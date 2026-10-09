<?php
/**
 * Returtax — Marcel cu AI.
 *
 * Primește conversația din chat și întoarce răspunsul lui Marcel (instrucțiunile și
 * instrumentul de estimare sunt în api/_marcel.php). Fiecare schimb de mesaje se salvează
 * în baza de date, cu tokenii și costul, pentru panoul de admin.
 *
 * Cheia API stă doar pe server: /home/returtax/secrets/anthropic.key
 * Pentru a compara modele: php /home/returtax/bin/test-model.php
 */

require __DIR__ . '/_marcel.php';

// Modelul lui Marcel (prețurile sunt în PRICES, în api/_lib.php).
// Sonnet 5.5: ~12–18 lei la 1000 de întrebări (măsurat cu bin/test-model.php); Haiku 5.5: ~1,2 lei, dar mai slab.
const MARCEL = ['model' => 'claude-sonnet-5-5', 'effort' => 'low', 'fallbacks' => true];

const MAX_HISTORY  = 24;     // câte mesaje din conversație trimitem (memoria lui Marcel)
const MAX_MSG_LEN  = 1200;   // caractere pe mesaj
const LIMIT_10MIN  = 20;     // mesaje pe vizitator în 10 minute
const LIMIT_DAY_IP = 80;     // mesaje pe vizitator pe zi
const LIMIT_DAY    = 3000;   // mesaje pe zi, pe tot site-ul (plafon de cost)

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rt_json(405, ['ok' => false, 'error' => 'method']);
}
rt_require_same_origin();

/* ---------- Conversația primită ---------- */
$input = json_decode(file_get_contents('php://input', false, null, 0, 96000) ?: '', true);
$conv = rt_conversation_id($input['conversation_id'] ?? null);
$raw = is_array($input['messages'] ?? null) ? $input['messages'] : [];

$messages = [];
foreach (array_slice($raw, -MAX_HISTORY) as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $text = trim(preg_replace('/\p{C}+/u', ' ', (string)($m['content'] ?? '')) ?? '');
    if ($text === '') {
        continue;
    }
    $text = mb_substr($text, 0, MAX_MSG_LEN);
    // Mesajele consecutive de la același rol se unesc (API-ul cere alternanță)
    $last = count($messages) - 1;
    if ($last >= 0 && $messages[$last]['role'] === $role) {
        $messages[$last]['content'] .= "\n" . $text;
    } else {
        $messages[] = ['role' => $role, 'content' => $text];
    }
}
// Conversația trebuie să înceapă și să se termine cu un mesaj al vizitatorului
while ($messages && $messages[0]['role'] !== 'user') {
    array_shift($messages);
}
if (!$messages || $messages[count($messages) - 1]['role'] !== 'user' || !$conv) {
    rt_json(422, ['ok' => false, 'error' => 'invalid']);
}
$userText = $messages[count($messages) - 1]['content'];
$source = ($input['source'] ?? '') === 'buton' ? 'buton' : 'ai';

/* ---------- Limite (pe vizitator și pe zi) ---------- */
function within_limits(): bool {
    $dir = RT_HOME . '/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return true; // dacă nu putem număra, nu blocăm oamenii
    }
    $now = time();
    $day = date('Y-m-d');
    $ip  = rt_ip_hash();
    $fh  = @fopen($dir . '/counters.json', 'c+');
    if (!$fh) {
        return true;
    }
    flock($fh, LOCK_EX);
    $data = json_decode(stream_get_contents($fh) ?: '', true) ?: [];
    if (($data['day'] ?? '') !== $day) {
        $data = ['day' => $day, 'total' => 0, 'ips' => []];
    }
    $hits = array_values(array_filter($data['ips'][$ip]['recent'] ?? [], fn($t) => $t > $now - 600));
    $today = $data['ips'][$ip]['today'] ?? 0;
    $ok = count($hits) < LIMIT_10MIN && $today < LIMIT_DAY_IP && $data['total'] < LIMIT_DAY;
    if ($ok) {
        $hits[] = $now;
        $data['ips'][$ip] = ['recent' => $hits, 'today' => $today + 1];
        $data['total']++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

if (!within_limits()) {
    rt_json(429, ['ok' => false, 'error' => 'limit']);
}

/* ---------- Răspunsul lui Marcel ---------- */
$key = trim((string)@file_get_contents(RT_SECRETS . '/anthropic.key'));
if ($key === '') {
    rt_json(503, ['ok' => false, 'error' => 'unavailable']);
}

$r = rt_marcel_reply($key, $messages, MARCEL);
if (!$r) {
    rt_json(502, ['ok' => false, 'error' => 'upstream']);
}

/* ---------- Salvăm schimbul pentru panoul de admin ---------- */
try {
    rt_log_message($conv, 'user', $source, $userText);
    rt_log_message($conv, 'assistant', 'ai', $r['reply'], $r['usage'],
        $r['estimate'] ? json_encode($r['estimate'], JSON_UNESCAPED_UNICODE) : null, MARCEL['model']);
} catch (Throwable $e) {
    error_log('[returtax/chat] DB: ' . $e->getMessage());
}

rt_json(200, ['ok' => true, 'reply' => $r['reply'], 'action' => $r['action'], 'estimate' => $r['estimate']]);
