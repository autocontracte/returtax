<?php
/**
 * Returtax — panoul de admin.
 *
 * Conversațiile cu Marcel (mesaj cu mesaj, cu costul AI), cererile din formulare,
 * calculator, chat și WhatsApp, plus costurile AI pe zile.
 *
 * Contul de admin se creează pe server, cu:
 *   php /home/returtax/bin/creeaza-admin.php
 * (parola se salvează doar ca hash, în /home/returtax/secrets/admin.json)
 */

require __DIR__ . '/../api/_lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; frame-ancestors 'none'");

const SESSION_HOURS = 8;
const STATUSES = ['nou' => 'Nou', 'contactat' => 'Contactat', 'client' => 'Client', 'inchis' => 'Închis'];
const SOURCES = ['formular' => 'Formular', 'calculator' => 'Calculator', 'chat' => 'Chat', 'whatsapp' => 'WhatsApp'];

session_name('RTADMIN');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function usd($v): string {
    return '$' . number_format((float)$v, (float)$v < 1 ? 4 : 2);
}
function ron($v): string {
    return number_format((float)$v * USD_TO_RON, 2, ',', '.') . ' lei';
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sesiune expirată. Reîncărcați pagina.');
    }
}
function go(string $query = ''): void {
    header('Location: /admin/' . ($query ? '?' . $query : ''));
    exit;
}

/* ---------- Autentificare ---------- */
$account = json_decode((string)@file_get_contents(RT_SECRETS . '/admin.json'), true);

// Prea multe încercări greșite de pe același IP: blocat 15 minute
function login_attempts(bool $add = false): int {
    $file = RT_HOME . '/ratelimit/login.json';
    @mkdir(dirname($file), 0700, true);
    $data = json_decode((string)@file_get_contents($file), true) ?: [];
    $ip = rt_ip_hash();
    $recent = array_values(array_filter($data[$ip] ?? [], fn($t) => $t > time() - 900));
    if ($add) {
        $recent[] = time();
        $data[$ip] = $recent;
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
    return count($recent);
}

$error = '';
if (($_POST['action'] ?? '') === 'login') {
    if (login_attempts() >= 5) {
        $error = 'Prea multe încercări. Așteptați 15 minute.';
    } elseif ($account && hash_equals((string)$account['user'], (string)($_POST['user'] ?? ''))
              && password_verify((string)($_POST['pass'] ?? ''), (string)$account['hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = $account['user'];
        $_SESSION['since'] = time();
        go();
    } else {
        login_attempts(true);
        $error = 'Utilizator sau parolă greșite.';
    }
}
if (($_POST['action'] ?? '') === 'logout') {
    check_csrf();
    session_destroy();
    go();
}
$logged = !empty($_SESSION['admin']) && (time() - ($_SESSION['since'] ?? 0)) < SESSION_HOURS * 3600;

/* ---------- Acțiuni ---------- */
if ($logged && ($_POST['action'] ?? '') === 'lead') {
    check_csrf();
    $status = array_key_exists($_POST['status'] ?? '', STATUSES) ? $_POST['status'] : 'nou';
    rt_db()->prepare('UPDATE leads SET status = ?, note = ? WHERE id = ?')
        ->execute([$status, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 2000), (int)($_POST['id'] ?? 0)]);
    go('v=cereri' . (!empty($_POST['back']) ? '&status=' . urlencode($_POST['back']) : ''));
}

$view = $logged ? ($_GET['v'] ?? 'acasa') : 'login';
$db = $logged ? rt_db() : null;

/* ---------- Pagina ---------- */
?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin · Returtax</title>
<link rel="icon" type="image/png" href="/assets/icon.png">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root { --ink:#0f172a; --muted:#5b6475; --line:#e3e8ef; --soft:#f4f6f9; --blue:#0b5cad; --sky:#eef4fb; --ok:#15803d; --warn:#b45309; }
  * { box-sizing: border-box; }
  body { margin:0; font:15px/1.55 Montserrat, system-ui, sans-serif; color:var(--ink); background:var(--soft); }
  a { color:var(--blue); }
  header { position:sticky; top:0; z-index:5; background:#fff; border-bottom:1px solid var(--line); }
  .bar { max-width:1180px; margin:0 auto; padding:10px 16px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
  .bar img { width:92px; }
  nav { display:flex; gap:4px; flex-wrap:wrap; margin-right:auto; }
  nav a { padding:8px 12px; border-radius:999px; text-decoration:none; color:var(--muted); font-weight:600; font-size:.88rem; }
  nav a.on { background:var(--ink); color:#fff; }
  main { max-width:1180px; margin:0 auto; padding:20px 16px 60px; }
  h1 { font:400 2.2rem/1 "Bebas Neue", sans-serif; letter-spacing:.5px; margin:6px 0 18px; }
  h2 { font-size:1.05rem; margin:28px 0 10px; }
  .cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:18px; padding:16px; }
  .card .k { font-size:.75rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
  .card .v { font:400 2.2rem/1.1 "Bebas Neue", sans-serif; margin-top:4px; }
  .card .s { font-size:.8rem; color:var(--muted); }
  .live { color:var(--ok); }
  .table { background:#fff; border:1px solid var(--line); border-radius:18px; overflow:auto; }
  table { width:100%; border-collapse:collapse; font-size:.88rem; }
  th, td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--line); vertical-align:top; }
  th { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); background:#fafbfd; white-space:nowrap; }
  tr:last-child td { border-bottom:0; }
  .tag { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.72rem; font-weight:700; background:var(--sky); color:var(--blue); white-space:nowrap; }
  .tag.nou { background:#fff3d6; color:var(--warn); } .tag.client { background:#dcfce7; color:var(--ok); }
  .tag.inchis { background:#eef0f3; color:var(--muted); } .tag.on { background:#dcfce7; color:var(--ok); }
  .muted { color:var(--muted); } .nowrap { white-space:nowrap; }
  .filters { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
  .filters a { padding:6px 12px; border:1px solid var(--line); border-radius:999px; background:#fff; text-decoration:none; color:var(--ink); font-size:.82rem; font-weight:600; }
  .filters a.on { background:var(--ink); color:#fff; border-color:var(--ink); }
  .thread { display:flex; flex-direction:column; gap:10px; max-width:760px; }
  .m { padding:10px 14px; border-radius:16px; max-width:85%; white-space:pre-wrap; }
  .m.user { align-self:flex-end; background:var(--ink); color:#fff; border-bottom-right-radius:5px; }
  .m.assistant { align-self:flex-start; background:#fff; border:1px solid var(--line); border-bottom-left-radius:5px; }
  .meta { font-size:.72rem; color:var(--muted); margin-top:4px; }
  .m.user .meta { color:#c3cad6; }
  .est { margin-top:8px; padding:8px 10px; border-radius:10px; background:var(--sky); color:var(--ink); font-size:.8rem; }
  form.inline { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
  select, input[type=text], input[type=password], textarea { font:inherit; padding:8px 10px; border:1px solid #cfd6e0; border-radius:10px; background:#fff; }
  button { font:600 .85rem Montserrat, sans-serif; padding:8px 14px; border:0; border-radius:999px; background:var(--ink); color:#fff; cursor:pointer; }
  button.ghost { background:#fff; color:var(--ink); border:1px solid var(--line); }
  .login { max-width:380px; margin:12vh auto; background:#fff; border:1px solid var(--line); border-radius:22px; padding:28px; }
  .login img { width:110px; margin-bottom:10px; }
  .login label { display:block; font-weight:600; font-size:.85rem; margin:12px 0 4px; }
  .login input { width:100%; }
  .login button { width:100%; margin-top:18px; padding:12px; }
  .err { color:#b91c1c; font-weight:600; margin-top:10px; }
  .note { background:#fff; border:1px solid var(--line); border-radius:14px; padding:14px; }
</style>
</head>
<body>
<?php if ($view === 'login'): ?>
  <div class="login">
    <img src="/assets/logo.png" alt="Returtax">
    <h1>Panou admin</h1>
    <?php if (!$account): ?>
      <p class="muted">Contul de admin nu este creat încă. Rulați pe server:<br><code>php /home/returtax/bin/creeaza-admin.php</code></p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="login">
        <label for="u">Utilizator</label><input id="u" name="user" type="text" autocomplete="username" required>
        <label for="p">Parolă</label><input id="p" name="pass" type="password" autocomplete="current-password" required>
        <button type="submit">Intră</button>
        <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
<?php else:
    $nav = ['acasa' => 'Acasă', 'conversatii' => 'Conversații', 'cereri' => 'Cereri', 'costuri' => 'Costuri AI'];
    $active = $view === 'conversatie' ? 'conversatii' : $view;
?>
  <header><div class="bar">
    <a href="/admin/"><img src="/assets/logo.png" alt="Returtax"></a>
    <nav><?php foreach ($nav as $k => $label): ?><a href="/admin/?v=<?= $k ?>" class="<?= $active === $k ? 'on' : '' ?>"><?= $label ?></a><?php endforeach; ?></nav>
    <a href="/" class="muted" target="_blank" rel="noopener">Site ↗</a>
    <form method="post" class="inline"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="ghost">Ieșire</button></form>
  </div></header>
  <main>
<?php
/* ---------- Acasă ---------- */
if ($view === 'acasa'):
    $q = function (string $sql, array $p = []) use ($db) {
        $s = $db->prepare($sql);
        $s->execute($p);
        return $s->fetchColumn();
    };
    $today = date('Y-m-d');
    $month = date('Y-m');
    $live = $q("SELECT COUNT(*) FROM conversations WHERE last_at >= ?", [date('Y-m-d H:i:s', time() - 300)]);
    $convToday = $q("SELECT COUNT(*) FROM conversations WHERE started_at >= ?", [$today]);
    $conv7 = $q("SELECT COUNT(*) FROM conversations WHERE started_at >= ?", [date('Y-m-d', strtotime('-6 days'))]);
    $aiToday = $q("SELECT COUNT(*) FROM messages WHERE role='assistant' AND source='ai' AND created_at >= ?", [$today]);
    $leadsNew = $q("SELECT COUNT(*) FROM leads WHERE status='nou'");
    $leadsToday = $q("SELECT COUNT(*) FROM leads WHERE created_at >= ?", [$today]);
    $costToday = $q("SELECT COALESCE(SUM(cost_usd),0) FROM messages WHERE created_at >= ?", [$today]);
    $costMonth = $q("SELECT COALESCE(SUM(cost_usd),0) FROM messages WHERE created_at >= ?", [$month . '-01']);
    $costAll = $q("SELECT COALESCE(SUM(cost_usd),0) FROM messages");
    $aiAll = $q("SELECT COUNT(*) FROM messages WHERE role='assistant' AND source='ai'");
?>
    <h1>Acasă</h1>
    <div class="cards">
      <div class="card"><div class="k">Acum pe chat</div><div class="v <?= $live ? 'live' : '' ?>"><?= (int)$live ?></div><div class="s">activi în ultimele 5 minute</div></div>
      <div class="card"><div class="k">Conversații azi</div><div class="v"><?= (int)$convToday ?></div><div class="s"><?= (int)$conv7 ?> în ultimele 7 zile</div></div>
      <div class="card"><div class="k">Cereri noi</div><div class="v"><?= (int)$leadsNew ?></div><div class="s"><?= (int)$leadsToday ?> primite azi</div></div>
      <div class="card"><div class="k">Cost AI azi</div><div class="v"><?= usd($costToday) ?></div><div class="s"><?= ron($costToday) ?> · <?= (int)$aiToday ?> răspunsuri</div></div>
      <div class="card"><div class="k">Cost AI luna asta</div><div class="v"><?= usd($costMonth) ?></div><div class="s"><?= ron($costMonth) ?></div></div>
      <div class="card"><div class="k">Cost / 1000 întrebări</div><div class="v"><?= $aiAll ? usd($costAll / $aiAll * 1000) : '—' ?></div><div class="s"><?= $aiAll ? ron($costAll / $aiAll * 1000) : 'încă fără date' ?></div></div>
    </div>

    <h2>Ultimele cereri</h2>
    <?php $rows = $db->query("SELECT * FROM leads ORDER BY id DESC LIMIT 8")->fetchAll(); ?>
    <div class="table"><table>
      <tr><th>Când</th><th>Sursa</th><th>Nume</th><th>Telefon</th><th>Status</th></tr>
      <?php foreach ($rows as $r): ?>
        <tr><td class="nowrap"><?= e(substr($r['created_at'], 0, 16)) ?></td><td><span class="tag"><?= e(SOURCES[$r['source']] ?? $r['source']) ?></span></td>
            <td><?= e($r['name'] ?: '—') ?></td><td class="nowrap"><?= $r['phone'] ? '<a href="tel:' . e($r['phone']) . '">' . e($r['phone']) . '</a>' : '—' ?></td>
            <td><span class="tag <?= e($r['status']) ?>"><?= e(STATUSES[$r['status']] ?? $r['status']) ?></span></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">Nicio cerere încă.</td></tr><?php endif; ?>
    </table></div>
    <p><a href="/admin/?v=cereri">Toate cererile →</a></p>

    <h2>Ultimele conversații</h2>
    <?php $rows = $db->query("SELECT c.*, (SELECT content FROM messages m WHERE m.conversation_id=c.id AND m.role='user' ORDER BY m.id LIMIT 1) AS first
                              FROM conversations c ORDER BY last_at DESC LIMIT 8")->fetchAll(); ?>
    <div class="table"><table>
      <tr><th>Ultimul mesaj</th><th>Început</th><th>Mesaje</th><th>Cost</th></tr>
      <?php foreach ($rows as $r): $on = strtotime($r['last_at']) > time() - 300; ?>
        <tr><td class="nowrap"><?= e(substr($r['last_at'], 0, 16)) ?> <?= $on ? '<span class="tag on">acum</span>' : '' ?></td>
            <td><a href="/admin/?v=conversatie&id=<?= e($r['id']) ?>"><?= e(mb_strimwidth((string)$r['first'], 0, 90, '…') ?: '(fără mesaj)') ?></a></td>
            <td><?= (int)$r['messages'] ?></td><td class="nowrap"><?= usd($r['cost_usd']) ?></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="4" class="muted">Nicio conversație încă.</td></tr><?php endif; ?>
    </table></div>
    <p><a href="/admin/?v=conversatii">Toate conversațiile →</a></p>

<?php
/* ---------- Conversații ---------- */
elseif ($view === 'conversatii'):
    $page = max(1, (int)($_GET['p'] ?? 1));
    $per = 50;
    $s = $db->prepare("SELECT c.*,
            (SELECT content FROM messages m WHERE m.conversation_id=c.id AND m.role='user' ORDER BY m.id LIMIT 1) AS first,
            (SELECT name || ' · ' || COALESCE(phone,'') FROM leads l WHERE l.conversation_id=c.id ORDER BY l.id DESC LIMIT 1) AS lead
        FROM conversations c ORDER BY last_at DESC LIMIT ? OFFSET ?");
    $s->execute([$per + 1, ($page - 1) * $per]);
    $rows = $s->fetchAll();
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
?>
    <h1>Conversații cu Marcel</h1>
    <div class="table"><table>
      <tr><th>Ultimul mesaj</th><th>Primul mesaj al vizitatorului</th><th>Cerere</th><th>Mesaje</th><th>Cost</th></tr>
      <?php foreach ($rows as $r): $on = strtotime($r['last_at']) > time() - 300; ?>
        <tr><td class="nowrap"><?= e(substr($r['last_at'], 0, 16)) ?> <?= $on ? '<span class="tag on">acum</span>' : '' ?></td>
            <td><a href="/admin/?v=conversatie&id=<?= e($r['id']) ?>"><?= e(mb_strimwidth((string)$r['first'], 0, 110, '…') ?: '(fără mesaj)') ?></a></td>
            <td><?= $r['lead'] ? e($r['lead']) : '<span class="muted">—</span>' ?></td>
            <td><?= (int)$r['messages'] ?></td><td class="nowrap"><?= usd($r['cost_usd']) ?></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">Nicio conversație încă.</td></tr><?php endif; ?>
    </table></div>
    <p><?php if ($page > 1): ?><a href="/admin/?v=conversatii&p=<?= $page - 1 ?>">← Mai noi</a><?php endif; ?>
       <?php if ($more): ?> <a href="/admin/?v=conversatii&p=<?= $page + 1 ?>">Mai vechi →</a><?php endif; ?></p>

<?php
/* ---------- O conversație ---------- */
elseif ($view === 'conversatie'):
    $id = rt_conversation_id($_GET['id'] ?? '');
    $c = $db->prepare('SELECT * FROM conversations WHERE id = ?');
    $c->execute([$id]);
    $c = $c->fetch();
    if (!$c): ?><h1>Conversație negăsită</h1><?php else:
    $m = $db->prepare('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id');
    $m->execute([$id]);
    $leads = $db->prepare('SELECT * FROM leads WHERE conversation_id = ? ORDER BY id');
    $leads->execute([$id]);
    $leads = $leads->fetchAll();
    $labels = ['ai' => 'AI', 'script' => 'automat', 'buton' => 'buton'];
?>
    <p><a href="/admin/?v=conversatii">← Conversații</a></p>
    <h1>Conversație din <?= e(substr($c['started_at'], 0, 16)) ?></h1>
    <p class="muted"><?= (int)$c['messages'] ?> mesaje · cost AI <?= usd($c['cost_usd']) ?> (<?= ron($c['cost_usd']) ?>) · ultimul mesaj <?= e(substr($c['last_at'], 0, 16)) ?>
       <?= strtotime($c['last_at']) > time() - 300 ? '<span class="tag on">activ acum</span>' : '' ?><br>
       <span class="nowrap">Browser: <?= e(mb_strimwidth((string)$c['user_agent'], 0, 90, '…')) ?></span></p>
    <?php foreach ($leads as $l): ?>
      <div class="note" style="margin-bottom:14px">
        <strong>Cerere:</strong> <?= e($l['name'] ?: '—') ?> · <?= $l['phone'] ? '<a href="tel:' . e($l['phone']) . '">' . e($l['phone']) . '</a>' : '—' ?>
        · <span class="tag <?= e($l['status']) ?>"><?= e(STATUSES[$l['status']] ?? $l['status']) ?></span>
        <?php if ($l['message']): ?><div class="muted"><?= e($l['message']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="thread">
      <?php foreach ($m->fetchAll() as $row): ?>
        <div class="m <?= e($row['role']) ?>"><?= e($row['content']) ?>
          <?php if ($row['meta']): $est = json_decode($row['meta'], true); if ($est): ?>
            <div class="est">Estimare calculată: <strong><?= e($est['estimare_minima_eur'] ?? '?') ?> – <?= e($est['estimare_maxima_eur'] ?? '?') ?> €</strong>,
              comision <?= e($est['comision_eur'] ?? '?') ?> €<?php if (isset($est['ramane_minim_eur'])): ?>,
              rămân <?= e($est['ramane_minim_eur']) ?> – <?= e($est['ramane_maxim_eur']) ?> €<?php endif; ?>
              <?php if (!empty($est['date_folosite'])): $d = $est['date_folosite']; ?>
                <br><span class="muted">din: <?= e($d['castig_lunar'] ?? '?') ?> <?= e($d['moneda'] ?? '') ?>/lună, <?= e($d['luni_pe_an'] ?? '?') ?> luni/an, <?= e($d['ani'] ?? '?') ?> ani<?=
                  !empty($d['cazare_platita']) ? ', cazare' : '' ?><?= !empty($d['drumuri_acasa']) ? ', drumuri' : '' ?><?=
                  !empty($d['familie_in_romania']) ? ', familie RO' : '' ?><?= !empty($d['credit_in_romania']) ? ', credit RO' : '' ?></span>
              <?php endif; ?>
            </div>
          <?php endif; endif; ?>
          <div class="meta"><?= e(substr($row['created_at'], 11, 5)) ?> · <?= e($labels[$row['source']] ?? $row['source']) ?>
            <?= $row['cost_usd'] !== null ? ' · ' . (int)$row['input_tokens'] . '+' . (int)$row['cache_read'] . ' in / ' . (int)$row['output_tokens'] . ' out · ' . usd($row['cost_usd']) : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
<?php endif;

/* ---------- Cereri ---------- */
elseif ($view === 'cereri'):
    $status = array_key_exists($_GET['status'] ?? '', STATUSES) ? $_GET['status'] : '';
    $s = $db->prepare('SELECT * FROM leads' . ($status ? ' WHERE status = ?' : '') . ' ORDER BY id DESC LIMIT 300');
    $s->execute($status ? [$status] : []);
    $rows = $s->fetchAll();
?>
    <h1>Cereri</h1>
    <div class="filters">
      <a href="/admin/?v=cereri" class="<?= $status === '' ? 'on' : '' ?>">Toate</a>
      <?php foreach (STATUSES as $k => $label): ?><a href="/admin/?v=cereri&status=<?= $k ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= $label ?></a><?php endforeach; ?>
    </div>
    <div class="table"><table>
      <tr><th>Când</th><th>Sursa</th><th>Nume / telefon</th><th>Mesaj</th><th>Status și notițe</th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= e(substr($r['created_at'], 0, 16)) ?></td>
          <td><span class="tag"><?= e(SOURCES[$r['source']] ?? $r['source']) ?></span>
              <?php if ($r['conversation_id']): ?><br><a href="/admin/?v=conversatie&id=<?= e($r['conversation_id']) ?>" class="muted">vezi chat</a><?php endif; ?></td>
          <td><strong><?= e($r['name'] ?: '—') ?></strong><br><?= $r['phone'] ? '<a href="tel:' . e($r['phone']) . '">' . e($r['phone']) . '</a>' : '<span class="muted">fără telefon</span>' ?>
              <?= $r['email'] ? '<br><a href="mailto:' . e($r['email']) . '">' . e($r['email']) . '</a>' : '' ?></td>
          <td style="max-width:340px"><?= nl2br(e($r['message'] ?: '—')) ?></td>
          <td>
            <form method="post" class="inline">
              <input type="hidden" name="action" value="lead"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= e($status) ?>">
              <select name="status"><?php foreach (STATUSES as $k => $label): ?><option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
              <input type="text" name="note" value="<?= e($r['note']) ?>" placeholder="Notiță" style="min-width:160px">
              <button>Salvează</button>
            </form>
          </td>
        </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">Nicio cerere.</td></tr><?php endif; ?>
    </table></div>

<?php
/* ---------- Costuri AI ---------- */
elseif ($view === 'costuri'):
    $rows = $db->query("SELECT substr(created_at, 1, 10) AS zi,
            SUM(CASE WHEN role='assistant' AND source='ai' THEN 1 ELSE 0 END) AS raspunsuri,
            COALESCE(SUM(input_tokens),0) AS tin, COALESCE(SUM(output_tokens),0) AS tout,
            COALESCE(SUM(cache_read),0) AS cread, COALESCE(SUM(cache_write),0) AS cwrite,
            COALESCE(SUM(cost_usd),0) AS cost
        FROM messages GROUP BY zi ORDER BY zi DESC LIMIT 60")->fetchAll();
    $months = $db->query("SELECT substr(created_at, 1, 7) AS luna, COALESCE(SUM(cost_usd),0) AS cost,
            SUM(CASE WHEN role='assistant' AND source='ai' THEN 1 ELSE 0 END) AS raspunsuri
        FROM messages GROUP BY luna ORDER BY luna DESC LIMIT 12")->fetchAll();
?>
    <h1>Costuri AI</h1>
    <p class="muted">Prețuri pe milion de tokeni (intrare / ieșire / din cache):
       <?php foreach (PRICES as $model => [$in, $out, $read]): ?><br><?= e($model) ?>: $<?= $in ?> / $<?= $out ?> / $<?= $read ?><?php endforeach; ?>
       <br>Lei calculați la un curs aproximativ de <?= USD_TO_RON ?> lei/$. Factura oficială: console.anthropic.com.</p>
    <h2>Pe luni</h2>
    <div class="table"><table>
      <tr><th>Luna</th><th>Răspunsuri AI</th><th>Cost</th><th>În lei</th></tr>
      <?php foreach ($months as $r): ?><tr><td><?= e($r['luna']) ?></td><td><?= (int)$r['raspunsuri'] ?></td><td><?= usd($r['cost']) ?></td><td><?= ron($r['cost']) ?></td></tr><?php endforeach; ?>
      <?php if (!$months): ?><tr><td colspan="4" class="muted">Încă fără date.</td></tr><?php endif; ?>
    </table></div>
    <h2>Pe zile</h2>
    <div class="table"><table>
      <tr><th>Ziua</th><th>Răspunsuri AI</th><th>Tokeni intrare</th><th>Din cache</th><th>Tokeni ieșire</th><th>Cost</th><th>În lei</th></tr>
      <?php foreach ($rows as $r): ?>
        <tr><td class="nowrap"><?= e($r['zi']) ?></td><td><?= (int)$r['raspunsuri'] ?></td><td><?= number_format($r['tin'] + $r['cwrite']) ?></td>
            <td><?= number_format($r['cread']) ?></td><td><?= number_format($r['tout']) ?></td><td><?= usd($r['cost']) ?></td><td><?= ron($r['cost']) ?></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="muted">Încă fără date.</td></tr><?php endif; ?>
    </table></div>
<?php endif; ?>
  </main>
<?php endif; ?>
</body>
</html>
