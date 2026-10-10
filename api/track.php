<?php
/**
 * Returtax — ce fac vizitatorii pe site (panoul de admin → Vizitatori).
 *
 * Fără cookie-uri: vizita e legată de id-ul temporar din browser (același cu al conversației),
 * care dispare la închiderea filei. Nu se păstrează adresa IP, doar amprenta ei.
 * Primește loturi mici de evenimente trimise de js/site.js; un lot gol doar marchează vizita ca activă.
 */

require __DIR__ . '/_lib.php';

const MAX_EVENTS_PER_VISIT = 600;
const MAX_EVENTS_PER_IP_DAY = 5000;
const KEEP_MONTHS = 12;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rt_json(405, ['ok' => false]);
}
rt_require_same_origin();

// Roboții și vizitele proprii (browserul în care v-ați conectat la admin) nu se numără
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|monitor|curl|wget|python|facebookexternalhit/i', $ua)
    || !empty($_COOKIE['rt_owner'])) {
    rt_json(200, ['ok' => true]);
}

$in = json_decode(file_get_contents('php://input', false, null, 0, 20000) ?: '', true);
$visit = is_array($in) ? rt_conversation_id($in['visit'] ?? null) : null;
if (!$visit) {
    rt_json(422, ['ok' => false]);
}
$clean = fn($v, int $max) => mb_substr(trim(preg_replace('/\p{C}+/u', ' ', is_scalar($v) ? (string)$v : '') ?? ''), 0, $max);
$tag = fn($v) => preg_match('/^[a-z0-9_.-]{1,40}$/', $t = strtolower($clean($v, 40))) ? $t : '';

// De unde a venit vizitatorul: eticheta din link (utm_source), altfel site-ul de pe care a dat clic
$ctx = is_array($in['ctx'] ?? null) ? $in['ctx'] : [];
$refHost = strtolower($clean($ctx['ref'] ?? '', 80));
$source = $tag($ctx['src'] ?? '');
if ($source === '') {
    $known = ['facebook' => 'facebook', 'fb.' => 'facebook', 'instagram' => 'instagram', 'google' => 'google', 'bing' => 'bing',
              'tiktok' => 'tiktok', 'youtube' => 'youtube', 'whatsapp' => 'whatsapp', 't.co' => 'twitter'];
    $source = $refHost === '' ? 'direct' : $refHost;
    foreach ($known as $needle => $name) {
        if (str_contains($refHost, $needle)) {
            $source = $name;
            break;
        }
    }
}
$width = (int)($ctx['w'] ?? 0);
$device = $width <= 0 ? '' : ($width < 768 ? 'telefon' : ($width < 1100 ? 'tabletă' : 'calculator'));

try {
    $db = rt_db();
    $now = time();
    $db->prepare('INSERT INTO visits (id, started_at, last_at, ip_hash, user_agent, device, source, medium, campaign, referrer, landing)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ON CONFLICT(id) DO UPDATE SET last_at = excluded.last_at')
       ->execute([$visit, rt_now(), rt_now(), rt_ip_hash(), mb_substr($ua, 0, 200), $device, $source,
                  $tag($ctx['med'] ?? ''), $tag($ctx['camp'] ?? ''), $refHost, $clean($ctx['land'] ?? '', 120)]);

    $events = array_slice(is_array($in['events'] ?? null) ? $in['events'] : [], 0, 40);
    if ($events) {
        $count = $db->prepare('SELECT events FROM visits WHERE id = ?');
        $count->execute([$visit]);
        $room = MAX_EVENTS_PER_VISIT - (int)$count->fetchColumn();
        $day = $db->prepare('SELECT COALESCE(SUM(events), 0) FROM visits WHERE ip_hash = ? AND started_at >= ?');
        $day->execute([rt_ip_hash(), date('Y-m-d')]);
        $room = min($room, MAX_EVENTS_PER_IP_DAY - (int)$day->fetchColumn());

        $insert = $db->prepare('INSERT INTO events (visit_id, created_at, type, detail, path) VALUES (?, ?, ?, ?, ?)');
        $saved = 0;
        foreach ($events as $ev) {
            if ($saved >= $room || !is_array($ev) || !preg_match('/^[a-z_]{2,24}$/', (string)($ev['t'] ?? ''))) {
                continue;
            }
            // „ago”: cu câte secunde înainte de trimitere s-a întâmplat (loturile pleacă la câteva secunde)
            $ago = max(0, min(600, (int)($ev['ago'] ?? 0)));
            $insert->execute([$visit, date('Y-m-d H:i:s', $now - $ago), $ev['t'], $clean($ev['d'] ?? '', 300), $clean($ev['p'] ?? '', 120)]);
            $saved++;
        }
        if ($saved) {
            $db->prepare('UPDATE visits SET events = events + ? WHERE id = ?')->execute([$saved, $visit]);
        }
    }

    // Din când în când, ștergem statisticile mai vechi de un an
    if (mt_rand(1, 300) === 1) {
        $old = date('Y-m-d', strtotime('-' . KEEP_MONTHS . ' months'));
        $db->prepare('DELETE FROM events WHERE created_at < ?')->execute([$old]);
        $db->prepare('DELETE FROM visits WHERE last_at < ?')->execute([$old]);
    }
} catch (Throwable $e) {
    error_log('[returtax/track] ' . $e->getMessage());
    rt_json(500, ['ok' => false]);
}

rt_json(200, ['ok' => true]);
