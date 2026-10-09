<?php
/**
 * Returtax — funcții comune pentru api/ și admin/.
 * Fișierele care încep cu „_” nu pot fi deschise din browser (vezi .htaccess).
 *
 * Datele stau în afara folderului public:
 *   /home/returtax/data/returtax.sqlite   conversații, mesaje, cereri
 *   /home/returtax/secrets/                cheia API, contul de admin
 */

const RT_HOME = __DIR__ . '/../..';               // /home/returtax
const RT_DATA = RT_HOME . '/data';
const RT_SECRETS = RT_HOME . '/secrets';

// Prețuri în USD per milion de tokeni: intrare, ieșire, citire din cache, scriere în cache (5 min)
const PRICES = [
    'claude-haiku-5-5'  => [0.10, 0.50, 0.01, 0.125],   // prompturi sub 100K tokeni
    'claude-sonnet-5-5' => [2.00, 10.00, 0.20, 2.50],
];
const USD_TO_RON = 4.6;                                  // curs aproximativ, doar pentru afișare

function rt_json(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function rt_db(): PDO {
    static $db = null;
    if ($db) {
        return $db;
    }
    if (!is_dir(RT_DATA)) {
        @mkdir(RT_DATA, 0700, true);
    }
    $db = new PDO('sqlite:' . RT_DATA . '/returtax.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 4000; PRAGMA foreign_keys = ON;');
    $db->exec("
        CREATE TABLE IF NOT EXISTS conversations (
            id          TEXT PRIMARY KEY,
            started_at  TEXT NOT NULL,
            last_at     TEXT NOT NULL,
            ip_hash     TEXT,
            user_agent  TEXT,
            messages    INTEGER NOT NULL DEFAULT 0,
            cost_usd    REAL NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS messages (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id TEXT NOT NULL REFERENCES conversations(id),
            created_at      TEXT NOT NULL,
            role            TEXT NOT NULL,           -- user | assistant
            source          TEXT NOT NULL,           -- ai | script | buton
            content         TEXT NOT NULL,
            input_tokens    INTEGER, output_tokens INTEGER, cache_read INTEGER, cache_write INTEGER,
            cost_usd        REAL,
            meta            TEXT,                    -- ex. estimarea calculată
            model           TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_messages_conv ON messages(conversation_id, id);
        CREATE TABLE IF NOT EXISTS leads (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at      TEXT NOT NULL,
            source          TEXT NOT NULL,           -- formular | calculator | chat | whatsapp
            name            TEXT, phone TEXT, email TEXT, message TEXT,
            conversation_id TEXT,
            status          TEXT NOT NULL DEFAULT 'nou',
            note            TEXT,
            ip_hash         TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created_at);
    ");
    // Bazele create înainte de coloana „model”
    try {
        $db->exec('ALTER TABLE messages ADD COLUMN model TEXT');
    } catch (PDOException $e) {
        // coloana există deja
    }
    return $db;
}

function rt_now(): string {
    return date('Y-m-d H:i:s');
}

function rt_ip_hash(): string {
    return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|returtax'), 0, 16);
}

// Doar cereri venite de pe site-ul nostru
function rt_require_same_origin(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && !preg_match('#^https://(www\.)?returtax\.ro$#', $origin)) {
        rt_json(403, ['ok' => false, 'error' => 'origin']);
    }
}

// Id-ul conversației vine din browser (UUID); îl validăm strict
function rt_conversation_id($raw): ?string {
    $id = is_string($raw) ? strtolower(trim($raw)) : '';
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) ? $id : null;
}

function rt_touch_conversation(string $id): void {
    $db = rt_db();
    $now = rt_now();
    $db->prepare('INSERT INTO conversations (id, started_at, last_at, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?)
                  ON CONFLICT(id) DO UPDATE SET last_at = excluded.last_at')
       ->execute([$id, $now, $now, rt_ip_hash(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)]);
}

function rt_log_message(string $conv, string $role, string $source, string $content, array $usage = [], ?string $meta = null, ?string $model = null): void {
    $cost = $usage ? rt_cost($usage, (string)$model) : 0.0;
    $db = rt_db();
    rt_touch_conversation($conv);
    $db->prepare('INSERT INTO messages (conversation_id, created_at, role, source, content, input_tokens, output_tokens, cache_read, cache_write, cost_usd, meta, model)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$conv, rt_now(), $role, $source, mb_substr($content, 0, 4000),
                  $usage['input_tokens'] ?? null, $usage['output_tokens'] ?? null,
                  $usage['cache_read_input_tokens'] ?? null, $usage['cache_creation_input_tokens'] ?? null,
                  $usage ? $cost : null, $meta, $model]);
    $db->prepare('UPDATE conversations SET messages = messages + 1, cost_usd = cost_usd + ? WHERE id = ?')
       ->execute([$cost, $conv]);
}

function rt_cost(array $u, string $model): float {
    [$in, $out, $read, $write] = PRICES[$model] ?? PRICES['claude-sonnet-5-5'];
    return (($u['input_tokens'] ?? 0) * $in
          + ($u['output_tokens'] ?? 0) * $out
          + ($u['cache_read_input_tokens'] ?? 0) * $read
          + ($u['cache_creation_input_tokens'] ?? 0) * $write) / 1e6;
}

/**
 * Estimarea sumei recuperabile, după regulile fiscale norvegiene pentru anul 2025.
 *
 * Cum se face calculul (aceeași logică ca în calculatorul de pe site, js/site.js → TAX):
 *  1. Cât s-a reținut: majoritatea muncitorilor străini sunt în schema PAYE (kildeskatt),
 *     cu 25% fix din salariul brut, fără deduceri (pentru venituri anuale sub 697.150 NOK).
 *  2. Cât trebuia plătit cu impozitarea obișnuită (pe care o pot cere, până la 3 ani în urmă):
 *     contribuția socială (trygdeavgift) 7,7% + impozitul în trepte (trinnskatt)
 *     + 22% din venitul rămas după deduceri:
 *       - deducerea minimă (minstefradrag): 46% din venit, cel mult 92.000 NOK;
 *       - deducerea personală (personfradrag): 108.550 NOK, proporțional cu lunile lucrate;
 *       - cazare plătită singur (navetist): ~4.500 NOK pe lună lucrată;
 *       - drumuri acasă: 4 drumuri dus-întors pe an × ~2.400 km × 1,83 NOK/km, minus pragul de 15.250 NOK;
 *       - dobânzi la un credit: ~15.000 NOK pe an.
 *  3. Diferența, înmulțită cu numărul de ani, este estimarea (afișată ca interval de ±10%).
 *
 * Valorile marcate „ipoteză” sunt aproximări; ajustați-le pe măsură ce aveți date din dosarele reale.
 * Surse: Skatteetaten (PAYE, forskuddsmeldingen 2025, Skatte-ABC: pendler, personfradrag).
 */
const TAX_2025 = [
    'nok_per_eur'   => 11.7,     // curs aproximativ
    'paye_rate'     => 0.25,     // schema PAYE 2025
    'paye_max'      => 697150,   // peste acest venit anual nu se aplică PAYE
    'trygd_rate'    => 0.077,    // trygdeavgift pe salariu
    'trygd_min'     => 99650,    // sub acest venit nu se plătește
    'trinn'         => [[217401, 0.017], [306051, 0.04], [697151, 0.137], [942401, 0.167], [1410751, 0.177]],
    'ordinary_rate' => 0.22,     // impozit pe venitul ordinar
    'minste_rate'   => 0.46,
    'minste_max'    => 92000,
    'personfradrag' => 108550,
    'lodging_month' => 4500,     // ipoteză: cost lunar de cazare plătit singur
    'trips'         => 4,        // ipoteză: drumuri dus-întors acasă pe an (minimul cerut pentru navetiști din SEE)
    'km_one_way'    => 2400,     // ipoteză: distanța medie România – Norvegia
    'km_rate'       => 1.83,
    'travel_floor'  => 15250,
    'travel_max'    => 100880,
    'loan_interest' => 15000,    // ipoteză: dobânzi anuale la un credit
    'spread'        => 0.10,     // intervalul afișat: ±10%
];

// Impozitul cu regulile obișnuite, după deducerile date
function rt_tax_ordinary(float $g, float $deductions): float {
    $t = TAX_2025;
    $trygd = $g > $t['trygd_min'] ? min($t['trygd_rate'] * $g, 0.25 * ($g - $t['trygd_min'])) : 0;
    $trinn = 0.0;
    $steps = $t['trinn'];
    foreach ($steps as $i => [$from, $rate]) {
        $to = $steps[$i + 1][0] ?? INF;
        if ($g > $from) {
            $trinn += (min($g, $to) - $from) * $rate;
        }
    }
    return $trygd + $trinn + $t['ordinary_rate'] * max(0, $g - $deductions);
}

// Cât primește înapoi pe un an (NOK), pentru un venit anual brut $g câștigat în $months luni
function rt_refund_year(float $g, int $months, bool $housing, bool $travel, bool $family, bool $loan): float {
    $t = TAX_2025;
    $basic = min($t['minste_rate'] * $g, $t['minste_max']) + $t['personfradrag'] * $months / 12;
    $extra = 0.0;
    $commuter = $family || $travel;              // navetist: familia acasă sau drumuri regulate acasă
    if ($housing && $commuter) {
        $extra += $t['lodging_month'] * $months;
    }
    if ($travel) {
        $extra += max(0, min($t['trips'] * 2 * $t['km_one_way'] * $t['km_rate'], $t['travel_max']) - $t['travel_floor']);
    }
    if ($loan) {
        $extra += $t['loan_interest'];
    }
    $actual = rt_tax_ordinary($g, $basic + $extra);
    // Ce s-a reținut: 25% fix (PAYE); peste plafon, reținerea obișnuită fără deducerile de navetist
    $withheld = $g <= $t['paye_max'] ? $t['paye_rate'] * $g : rt_tax_ordinary($g, $basic);
    return max(0, $withheld - $actual);
}

function rt_estimate(float $salary, string $currency, int $months, int $years,
                     bool $housing, bool $travel, bool $family, bool $loan): array {
    $t = TAX_2025;
    $months = max(1, min(12, $months));
    $years  = max(1, min(3, $years));            // se pot cere de regulă ultimii 3 ani
    $monthlyNok = $currency === 'NOK' ? $salary : $salary * $t['nok_per_eur'];
    $perYear = rt_refund_year($monthlyNok * $months, $months, $housing, $travel, $family, $loan);
    $totalEur = $perYear * $years / $t['nok_per_eur'];

    $round = fn($n) => (int)(round($n / 10) * 10);
    $low  = $round($totalEur * (1 - $t['spread']));
    $high = $round($totalEur * (1 + $t['spread']));
    // Comisionul depinde de suma recuperată: 0 € sub 1.000 €, 100 € fix peste
    $feeLow  = $low >= 1000 ? 100 : 0;
    $feeHigh = $high >= 1000 ? 100 : 0;
    return [
        'estimare_minima_eur' => $low,
        'estimare_maxima_eur' => $high,
        'comision_eur'        => $feeLow === $feeHigh ? $feeHigh : '0 sau 100 (100 doar dacă suma depășește 1.000 €)',
        'ramane_minim_eur'    => $low - $feeLow,
        'ramane_maxim_eur'    => $high - $feeHigh,
        'pe_an_nok'           => (int)round($perYear),
        'nota' => 'Estimare orientativă după regulile din 2025, presupunând impozitare PAYE de 25%. Suma exactă se află după verificarea gratuită.',
    ];
}
