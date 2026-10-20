<?php
declare(strict_types=1);
session_start();
$cfg = require __DIR__ . '/config.php';

function jsonOut(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizePe(string $raw): ?string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) === 11 && str_starts_with($d, '51')) {
        $d = substr($d, 2);
    } elseif (strlen($d) === 13 && str_starts_with($d, '0051')) {
        $d = substr($d, 4);
    }
    return preg_match('/^9\d{8}$/', $d) ? $d : null;
}

function looksFake(string $n): bool
{
    if (count(array_unique(str_split($n))) <= 2) {
        return true;
    }
    $up = $down = 0;
    for ($i = 1; $i < 9; $i++) {
        $diff = ((int)$n[$i] - (int)$n[$i - 1] + 10) % 10;
        if ($diff === 1) $up++;
        if ($diff === 9) $down++;
    }
    if ($up >= 7 || $down >= 7) {
        return true;
    }
    return (bool)preg_match('/(\d)\1{5,}/', $n);
}

function normalizeOperator(?string $name): ?string
{
    if (!$name) return null;
    $l = strtolower($name);
    return match (true) {
        str_contains($l, 'movistar'), str_contains($l, 'telefonica'), str_contains($l, 'telefónica') => 'Movistar',
        str_contains($l, 'claro'), str_contains($l, 'america movil'), str_contains($l, 'américa móvil') => 'Claro',
        str_contains($l, 'entel') => 'Entel',
        str_contains($l, 'bitel'), str_contains($l, 'viettel') => 'Bitel',
        default => $name,
    };
}


function db(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    try {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        $pdo = new PDO('sqlite:' . $dir . '/cache.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS lookups (
            number TEXT, provider TEXT, result TEXT, ts INTEGER, PRIMARY KEY (number, provider))');
    } catch (Throwable) {
        $pdo = null;
    }
    return $pdo;
}

function cacheGet(string $n, string $provider, int $days): ?array
{
    $pdo = db();
    if (!$pdo) return null;
    $st = $pdo->prepare('SELECT result, ts FROM lookups WHERE number = ? AND provider = ?');
    $st->execute([$n, $provider]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['ts'] < time() - $days * 86400) return null;
    return json_decode($row['result'], true) ?: null;
}

function cacheSet(string $n, string $provider, array $result): void
{
    $pdo = db();
    if (!$pdo) return;
    $st = $pdo->prepare('REPLACE INTO lookups (number, provider, result, ts) VALUES (?, ?, ?, ?)');
    $st->execute([$n, $provider, json_encode($result, JSON_UNESCAPED_UNICODE), time()]);
}

function lookupOffline(string $n): array
{
    return [
        'status' => 'format_ok',
        'operator' => null,
        'line_type' => null,
        'message' => 'Formato correcto. Sin proveedor no se puede confirmar si existe.',
    ];
}

function lookupTwilio(string $n, array $cfg): array
{
    if ($cfg['twilio_sid'] === '' || $cfg['twilio_token'] === '') {
        return ['status' => 'error', 'message' => 'Faltan las credenciales de Twilio en config.php'];
    }
    $url = 'https://lookups.twilio.com/v2/PhoneNumbers/%2B51' . $n . '?Fields=line_type_intelligence';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $cfg['twilio_sid'] . ':' . $cfg['twilio_token'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['status' => 'error', 'message' => 'No se pudo conectar: ' . $err];
    }
    $j = json_decode($body, true) ?: [];

    if ($code === 404) {
        return ['status' => 'invalid', 'operator' => null, 'line_type' => null, 'message' => 'El proveedor no reconoce el número.'];
    }
    if ($code >= 400) {
        return ['status' => 'error', 'message' => $j['message'] ?? ('Error HTTP ' . $code)];
    }
    if (empty($j['valid'])) {
        return ['status' => 'invalid', 'operator' => null, 'line_type' => null, 'message' => 'Número no válido según el proveedor.'];
    }
    $lti = $j['line_type_intelligence'] ?? null;
    $operator = normalizeOperator($lti['carrier_name'] ?? null);
    return [
        'status' => 'ok',
        'operator' => $operator,
        'line_type' => $lti['type'] ?? null,
        'message' => $operator ? '' : 'Número válido, operador no disponible.',
    ];
}

function lookupNumber(string $n, array $cfg): array
{
    if (looksFake($n)) {
        return ['number' => $n, 'status' => 'suspicious', 'operator' => null, 'line_type' => null,
                'message' => 'Patrón típico de número inventado.', 'cached' => false];
    }
    $provider = $cfg['provider'];
    $cached = cacheGet($n, $provider, (int)$cfg['cache_days']);
    if ($cached) {
        return ['number' => $n, 'cached' => true] + $cached;
    }
    $res = $provider === 'twilio' ? lookupTwilio($n, $cfg) : lookupOffline($n);
    if ($res['status'] !== 'error') {
        cacheSet($n, $provider, $res);
    }
    return ['number' => $n, 'cached' => false] + $res;
}


function readXlsxCells(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Falta la extensión zip de PHP para leer .xlsx. Sube un CSV o pega la lista.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No se pudo abrir el archivo Excel.');
    }
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml);
        foreach ($sx->si ?? [] as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } else {
                foreach ($si->r ?? [] as $r) $text .= (string)$r->t;
            }
            $shared[] = $text;
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('No se encontró la primera hoja del Excel.');
    }
    $cells = [];
    $sheet = simplexml_load_string($sheetXml);
    foreach ($sheet->sheetData->row ?? [] as $row) {
        foreach ($row->c as $c) {
            $t = (string)($c['t'] ?? '');
            if ($t === 's') {
                $cells[] = $shared[(int)$c->v] ?? '';
            } elseif ($t === 'inlineStr') {
                $cells[] = (string)$c->is->t;
            } else {
                $v = (string)$c->v;
                // Los teléfonos guardados como número pueden venir como 9.87654321E8
                $cells[] = (stripos($v, 'e') !== false && is_numeric($v)) ? sprintf('%.0f', (float)$v) : $v;
            }
        }
    }
    return $cells;
}

function extractTokens(string $text): array
{
    return preg_split('/[\r\n,;\t]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}


$action = $_GET['action'] ?? '';

if ($action === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

$loginError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals($cfg['app_password'], (string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        header('Location: index.php');
        exit;
    }
    $loginError = true;
}
$logged = !empty($_SESSION['ok']);


if ($action === 'lookup' || $action === 'parse') {
    if (!$logged) jsonOut(['error' => 'Sesión expirada. Recarga la página.'], 401);
    session_write_close(); // permite consultas en paralelo

    if ($action === 'lookup') {
        $n = normalizePe((string)($_GET['number'] ?? ''));
        if (!$n) jsonOut(['status' => 'bad_format', 'message' => 'Debe tener 9 dígitos y empezar con 9.']);
        jsonOut(lookupNumber($n, $cfg));
    }

    try {
        $tokens = [];
        if (!empty($_FILES['file']['tmp_name'])) {
            $name = strtolower($_FILES['file']['name']);
            if (str_ends_with($name, '.xlsx')) {
                $tokens = readXlsxCells($_FILES['file']['tmp_name']);
            } elseif (preg_match('/\.(csv|txt|tsv)$/', $name)) {
                $tokens = extractTokens((string)file_get_contents($_FILES['file']['tmp_name']));
            } else {
                jsonOut(['error' => 'Formato no admitido. Usa .xlsx, .csv o .txt'], 400);
            }
        } else {
            $tokens = extractTokens((string)($_POST['text'] ?? ''));
        }
        $items = [];
        $seen = [];
        foreach ($tokens as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '' || !preg_match('/\d/', $raw)) continue; // ignora encabezados y celdas de texto
            $n = normalizePe($raw);
            $key = $n ?? 'raw:' . $raw;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $items[] = ['raw' => $raw, 'number' => $n];
        }
        $truncated = count($items) > $cfg['max_numbers'];
        jsonOut(['items' => array_slice($items, 0, (int)$cfg['max_numbers']), 'truncated' => $truncated,
                 'max' => (int)$cfg['max_numbers']]);
    } catch (Throwable $e) {
        jsonOut(['error' => $e->getMessage()], 400);
    }
}

$providerLabel = $cfg['provider'] === 'twilio' ? 'Twilio Lookup' : 'Solo formato (sin proveedor)';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Validador de teléfonos de Perú</title>
<style>
:root {
  --bg: #eef1f4; --surface: #ffffff; --ink: #16202a; --muted: #5d6b79; --line: #d5dce3;
  --accent: #1f5fbf; --accent-ink: #ffffff;
  --ok: #17784b; --ok-bg: #e0f3ea; --warn: #8a5a00; --warn-bg: #fbefd2;
  --bad: #a8321f; --bad-bg: #fbe3df; --neutral-bg: #e6eaee;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #11171d; --surface: #1a232c; --ink: #e8edf2; --muted: #96a4b2; --line: #2e3a46;
    --accent: #6aa2f5; --accent-ink: #0d1a2b;
    --ok: #62d19a; --ok-bg: #173529; --warn: #f0c36a; --warn-bg: #3a2f14;
    --bad: #f19483; --bad-bg: #3d1f1a; --neutral-bg: #26313b;
  }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--ink);
  font: 16px/1.5 "Segoe UI", system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif; }
main { max-width: 920px; margin: 0 auto; padding: 32px 20px 64px; }
header { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; }
h1 { font-size: 1.6rem; margin: 0; letter-spacing: -0.01em; }
.sub { color: var(--muted); font-size: .9rem; }
.panel { background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 20px; margin-bottom: 20px; }
.tabs { display: flex; gap: 4px; margin-bottom: 16px; border-bottom: 1px solid var(--line); }
.tab { background: none; border: 0; padding: 10px 14px; font: inherit; color: var(--muted); cursor: pointer;
  border-bottom: 3px solid transparent; margin-bottom: -1px; }
.tab[aria-selected="true"] { color: var(--ink); border-bottom-color: var(--accent); font-weight: 600; }
label { display: block; font-weight: 600; margin-bottom: 6px; }
input[type=text], input[type=password], textarea { width: 100%; padding: 10px 12px; font: inherit; color: var(--ink);
  background: var(--bg); border: 1px solid var(--line); border-radius: 6px; }
textarea { min-height: 120px; resize: vertical; font-family: ui-monospace, Consolas, monospace; font-size: .9rem; }
input[type=file] { font: inherit; max-width: 100%; }
.row { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; }
.row > .grow { flex: 1 1 240px; }
button.btn { background: var(--accent); color: var(--accent-ink); border: 0; border-radius: 6px; padding: 10px 18px;
  font: inherit; font-weight: 600; cursor: pointer; }
button.btn.secondary { background: var(--neutral-bg); color: var(--ink); }
button.btn:disabled { opacity: .5; cursor: not-allowed; }
:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
.hint { color: var(--muted); font-size: .85rem; margin: 8px 0 0; }
.divider { margin: 16px 0; color: var(--muted); font-size: .85rem; }
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .82rem; font-weight: 600; white-space: nowrap; }
.pill.ok { background: var(--ok-bg); color: var(--ok); }
.pill.warn { background: var(--warn-bg); color: var(--warn); }
.pill.bad { background: var(--bad-bg); color: var(--bad); }
.pill.neutral { background: var(--neutral-bg); color: var(--muted); }
.result { margin-top: 16px; padding: 14px 16px; border: 1px solid var(--line); border-radius: 8px; display: none; }
.result .num { font-size: 1.3rem; font-weight: 700; font-variant-numeric: tabular-nums; }
progress { width: 100%; height: 10px; margin: 14px 0 4px; accent-color: var(--accent); }
.summary { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
.tablewrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: .92rem; }
th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); }
th { color: var(--muted); font-weight: 600; font-size: .85rem; }
td.mono { font-variant-numeric: tabular-nums; font-family: ui-monospace, Consolas, monospace; }
.notice { background: var(--warn-bg); color: var(--warn); border-radius: 8px; padding: 10px 14px; font-size: .88rem; margin-bottom: 20px; }
.error { color: var(--bad); margin: 10px 0 0; }
a { color: var(--accent); }
.login { max-width: 360px; margin: 14vh auto 0; }
</style>
</head>
<body>
<main>
<?php if (!$logged): ?>
  <div class="panel login">
    <h1>Validador de teléfonos</h1>
    <p class="sub">Ingresa la clave de acceso.</p>
    <form method="post">
      <label for="password">Clave</label>
      <input type="password" id="password" name="password" autofocus required>
      <?php if ($loginError): ?><p class="error">Clave incorrecta.</p><?php endif; ?>
      <p><button class="btn" type="submit">Entrar</button></p>
    </form>
  </div>
<?php else: ?>
  <header>
    <div>
      <h1>Validador de teléfonos de Perú</h1>
      <div class="sub">Proveedor activo: <strong><?= htmlspecialchars($providerLabel) ?></strong></div>
    </div>
    <a href="?action=logout" class="sub">Cerrar sesión</a>
  </header>

  <?php if ($cfg['provider'] !== 'twilio'): ?>
  <div class="notice">
    Estás en modo sin proveedor: la app valida el formato y descarta números inventados (999 999 999, 912 345 678),
    pero no puede confirmar que la línea exista ni decir el operador. Para eso configura un proveedor en <code>config.php</code>.
  </div>
  <?php endif; ?>

  <section class="panel">
    <div class="tabs" role="tablist">
      <button class="tab" role="tab" id="tab-one" aria-selected="true" aria-controls="pane-one">Un número</button>
      <button class="tab" role="tab" id="tab-list" aria-selected="false" aria-controls="pane-list">Lista o archivo</button>
    </div>

    <div id="pane-one" role="tabpanel" aria-labelledby="tab-one">
      <div class="row">
        <div class="grow">
          <label for="single">Número de celular</label>
          <input type="text" id="single" inputmode="tel" placeholder="987 654 321 o +51 987 654 321" autocomplete="off">
        </div>
        <button class="btn" id="btn-single">Consultar</button>
      </div>
      <div class="result" id="single-result" aria-live="polite"></div>
    </div>

    <div id="pane-list" role="tabpanel" aria-labelledby="tab-list" hidden>
      <label for="file">Sube un archivo (.xlsx, .csv o .txt)</label>
      <input type="file" id="file" accept=".xlsx,.csv,.txt,.tsv">
      <p class="hint">Se leen todas las celdas de la primera hoja. Los encabezados se ignoran.</p>
      <div class="divider">o pega una lista (un número por línea, o separados por comas)</div>
      <textarea id="paste" placeholder="987654321&#10;+51 912 345 670&#10;999999999"></textarea>
      <p><button class="btn" id="btn-list">Validar lista</button></p>
      <p class="error" id="list-error" role="alert" hidden></p>

      <div id="progress-box" hidden>
        <progress id="progress" value="0" max="1"></progress>
        <div class="sub" id="progress-text"></div>
      </div>
      <div id="results" hidden>
        <div class="summary" id="summary"></div>
        <div class="row" style="margin-bottom:10px">
          <div class="grow">
            <label for="filter" class="sub" style="font-weight:400">Mostrar</label>
            <select id="filter" style="padding:8px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font:inherit">
              <option value="all">Todos</option>
              <option value="ok">Existen / válidos</option>
              <option value="no">No existen o falsos</option>
              <option value="error">Con error</option>
            </select>
          </div>
          <button class="btn secondary" id="btn-csv">Descargar CSV</button>
        </div>
        <div class="tablewrap">
          <table>
            <thead><tr><th>Número</th><th>Resultado</th><th>Operador</th><th>Detalle</th></tr></thead>
            <tbody id="tbody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

<script>
const STATUS = {
  ok:         { label: 'Existe',            cls: 'ok',      group: 'ok' },
  format_ok:  { label: 'Formato correcto',  cls: 'neutral', group: 'ok' },
  invalid:    { label: 'No existe',         cls: 'bad',     group: 'no' },
  suspicious: { label: 'Número falso',      cls: 'warn',    group: 'no' },
  bad_format: { label: 'Formato inválido',  cls: 'bad',     group: 'no' },
  error:      { label: 'Error',             cls: 'warn',    group: 'error' },
};
const $ = (id) => document.getElementById(id);
let rows = [];

function pill(status) {
  const s = STATUS[status] || STATUS.error;
  const el = document.createElement('span');
  el.className = 'pill ' + s.cls;
  el.textContent = s.label;
  return el;
}
const fmt = (n) => n ? n.replace(/(\d{3})(\d{3})(\d{3})/, '$1 $2 $3') : '';

for (const id of ['one', 'list']) {
  $('tab-' + id).addEventListener('click', () => {
    for (const k of ['one', 'list']) {
      $('tab-' + k).setAttribute('aria-selected', String(k === id));
      $('pane-' + k).hidden = k !== id;
    }
  });
}

async function lookup(number) {
  try {
    const r = await fetch('?action=lookup&number=' + encodeURIComponent(number));
    const j = await r.json();
    if (!r.ok) return { status: 'error', message: j.error || 'Error del servidor' };
    return j;
  } catch (e) {
    return { status: 'error', message: 'Sin conexión con el servidor' };
  }
}

$('btn-single').addEventListener('click', async () => {
  const v = $('single').value.trim();
  const box = $('single-result');
  if (!v) return;
  $('btn-single').disabled = true;
  const res = await lookup(v);
  $('btn-single').disabled = false;
  box.style.display = 'block';
  box.replaceChildren();
  const num = document.createElement('div');
  num.className = 'num';
  num.textContent = res.number ? '+51 ' + fmt(res.number) : v;
  const line = document.createElement('div');
  line.style.marginTop = '8px';
  line.append(pill(res.status));
  if (res.operator) {
    const op = document.createElement('span');
    op.style.marginLeft = '10px';
    op.textContent = 'Operador: ' + res.operator + (res.line_type ? ' (' + res.line_type + ')' : '');
    line.append(op);
  }
  box.append(num, line);
  if (res.message) {
    const m = document.createElement('div');
    m.className = 'hint';
    m.textContent = res.message;
    box.append(m);
  }
});
$('single').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('btn-single').click(); });

function showError(msg) {
  const el = $('list-error');
  el.hidden = !msg;
  el.textContent = msg || '';
}

function renderTable() {
  const f = $('filter').value;
  const tb = $('tbody');
  tb.replaceChildren();
  for (const r of rows) {
    const s = STATUS[r.status] || STATUS.error;
    if (f !== 'all' && s.group !== f) continue;
    const tr = document.createElement('tr');
    const c1 = document.createElement('td'); c1.className = 'mono'; c1.textContent = r.number ? fmt(r.number) : r.raw;
    const c2 = document.createElement('td'); c2.append(pill(r.status));
    const c3 = document.createElement('td'); c3.textContent = r.operator || '';
    const c4 = document.createElement('td'); c4.textContent = r.message || '';
    tr.append(c1, c2, c3, c4);
    tb.append(tr);
  }
}

function renderSummary() {
  const counts = {};
  for (const r of rows) counts[r.status] = (counts[r.status] || 0) + 1;
  const box = $('summary');
  box.replaceChildren();
  for (const [st, n] of Object.entries(counts)) {
    const p = pill(st);
    p.textContent = (STATUS[st] || STATUS.error).label + ': ' + n;
    box.append(p);
  }
}

$('btn-list').addEventListener('click', async () => {
  showError('');
  const fd = new FormData();
  const file = $('file').files[0];
  if (file) fd.append('file', file); else fd.append('text', $('paste').value);
  if (!file && !$('paste').value.trim()) { showError('Sube un archivo o pega una lista de números.'); return; }

  $('btn-list').disabled = true;
  $('results').hidden = true;
  let parsed;
  try {
    const r = await fetch('?action=parse', { method: 'POST', body: fd });
    parsed = await r.json();
    if (!r.ok) throw new Error(parsed.error || 'No se pudo leer la lista');
  } catch (e) {
    showError(e.message);
    $('btn-list').disabled = false;
    return;
  }
  if (!parsed.items.length) { showError('No se encontraron números en la lista.'); $('btn-list').disabled = false; return; }
  if (parsed.truncated) showError('La lista supera el máximo de ' + parsed.max + ' números. Se validarán los primeros ' + parsed.max + '.');

  rows = parsed.items.map((it) => it.number
    ? { raw: it.raw, number: it.number, status: null }
    : { raw: it.raw, number: null, status: 'bad_format', message: 'Debe tener 9 dígitos y empezar con 9.' });

  const pending = rows.filter((r) => r.status === null);
  let done = 0;
  $('progress-box').hidden = false;
  $('progress').max = pending.length || 1;
  $('progress').value = 0;
  const updateProgress = () => {
    $('progress').value = done;
    $('progress-text').textContent = done + ' de ' + pending.length + ' consultados';
  };
  updateProgress();

  let idx = 0;
  const worker = async () => {
    while (idx < pending.length) {
      const row = pending[idx++];
      Object.assign(row, await lookup(row.number));
      done++;
      updateProgress();
    }
  };
  await Promise.all(Array.from({ length: Math.min(4, pending.length) }, worker));

  $('progress-box').hidden = true;
  $('results').hidden = false;
  renderSummary();
  renderTable();
  $('btn-list').disabled = false;
});

$('filter').addEventListener('change', renderTable);

$('btn-csv').addEventListener('click', () => {
  const esc = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"';
  const lines = [['numero', 'resultado', 'operador', 'tipo_linea', 'detalle'].join(',')];
  for (const r of rows) {
    const s = STATUS[r.status] || STATUS.error;
    lines.push([r.number || r.raw, s.label, r.operator, r.line_type, r.message].map(esc).join(','));
  }
  const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'resultados-telefonos.csv';
  a.click();
  URL.revokeObjectURL(a.href);
});
</script>
<?php endif; ?>
</main>
</body>
</html>
