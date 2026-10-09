<?php
/**
 * Returtax — salvează în baza de date ce nu trece prin AI, pentru panoul de admin:
 *   - mesajele din chat cu răspunsuri pe bază de cuvinte-cheie sau butoane (type = message)
 *   - cine a parcurs fereastra de WhatsApp (type = whatsapp)
 */

require __DIR__ . '/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rt_json(405, ['ok' => false]);
}
rt_require_same_origin();

$in = json_decode(file_get_contents('php://input', false, null, 0, 16000) ?: '', true);
if (!is_array($in)) {
    rt_json(422, ['ok' => false]);
}
$clean = fn($v, $max) => mb_substr(trim(preg_replace('/[^\P{C}\n]+/u', ' ', (string)$v) ?? ''), 0, $max);

try {
    switch ($in['type'] ?? '') {
        case 'message':
            $conv = rt_conversation_id($in['conversation_id'] ?? null);
            $role = ($in['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $source = ($in['source'] ?? '') === 'buton' ? 'buton' : 'script';
            $text = $clean($in['content'] ?? '', 2000);
            if (!$conv || $text === '') {
                rt_json(422, ['ok' => false]);
            }
            // Plafon simplu: cel mult 200 de mesaje pe conversație
            $count = rt_db()->prepare('SELECT messages FROM conversations WHERE id = ?');
            $count->execute([$conv]);
            if ((int)$count->fetchColumn() >= 200) {
                rt_json(429, ['ok' => false]);
            }
            rt_log_message($conv, $role, $source, $text);
            break;

        case 'whatsapp':
            $msg = 'Perioada: ' . $clean($in['years'] ?? '-', 60) . ' · D-nummer/MinID: ' . $clean($in['docs'] ?? '-', 60);
            rt_db()->prepare('INSERT INTO leads (created_at, source, name, message, conversation_id, ip_hash) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([rt_now(), 'whatsapp', $clean($in['name'] ?? '', 120), $msg,
                           rt_conversation_id($in['conversation_id'] ?? null), rt_ip_hash()]);
            break;

        default:
            rt_json(422, ['ok' => false]);
    }
} catch (Throwable $e) {
    error_log('[returtax/log] ' . $e->getMessage());
    rt_json(500, ['ok' => false]);
}

rt_json(200, ['ok' => true]);
