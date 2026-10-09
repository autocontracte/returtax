<?php
/**
 * Returtax — primește cererile din formularul de contact și din chat (Marcel)
 * și le trimite pe e-mail. Păstrează și o copie într-un fișier CSV, în afara folderului public.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

const TO_EMAIL   = 'contact@returtax.ro';
const FROM_EMAIL = 'no-reply@returtax.ro';

function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// Elimină caracterele de control (inclusiv rândurile noi, ca să nu poată fi injectate antete de e-mail)
function clean(string $key, int $max, bool $multiline = false): string {
    $v = trim((string)($_POST[$key] ?? ''));
    $v = $multiline ? preg_replace('/[^\P{C}\n]/u', '', $v) : preg_replace('/\p{C}/u', '', $v);
    return mb_substr($v ?? '', 0, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method']);
}

// Capcană pentru roboți: câmpul ascuns trebuie să rămână gol
if (!empty($_POST['website'])) {
    respond(200, ['ok' => true]);
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

$digits = preg_replace('/\D/', '', $phone);
if ($name === '' || strlen($digits) < 8 || strlen($digits) > 15 || $consent === '') {
    respond(422, ['ok' => false, 'error' => 'invalid']);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = '';
}

$when = date('Y-m-d H:i');
$ip   = $_SERVER['REMOTE_ADDR'] ?? '';

// Copie locală (în afara public_html), ca să nu se piardă nimic dacă e-mailul nu pleacă
$saved = false;
$dir = dirname(__DIR__, 2) . '/leads';
if (is_dir($dir) || @mkdir($dir, 0750, true)) {
    if ($fh = @fopen($dir . '/leads.csv', 'a')) {
        flock($fh, LOCK_EX);
        $saved = fputcsv($fh, [$when, $source, $name, $phone, $email, str_replace("\n", ' / ', $message), $ip]) !== false;
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

$subject = '=?UTF-8?B?' . base64_encode("Cerere nouă Returtax ($source): $name") . '?=';
$body = "Cerere nouă de pe returtax.ro\n\n"
      . "Sursa:   $source\n"
      . "Nume:    $name\n"
      . "Telefon: $phone\n"
      . "E-mail:  " . ($email ?: '-') . "\n"
      . "Data:    $when\n\n"
      . "Mesaj:\n" . ($message ?: '-') . "\n";

$headers = [
    'From: Returtax <' . FROM_EMAIL . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];
if ($email !== '') {
    $headers[] = 'Reply-To: ' . $email;
}

$sent = @mail(TO_EMAIL, $subject, $body, implode("\r\n", $headers));

// E suficient ca cererea să fi ajuns măcar pe un drum (e-mail sau fișier)
respond($sent || $saved ? 200 : 500, ['ok' => $sent || $saved]);
