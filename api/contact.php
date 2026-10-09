<?php
/**
 * Returtax — primește cererile din formularul de contact, din pop-up-ul calculatorului
 * și din chat (Marcel). Le salvează în baza de date (panoul de admin), păstrează o copie CSV
 * în afara folderului public și trimite o notificare pe e-mail (prin Gmail, vezi api/_mail.php).
 */

require __DIR__ . '/_mail.php';

// Elimină caracterele de control (inclusiv rândurile noi, ca să nu poată fi injectate antete de e-mail)
function clean(string $key, int $max, bool $multiline = false): string {
    $v = trim((string)($_POST[$key] ?? ''));
    $v = $multiline ? preg_replace('/[^\P{C}\n]/u', '', $v) : preg_replace('/\p{C}/u', '', $v);
    return mb_substr($v ?? '', 0, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rt_json(405, ['ok' => false, 'error' => 'method']);
}
rt_require_same_origin();

// Capcană pentru roboți: câmpul ascuns trebuie să rămână gol
if (!empty($_POST['website'])) {
    rt_json(200, ['ok' => true]);
}

$source  = clean('source', 20);
if (!in_array($source, ['chat', 'calculator', 'formular'], true)) {
    $source = 'formular';
}
$name    = clean('name', 120);
$phone   = clean('phone', 40);
$email   = clean('email', 160);
$message = clean('message', 3000, true);
$consent = clean('consent', 10);
$conv    = rt_conversation_id($_POST['conversation_id'] ?? null);

$digits = preg_replace('/\D/', '', $phone);
if ($name === '' || strlen($digits) < 8 || strlen($digits) > 15 || $consent === '') {
    rt_json(422, ['ok' => false, 'error' => 'invalid']);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = '';
}

$when = rt_now();

// 1) Baza de date (panoul de admin)
$saved = false;
try {
    rt_db()->prepare('INSERT INTO leads (created_at, source, name, phone, email, message, conversation_id, ip_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$when, $source, $name, $phone, $email, $message, $conv, rt_ip_hash()]);
    $saved = true;
} catch (Throwable $e) {
    error_log('[returtax/contact] DB: ' . $e->getMessage());
}

// 2) Copie CSV (în afara public_html), ca rezervă
$dir = RT_HOME . '/leads';
if ((is_dir($dir) || @mkdir($dir, 0750, true)) && ($fh = @fopen($dir . '/leads.csv', 'a'))) {
    flock($fh, LOCK_EX);
    $saved = fputcsv($fh, [$when, $source, $name, $phone, $email, str_replace("\n", ' / ', $message)]) !== false || $saved;
    flock($fh, LOCK_UN);
    fclose($fh);
}

// 3) Notificare pe e-mail
$subject = "Cerere nouă Returtax ($source): $name";
$body = "Cerere nouă de pe returtax.ro\n\n"
      . "Sursa:   $source\n"
      . "Nume:    $name\n"
      . "Telefon: $phone\n"
      . "E-mail:  " . ($email ?: '-') . "\n"
      . "Data:    $when\n\n"
      . "Mesaj:\n" . ($message ?: '-') . "\n\n"
      . "Toate cererile: https://returtax.ro/admin/\n";

$sent = rt_send_mail($subject, $body, $email !== '' ? $email : null);

// E suficient ca cererea să fi ajuns măcar pe un drum
rt_json($sent || $saved ? 200 : 500, ['ok' => $sent || $saved]);
