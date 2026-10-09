<?php
/**
 * Returtax — creează (sau schimbă) contul pentru panoul de admin.
 *
 * Rulare pe server:
 *   ssh -t psiholog-vps 'php /home/returtax/bin/creeaza-admin.php'
 *
 * Parola nu se afișează în timp ce o scrieți și se salvează doar ca hash,
 * în /home/returtax/secrets/admin.json.
 */

if (PHP_SAPI !== 'cli') {
    exit("Doar din linia de comandă.\n");
}

$file = __DIR__ . '/../secrets/admin.json';

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

$user = ask('Utilizator: ');
if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $user)) {
    exit("Utilizatorul trebuie să aibă 3–40 de caractere (litere, cifre, . _ -).\n");
}
$pass = ask('Parolă (minim 12 caractere): ', true);
if (mb_strlen($pass) < 12) {
    exit("Parola e prea scurtă.\n");
}
if (ask('Repetați parola: ', true) !== $pass) {
    exit("Parolele nu coincid.\n");
}

@mkdir(dirname($file), 0700, true);
file_put_contents($file, json_encode(['user' => $user, 'hash' => password_hash($pass, PASSWORD_DEFAULT)]));
chmod($file, 0600);
// Dacă rulați ca root, fișierul trebuie să rămână al site-ului
if (function_exists('posix_getuid') && posix_getuid() === 0) {
    chown($file, 'returtax');
    chgrp($file, 'returtax');
}
echo "Gata. Intrați pe https://returtax.ro/admin/\n";
