<?php
/**
 * Returtax — configurează trimiterea notificărilor prin Gmail și trimite un e-mail de test.
 *
 * Rulare pe server:
 *   ssh -t psiholog-vps 'php /home/returtax/bin/configureaza-email.php'
 *
 * Aveți nevoie de o „parolă de aplicație” Google (Contul Google → Securitate →
 * Verificare în doi pași → Parole pentru aplicații). Parola nu se afișează în timp ce o scrieți
 * și se salvează doar pe server, în /home/returtax/secrets/mail.json.
 */

if (PHP_SAPI !== 'cli') {
    exit("Doar din linia de comandă.\n");
}
require __DIR__ . '/../public_html/api/_mail.php';

function ask(string $label, bool $hidden = false): string {
    echo $label;
    if ($hidden) {
        system('stty -echo');
    }
    $value = trim((string)fgets(STDIN));
    if ($hidden) {
        system('stty echo');
        echo "\n";
    }
    return $value;
}

$gmail = ask('Adresa de Gmail: ');
if (!filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
    exit("Adresă invalidă.\n");
}
$pass = str_replace(' ', '', ask('Parola de aplicație Google (16 litere): ', true));
if (strlen($pass) < 16) {
    exit("Parola de aplicație are 16 litere.\n");
}
$to = ask('Unde să ajungă notificările [' . RT_MAIL_TO . ']: ') ?: RT_MAIL_TO;

echo "Trimit un e-mail de test către $to...\n";
try {
    rt_smtp_send($gmail, $pass, $to, 'Test Returtax: notificările funcționează',
        "Salut!\n\nAcesta este un e-mail de test de pe returtax.ro.\nDe acum, cererile noi de pe site vor ajunge aici.\n", null);
} catch (Throwable $e) {
    exit('Nu a mers: ' . $e->getMessage() . "\nVerificați adresa și parola de aplicație. Nu am salvat nimic.\n");
}

$file = RT_SECRETS . '/mail.json';
@mkdir(dirname($file), 0700, true);
file_put_contents($file, json_encode(['gmail' => $gmail, 'app_password' => $pass, 'to' => $to]));
chmod($file, 0600);
if (function_exists('posix_getuid') && posix_getuid() === 0) {
    chown($file, 'returtax');
    chgrp($file, 'returtax');
}
echo "Gata. E-mailul de test a plecat, iar setările sunt salvate.\n";
