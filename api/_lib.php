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

// Prețuri Claude Haiku 5.5 (prompturi sub 100K tokeni), în USD per token
const PRICE_IN          = 0.10 / 1e6;
const PRICE_OUT         = 0.50 / 1e6;
const PRICE_CACHE_READ  = 0.01 / 1e6;
const PRICE_CACHE_WRITE = 0.125 / 1e6;
const USD_TO_RON        = 4.6;                     // curs aproximativ, doar pentru afișare

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
            meta            TEXT                     -- ex. estimarea calculată
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

function rt_log_message(string $conv, string $role, string $source, string $content, array $usage = [], ?string $meta = null): void {
    $cost = rt_cost($usage);
    $db = rt_db();
    rt_touch_conversation($conv);
    $db->prepare('INSERT INTO messages (conversation_id, created_at, role, source, content, input_tokens, output_tokens, cache_read, cache_write, cost_usd, meta)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$conv, rt_now(), $role, $source, mb_substr($content, 0, 4000),
                  $usage['input_tokens'] ?? null, $usage['output_tokens'] ?? null,
                  $usage['cache_read_input_tokens'] ?? null, $usage['cache_creation_input_tokens'] ?? null,
                  $usage ? $cost : null, $meta]);
    $db->prepare('UPDATE conversations SET messages = messages + 1, cost_usd = cost_usd + ? WHERE id = ?')
       ->execute([$cost, $conv]);
}

function rt_cost(array $u): float {
    return ($u['input_tokens'] ?? 0) * PRICE_IN
         + ($u['output_tokens'] ?? 0) * PRICE_OUT
         + ($u['cache_read_input_tokens'] ?? 0) * PRICE_CACHE_READ
         + ($u['cache_creation_input_tokens'] ?? 0) * PRICE_CACHE_WRITE;
}

/**
 * Estimarea sumei recuperabile — aceeași formulă ca în calculatorul de pe site (js/site.js, CALC).
 * Dacă schimbați coeficienții, schimbați-i în ambele locuri.
 */
function rt_estimate(float $salary, string $currency, int $months, int $years,
                     bool $housing, bool $travel, bool $family, bool $loan): array {
    $nokPerEur = 11.7;
    $months = max(1, min(12, $months));
    $years  = max(1, min(5, $years));
    $monthlyEur = $currency === 'NOK' ? $salary / $nokPerEur : $salary;
    $rate = 0.02
          + ($housing ? 0.05 : 0)
          + ($travel ? 0.025 : 0)
          + ($family ? 0.02 : 0)
          + ($loan ? 0.015 : 0)
          + ($months <= 6 ? 0.02 : 0);
    $rate = min($rate, 0.14);
    $mid  = max(0, $monthlyEur * $months * $rate * $years);
    $fee  = $mid >= 1000 ? 100 : 0;
    $round = fn($n) => (int)(round($n / 10) * 10);
    return [
        'estimare_minima_eur' => $round($mid * 0.6),
        'estimare_maxima_eur' => $round($mid * 1.3),
        'comision_eur'        => $fee,
        'ramane_clientului_aprox_eur' => $round(max(0, $mid - $fee)),
        'nota' => 'Estimare orientativă, nu o garanție. Suma exactă se află după verificarea gratuită.',
    ];
}
