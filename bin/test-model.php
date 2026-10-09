<?php
/**
 * Returtax — compară modele pentru Marcel pe aceeași conversație de test.
 * Afișează răspunsurile și costul real (tokeni din API), plus costul estimat la 1000 de întrebări.
 *
 * Rulare pe server:
 *   php /home/returtax/bin/test-model.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Doar din linia de comandă.\n");
}
require __DIR__ . '/../public_html/api/_marcel.php';

$key = trim((string)file_get_contents(RT_SECRETS . '/anthropic.key'));

$configs = [
    'Haiku 5.5 · efort low'         => ['model' => 'claude-haiku-5-5', 'effort' => 'low'],
    'Haiku 5.5 · efort medium'      => ['model' => 'claude-haiku-5-5', 'effort' => 'medium'],
    'Sonnet 5.5 · efort low'        => ['model' => 'claude-sonnet-5-5', 'effort' => 'low', 'fallbacks' => true],
    'Sonnet 5.5 · fără gândire'     => ['model' => 'claude-sonnet-5-5', 'effort' => 'low', 'thinking' => ['type' => 'between_tools']],
];

// O conversație tipică, cu un buton apăsat după estimare (acolo unde Marcel „uita”)
$userTurns = [
    'Bună ziua, am lucrat în Norvegia 2 ani pe șantier',
    'Cât pot primi înapoi?',
    'câștigam cam 30000 de coroane',
    '11 luni',
    'da la cazare și drumuri, familia e acasă, credit nu am',
    'Cât pot primi înapoi?',
    'și cât durează? nu am MinID',
];

$only = $argv[1] ?? null;
foreach ($configs as $label => $cfg) {
    if ($only && stripos($label, $only) === false) {
        continue;
    }
    echo "\n==================== $label ====================\n";
    $history = [];
    $total = 0.0;
    $turns = 0;
    foreach ($userTurns as $text) {
        $history[] = ['role' => 'user', 'content' => $text];
        $t = microtime(true);
        $r = rt_marcel_reply($key, $history, $cfg);
        $secs = microtime(true) - $t;
        if (!$r) {
            echo "  [EROARE API]\n";
            break;
        }
        $cost = rt_cost($r['usage'], $cfg['model']);
        $total += $cost;
        $turns++;
        $u = $r['usage'];
        printf("\n> %s\n< %s%s\n  [%.1fs · in %d + cache %d/%d · out %d · $%.5f%s]\n",
            $text, $r['reply'], $r['action'] ? ' [APEL]' : '', $secs,
            $u['input_tokens'], $u['cache_read_input_tokens'], $u['cache_creation_input_tokens'], $u['output_tokens'], $cost,
            $r['estimate'] ? ' · estimare ' . $r['estimate']['estimare_minima_eur'] . '–' . $r['estimate']['estimare_maxima_eur'] . ' €' : '');
        $history[] = ['role' => 'assistant', 'content' => $r['reply']];
    }
    if ($turns) {
        printf("\n  MEDIE: $%.5f / întrebare  →  %.2f lei la 1000 de întrebări\n", $total / $turns, $total / $turns * 1000 * USD_TO_RON);
    }
}
