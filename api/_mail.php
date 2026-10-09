<?php
/**
 * Returtax — trimiterea e-mailurilor prin Gmail (SMTP), ca notificările să nu ajungă în spam.
 *
 * Datele de conectare stau doar pe server, în /home/returtax/secrets/mail.json:
 *   {"gmail": "adresa@gmail.com", "app_password": "parola de aplicație Google", "to": "contact@returtax.ro"}
 * Se creează cu:  ssh -t psiholog-vps 'php /home/returtax/bin/configureaza-email.php'
 *
 * Dacă fișierul lipsește sau Gmail nu răspunde, se încearcă mail() de pe server.
 */

require_once __DIR__ . '/_lib.php';

function rt_mail_config(): ?array {
    $cfg = json_decode((string)@file_get_contents(RT_SECRETS . '/mail.json'), true);
    return is_array($cfg) && !empty($cfg['gmail']) && !empty($cfg['app_password']) ? $cfg : null;
}

// Antet codat, ca diacriticele să ajungă corect
function rt_mail_header(string $text): string {
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * Trimite un e-mail text simplu. Întoarce true dacă a plecat.
 * $to: destinatarul (implicit adresa din configurare sau contact@returtax.ro).
 */
function rt_send_mail(string $subject, string $body, ?string $replyTo = null, ?string $to = null): bool {
    $cfg = rt_mail_config();
    $to = $to ?: ($cfg['to'] ?? 'contact@returtax.ro');
    if ($cfg) {
        try {
            rt_smtp_send($cfg['gmail'], $cfg['app_password'], $to, $subject, $body, $replyTo);
            return true;
        } catch (Throwable $e) {
            error_log('[returtax/mail] Gmail: ' . $e->getMessage());
        }
    }
    $headers = ['From: Returtax <no-reply@returtax.ro>', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit'];
    if ($replyTo) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    return @mail($to, rt_mail_header($subject), $body, implode("\r\n", $headers));
}

// Client SMTP minimal pentru smtp.gmail.com:465 (SSL), cu autentificare prin parolă de aplicație
function rt_smtp_send(string $user, string $pass, string $to, string $subject, string $body, ?string $replyTo): void {
    $fp = @stream_socket_client('ssl://smtp.gmail.com:465', $errno, $errstr, 15);
    if (!$fp) {
        throw new RuntimeException("conectare eșuată: $errstr");
    }
    stream_set_timeout($fp, 15);

    $expect = function (int $code) use ($fp): void {
        $reply = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break; // ultima linie a răspunsului
            }
        }
        if ((int)substr($reply, 0, 3) !== $code) {
            throw new RuntimeException('SMTP: ' . trim($reply));
        }
    };
    $send = function (string $cmd, int $code) use ($fp, $expect): void {
        fwrite($fp, $cmd . "\r\n");
        $expect($code);
    };

    $expect(220);
    $send('EHLO returtax.ro', 250);
    $send('AUTH LOGIN', 334);
    $send(base64_encode($user), 334);
    $send(base64_encode($pass), 235);
    $send('MAIL FROM:<' . $user . '>', 250);
    $send('RCPT TO:<' . $to . '>', 250);
    $send('DATA', 354);

    $headers = [
        'From: ' . rt_mail_header('Returtax') . ' <' . $user . '>',
        'To: <' . $to . '>',
        'Subject: ' . rt_mail_header($subject),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@returtax.ro>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($replyTo) {
        $headers[] = 'Reply-To: <' . $replyTo . '>';
    }
    // Rânduri care încep cu „.” se dublează (regula SMTP)
    $text = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
    fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $text) . "\r\n.\r\n");
    $expect(250);
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
}
