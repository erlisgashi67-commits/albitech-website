<?php
/*
 * Albitech admin: shared code.
 *
 * The editable content lives in data/content.json. Saving it rewrites the
 * marked regions of the HTML pages (<!-- cms:name --> ... <!-- /cms:name -->),
 * so the public site stays plain, fast HTML with no database.
 *
 * Works on PHP 7.4 and newer.
 */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

define('SITE_DIR', dirname(__DIR__));
define('DATA_DIR', SITE_DIR . '/data');
define('CONTENT_FILE', DATA_DIR . '/content.json');
define('CREDENTIALS_FILE', DATA_DIR . '/admin-credentials.php');
define('ATTEMPTS_FILE', DATA_DIR . '/login-attempts.json');
define('BACKUP_DIR', DATA_DIR . '/backups');
define('LOCK_FILE', DATA_DIR . '/.lock');

const PAGES = ['index.html', 'services.html', 'albismart.html', 'facility.html', 'about.html', 'contact.html'];
const MAX_BACKUPS = 30;
const MAX_STATS = 8;
const MAX_HOURS = 7;
const MAX_COUNTRIES = 200;
const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_SECONDS = 900;
const SESSION_IDLE_SECONDS = 7200;

/* ---------------------------------------------------------------
   Small helpers
   --------------------------------------------------------------- */

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function str_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/** Write a file in one step, so a reader never sees half a file. */
function write_atomic(string $path, string $data): bool
{
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $data) === false) {
        return false;
    }
    if (is_file($path)) {
        @chmod($tmp, fileperms($path) & 0777);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function with_lock(callable $fn)
{
    $fp = fopen(LOCK_FILE, 'c');
    if ($fp === false) {
        return $fn();
    }
    flock($fp, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/* ---------------------------------------------------------------
   Content: load, validate, save, back up
   --------------------------------------------------------------- */

function load_content(?string $file = null): array
{
    $file = $file ?? CONTENT_FILE;
    if (!is_file($file)) {
        throw new RuntimeException('The content file is missing: ' . basename($file));
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException('The content file could not be read: ' . basename($file));
    }
    return $data;
}

function content_json(array $content): string
{
    return json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/**
 * Turn submitted form fields into clean content.
 * Returns [content, errors]; errors is a list of messages.
 */
function validate_content(array $in): array
{
    $errors = [];
    $text = function ($value, int $max, string $name, bool $required = false) use (&$errors): string {
        $value = trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');
        if ($required && $value === '') {
            $errors[] = $name . ' is required.';
        } elseif (str_len($value) > $max) {
            $errors[] = $name . ' is too long (at most ' . $max . ' characters).';
        }
        return $value;
    };

    // Numbers on the home page
    $stats = [];
    foreach (array_values((array) ($in['stats'] ?? [])) as $i => $row) {
        $row = (array) $row;
        $n = 'Number ' . ($i + 1);
        $value = str_replace(',', '', $text($row['value'] ?? '', 12, $n . ': value', true));
        if ($value !== '' && !preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            $errors[] = $n . ': the value must be a number, such as 64, 10 or 2.5.';
        }
        $suffix = $text($row['suffix'] ?? '', 4, $n . ': unit');
        if (!preg_match('/^[A-Za-z%+]*$/', $suffix)) {
            $errors[] = $n . ': the unit can only contain letters, % and +, such as M+, K or %.';
        }
        $stats[] = [
            'value' => $value,
            'suffix' => $suffix,
            'label' => $text($row['label'] ?? '', 40, $n . ': label', true),
            'isYear' => !empty($row['isYear']),
        ];
    }
    if (count($stats) < 1 || count($stats) > MAX_STATS) {
        $errors[] = 'Keep between 1 and ' . MAX_STATS . ' numbers on the home page.';
    }

    // Track record
    $record = (array) ($in['trackRecord'] ?? []);
    $countries = [];
    foreach (preg_split('/\r\n|\r|\n|,/', (string) ($record['countries'] ?? '')) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_len($line) > 40) {
            $errors[] = 'Country "' . $line . '" is too long.';
            continue;
        }
        if (!in_array($line, $countries, true)) {
            $countries[] = $line;
        }
    }
    if (count($countries) > MAX_COUNTRIES) {
        $errors[] = 'List at most ' . MAX_COUNTRIES . ' countries.';
    }
    $trackRecord = [
        'heading' => $text($record['heading'] ?? '', 120, 'Track record heading', true),
        'intro' => $text($record['intro'] ?? '', 400, 'Track record text'),
        'countries' => $countries,
    ];

    // Address and contact
    $c = (array) ($in['contact'] ?? []);
    $contact = [
        'company' => $text($c['company'] ?? '', 80, 'Building name', true),
        'street' => $text($c['street'] ?? '', 80, 'Street', true),
        'number' => $text($c['number'] ?? '', 12, 'Building number'),
        'postalCode' => $text($c['postalCode'] ?? '', 12, 'Postal code'),
        'town' => $text($c['town'] ?? '', 60, 'Town', true),
        'municipality' => $text($c['municipality'] ?? '', 60, 'Municipality'),
        'country' => $text($c['country'] ?? '', 60, 'Country', true),
        'countryCode' => strtoupper($text($c['countryCode'] ?? '', 2, 'Country code', true)),
        'phone' => $text($c['phone'] ?? '', 25, 'Phone', true),
        'email' => $text($c['email'] ?? '', 120, 'Email', true),
        'mapUrl' => $text($c['mapUrl'] ?? '', 300, 'Google Maps link', true),
    ];
    if ($contact['postalCode'] !== '' && !preg_match('/^[0-9A-Za-z -]+$/', $contact['postalCode'])) {
        $errors[] = 'The postal code can only contain letters, digits and spaces.';
    }
    if ($contact['countryCode'] !== '' && !preg_match('/^[A-Z]{2}$/', $contact['countryCode'])) {
        $errors[] = 'The country code must be two letters, such as XK for Kosovo.';
    }
    if ($contact['phone'] !== '' && !preg_match('/^\+?[0-9 ()\-]{6,24}$/', $contact['phone'])) {
        $errors[] = 'The phone number can only contain digits, spaces, brackets, dashes and a leading +.';
    }
    if ($contact['email'] !== '' && !filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'The email address is not valid.';
    }
    if ($contact['mapUrl'] !== '' && (stripos($contact['mapUrl'], 'https://') !== 0 || !filter_var($contact['mapUrl'], FILTER_VALIDATE_URL))) {
        $errors[] = 'The Google Maps link must be a full address starting with https://';
    }

    // Business hours
    $hours = [];
    foreach (array_values((array) ($in['hours'] ?? [])) as $i => $row) {
        $row = (array) $row;
        $hours[] = [
            'days' => $text($row['days'] ?? '', 40, 'Business hours row ' . ($i + 1) . ': days', true),
            'time' => $text($row['time'] ?? '', 40, 'Business hours row ' . ($i + 1) . ': hours', true),
        ];
    }
    if (count($hours) < 1 || count($hours) > MAX_HOURS) {
        $errors[] = 'Keep between 1 and ' . MAX_HOURS . ' rows of business hours.';
    }

    return [[
        'stats' => $stats,
        'trackRecord' => $trackRecord,
        'contact' => $contact,
        'hours' => $hours,
    ], array_values(array_unique($errors))];
}

/** Keep a copy of the current content before it is replaced. */
function backup_current(): void
{
    if (!is_file(CONTENT_FILE)) {
        return;
    }
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0755, true);
    }
    $name = 'content-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
    @copy(CONTENT_FILE, BACKUP_DIR . '/' . $name);

    $all = list_backups();
    foreach (array_slice($all, MAX_BACKUPS) as $old) {
        @unlink(BACKUP_DIR . '/' . $old['file']);
    }
}

/** Newest first. */
function list_backups(): array
{
    $out = [];
    foreach (glob(BACKUP_DIR . '/content-*.json') ?: [] as $path) {
        $out[] = ['file' => basename($path), 'time' => (int) filemtime($path)];
    }
    usort($out, function ($a, $b) {
        return [$b['time'], $b['file']] <=> [$a['time'], $a['file']];
    });
    return $out;
}

function backup_path(string $file): ?string
{
    if (!preg_match('/^content-\d{8}-\d{6}-[0-9a-f]{4}\.json$/', $file)) {
        return null;
    }
    $path = BACKUP_DIR . '/' . $file;
    return is_file($path) ? $path : null;
}

/**
 * Save new content and publish it to the pages.
 * Returns ['pages' => [...updated], 'errors' => [...]].
 */
function save_and_publish(array $content): array
{
    return with_lock(function () use ($content) {
        $content['updated'] = gmdate('c');
        backup_current();
        if (!write_atomic(CONTENT_FILE, content_json($content))) {
            return ['pages' => [], 'errors' => ['Could not save data/content.json. Check that the data folder is writable.']];
        }
        return publish_pages($content);
    });
}

/* ---------------------------------------------------------------
   Publishing: rewrite the marked regions of every page
   --------------------------------------------------------------- */

function publish_pages(array $content, string $siteDir = SITE_DIR): array
{
    $updated = [];
    $errors = [];
    foreach (PAGES as $page) {
        $path = $siteDir . '/' . $page;
        if (!is_file($path)) {
            continue;
        }
        $html = (string) file_get_contents($path);
        try {
            $new = apply_regions($html, $content);
        } catch (Throwable $e) {
            $errors[] = $page . ': ' . $e->getMessage();
            continue;
        }
        if ($new === $html) {
            continue;
        }
        if (!is_writable($path) || !write_atomic($path, $new)) {
            $errors[] = 'Could not write ' . $page . '. Check the file permissions.';
            continue;
        }
        $updated[] = $page;
    }
    return ['pages' => $updated, 'errors' => $errors];
}

function apply_regions(string $html, array $content): string
{
    $pattern = '/<!-- cms:([a-z0-9-]+) -->(.*?)<!-- \/cms:\1 -->/s';
    $result = preg_replace_callback($pattern, function ($m) use ($html, $content) {
        $name = $m[1];
        $inner = $m[2];
        $open = '<!-- cms:' . $name . ' -->';
        $close = '<!-- /cms:' . $name . ' -->';

        if ($name === 'jsonld') {
            return $open . render_jsonld($inner, $content) . $close;
        }

        $lines = render_region($name, $content);
        if ($lines === null) {
            return $m[0]; // unknown region: leave it alone
        }
        if (strpos($inner, "\n") === false) {
            return $open . implode('', $lines) . $close; // inline region
        }
        // Block region: keep the indentation of the opening marker.
        $indent = region_indent($html, $m[0]);
        $body = '';
        foreach ($lines as $line) {
            $body .= "\n" . $indent . $line;
        }
        return $open . $body . "\n" . $indent . $close;
    }, $html);
    if ($result === null) {
        throw new RuntimeException('The page markers could not be processed.');
    }
    return $result;
}

function region_indent(string $html, string $region): string
{
    $pos = strpos($html, $region);
    if ($pos === false) {
        return '';
    }
    $lineStart = strrpos(substr($html, 0, $pos), "\n");
    $prefix = substr($html, $lineStart === false ? 0 : $lineStart + 1, $pos - ($lineStart === false ? 0 : $lineStart + 1));
    return preg_match('/^[ \t]*$/', $prefix) ? $prefix : '';
}

/** Returns the lines for a region, or null for an unknown region name. */
function render_region(string $name, array $content): ?array
{
    $c = $content['contact'];
    switch ($name) {
        case 'stats':
            return render_stats($content['stats']);
        case 'record':
            return render_record($content['trackRecord']);
        case 'footer-contact':
            return render_footer_contact($c, $content['hours']);
        case 'contact-details':
            return render_contact_details($c);
        case 'hours':
            return render_hours($content['hours']);
        case 'hq':
            return [h(implode(', ', array_filter([$c['town'], $c['municipality'], $c['country']])))];
        case 'map-address':
            return [h(address_line($c))];
    }
    return null;
}

/* ---------- Formatting ---------- */

function stat_decimals(string $value): int
{
    $dot = strpos($value, '.');
    return $dot === false ? 0 : strlen($value) - $dot - 1;
}

function stat_display(array $s): string
{
    $value = $s['value'];
    return number_format((float) $value, stat_decimals($value), '.', $s['isYear'] ? '' : ',');
}

/** What a screen reader says, for example "More than 5 million CPE devices tested". */
function stat_spoken(array $s): string
{
    $label = $s['label'];
    $num = stat_display($s);
    if ($s['isYear']) {
        return $label . ' in ' . $num;
    }
    if (preg_match('/^[A-Z][a-z]/', $label)) {
        $label = lcfirst($label);
    }
    $suffix = $s['suffix'];
    $more = substr($suffix, -1) === '+';
    $core = rtrim($suffix, '+');
    $words = ['K' => 'thousand', 'k' => 'thousand', 'M' => 'million', 'm' => 'million', 'B' => 'billion', 'b' => 'billion', '%' => 'percent'];
    $amount = $num . (isset($words[$core]) ? ' ' . $words[$core] : ($core !== '' ? ' ' . $core : ''));
    $sentence = ($more ? 'More than ' . $amount : $amount) . ' ' . $label;
    return ucfirst($sentence);
}

function street_line(array $c): string
{
    return trim($c['street'] . ' ' . $c['number']);
}

function town_line(array $c): string
{
    return trim($c['postalCode'] . ' ' . $c['town']);
}

/** One line, for example "Xhafer Gashi 872, 24060 Kijevë, Kosovo". */
function address_line(array $c): string
{
    return implode(', ', array_filter([street_line($c), town_line($c), $c['country']]));
}

function tel_href(string $phone): string
{
    return 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
}

/* ---------- Region templates ---------- */

function render_stats(array $stats): array
{
    $count = count($stats);
    $cols = $count <= 4 ? $count : (int) ceil($count / 2);
    $lines = [];
    foreach ($stats as $i => $s) {
        $classes = ['stat'];
        if ($i > 0 && $i % $cols === 0) {
            $classes[] = 'stat--row-start';
        }
        if ($i >= $cols) {
            $classes[] = 'stat--next-row';
        }
        if ($i === $count - 1 && $count > $cols && $count % $cols !== 0) {
            $classes[] = 'stat--fill'; // last row is short: stretch to the edge
        }
        $attrs = ' data-count="' . h($s['value']) . '"';
        $decimals = stat_decimals($s['value']);
        if ($decimals > 0) {
            $attrs .= ' data-decimals="' . $decimals . '"';
        }
        if ($s['isYear']) {
            $attrs .= ' data-grouping="false"';
        }
        $suffix = $s['suffix'] !== '' ? '<span class="suffix">' . h($s['suffix']) . '</span>' : '';
        $lines[] = '<div class="' . implode(' ', $classes) . '">';
        $lines[] = '  <span class="stat-num" aria-hidden="true"><span' . $attrs . '>' . h(stat_display($s)) . '</span>' . $suffix . '</span>';
        $lines[] = '  <span class="stat-label">' . h($s['label']) . '</span>';
        $lines[] = '  <span class="sr-only">' . h(stat_spoken($s)) . '</span>';
        $lines[] = '</div>';
    }
    // The column count travels with the cards so the grid can size itself.
    array_unshift($lines, '<div class="stats-card" style="--stat-cols:' . $cols . '" data-reveal>');
    $lines = array_merge([$lines[0]], array_map(function ($l) {
        return '  ' . $l;
    }, array_slice($lines, 1)));
    $lines[] = '</div>';
    return $lines;
}

function render_record(array $r): array
{
    $lines = ['<h2 id="record-title">' . h($r['heading']) . '</h2>'];
    if ($r['intro'] !== '') {
        $lines[] = '<p class="lead">' . h($r['intro']) . '</p>';
    }
    if ($r['countries']) {
        $lines[] = '<ul class="tags" aria-label="Countries">';
        foreach (array_chunk($r['countries'], 6) as $chunk) {
            $lines[] = '  ' . implode('', array_map(function ($name) {
                return '<li class="tag">' . h($name) . '</li>';
            }, $chunk));
        }
        $lines[] = '</ul>';
    }
    return $lines;
}

function footer_hours(array $hours): string
{
    $first = $hours[0] ?? null;
    return $first ? $first['days'] . ', ' . $first['time'] : '';
}

function render_footer_contact(array $c, array $hours): array
{
    $icon = [
        'web' => '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z"/></svg>',
        'mail' => '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6.5 8.5-6.5"/></svg>',
        'phone' => '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h3l2 5-2.5 1.5a11 11 0 0 0 7 7L16 14l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/></svg>',
        'pin' => '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>',
        'clock' => '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    ];
    return [
        '<li>' . $icon['web'] . '<a href="https://www.albi-tech.net">www.albi-tech.net</a></li>',
        '<li>' . $icon['mail'] . '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a></li>',
        '<li>' . $icon['phone'] . '<a href="' . h(tel_href($c['phone'])) . '">' . h($c['phone']) . '</a></li>',
        '<li>' . $icon['pin'] . '<a href="' . h($c['mapUrl']) . '" target="_blank" rel="noopener">' . h(address_line($c)) . '</a></li>',
        '<li>' . $icon['clock'] . '<span>' . h(footer_hours($hours)) . '</span></li>',
    ];
}

function render_contact_details(array $c): array
{
    $address = array_filter([
        h($c['company']),
        h(street_line($c)),
        h(implode(', ', array_filter([town_line($c), $c['municipality']]))),
        h($c['country']),
    ]);
    return [
        '<li>',
        '  <span class="icon-badge"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6.5 8.5-6.5"/></svg></span>',
        '  <div><span class="info-label">Email</span><a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a></div>',
        '</li>',
        '<li>',
        '  <span class="icon-badge"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z"/></svg></span>',
        '  <div><span class="info-label">Website</span><a href="https://www.albi-tech.net">www.albi-tech.net</a></div>',
        '</li>',
        '<li>',
        '  <span class="icon-badge"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg></span>',
        '  <div><span class="info-label">Warehouse</span><a href="' . h($c['mapUrl']) . '" target="_blank" rel="noopener">' . implode('<br>', $address) . '</a></div>',
        '</li>',
        '<li>',
        '  <span class="icon-badge"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h3l2 5-2.5 1.5a11 11 0 0 0 7 7L16 14l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/></svg></span>',
        '  <div><span class="info-label">Phone</span><a href="' . h(tel_href($c['phone'])) . '">' . h($c['phone']) . '</a></div>',
        '</li>',
    ];
}

function render_hours(array $hours): array
{
    $lines = [];
    foreach ($hours as $row) {
        $lines[] = '<tr><th scope="row">' . h($row['days']) . '</th><td>' . h($row['time']) . '</td></tr>';
    }
    return $lines;
}

/** Update the address, phone and email inside the page's structured data. */
function render_jsonld(string $inner, array $content): string
{
    if (!preg_match('/^(\s*<script type="application\/ld\+json">)(.*?)(<\/script>\s*)$/s', $inner, $m)) {
        throw new RuntimeException('The structured data block is not in the expected format.');
    }
    $data = json_decode($m[2], true);
    if (!is_array($data)) {
        throw new RuntimeException('The structured data is not valid JSON.');
    }
    $c = $content['contact'];
    $data['email'] = $c['email'];
    $data['telephone'] = $c['phone'];
    $address = ['@type' => 'PostalAddress', 'streetAddress' => street_line($c)];
    if ($c['postalCode'] !== '') {
        $address['postalCode'] = $c['postalCode'];
    }
    $address['addressLocality'] = $c['town'];
    if ($c['municipality'] !== '') {
        $address['addressRegion'] = $c['municipality'];
    }
    $address['addressCountry'] = $c['countryCode'];
    $data['address'] = $address;
    $data['hasMap'] = $c['mapUrl'];

    // Match the page's 2-space indentation.
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $json = preg_replace_callback('/^( +)/m', function ($s) {
        return str_repeat(' ', (int) (strlen($s[1]) / 2));
    }, $json);
    $indent = '';
    if (preg_match('/\n([ \t]*)$/', $m[3], $tail)) {
        $indent = $tail[1];
    }
    $json = $indent . str_replace("\n", "\n" . $indent, $json);
    return $m[1] . "\n" . $json . "\n" . $indent . '</script>' . substr($m[3], strlen('</script>'));
}

/* ---------------------------------------------------------------
   Sign-in, sessions and CSRF
   --------------------------------------------------------------- */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function start_admin_session(): void
{
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php')), '/') . '/';
    session_name('albitech_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    if (!empty($_SESSION['auth']) && time() - (int) ($_SESSION['last'] ?? 0) > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['expired'] = true;
    }
    if (!empty($_SESSION['auth'])) {
        $_SESSION['last'] = time();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function is_signed_in(): bool
{
    return !empty($_SESSION['auth']);
}

function csrf_token(): string
{
    return (string) $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    $sent = (string) ($_POST['csrf'] ?? '');
    return $sent !== '' && hash_equals(csrf_token(), $sent);
}

function credentials(): ?array
{
    if (!is_file(CREDENTIALS_FILE)) {
        return null;
    }
    $data = include CREDENTIALS_FILE;
    return is_array($data) && !empty($data['hash']) ? $data : null;
}

function set_password(string $password): bool
{
    $data = ['hash' => password_hash($password, PASSWORD_DEFAULT), 'changed' => gmdate('c')];
    $php = "<?php\n// Albitech admin password (hashed). Created by the admin; do not edit by hand.\nreturn " . var_export($data, true) . ";\n";
    $ok = write_atomic(CREDENTIALS_FILE, $php);
    if ($ok) {
        @chmod(CREDENTIALS_FILE, 0600);
    }
    return $ok;
}

function password_problem(string $new, string $confirm): ?string
{
    if (str_len($new) < 10) {
        return 'Use at least 10 characters for the password.';
    }
    if ($new !== $confirm) {
        return 'The two new passwords do not match.';
    }
    return null;
}

/* ---------- Login rate limit: 5 failures locks that address for 15 minutes ---------- */

function attempts_key(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function read_attempts(): array
{
    $data = is_file(ATTEMPTS_FILE) ? json_decode((string) file_get_contents(ATTEMPTS_FILE), true) : [];
    $data = is_array($data) ? $data : [];
    $now = time();
    foreach ($data as $key => $row) {
        if ($now - (int) ($row['first'] ?? 0) > LOGIN_LOCK_SECONDS && (int) ($row['until'] ?? 0) < $now) {
            unset($data[$key]);
        }
    }
    return $data;
}

function login_locked_for(): int
{
    $row = read_attempts()[attempts_key()] ?? null;
    return $row ? max(0, (int) ($row['until'] ?? 0) - time()) : 0;
}

function record_login(bool $success): void
{
    with_lock(function () use ($success) {
        $data = read_attempts();
        $key = attempts_key();
        if ($success) {
            unset($data[$key]);
        } else {
            $row = $data[$key] ?? ['count' => 0, 'first' => time(), 'until' => 0];
            $row['count']++;
            if ($row['count'] >= LOGIN_MAX_FAILS) {
                $row['until'] = time() + LOGIN_LOCK_SECONDS;
                $row['count'] = 0;
                $row['first'] = time();
            }
            $data[$key] = $row;
        }
        write_atomic(ATTEMPTS_FILE, json_encode($data));
    });
}
