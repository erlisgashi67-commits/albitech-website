<?php
/*
 * Apply data/content.json to the HTML pages, without the web admin.
 * Use it after changing content.json by hand, or after downloading a copy
 * from the live admin, so the repository matches the live site:
 *
 *   php admin/build.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib.php';

try {
    $raw = load_content();
    $raw['trackRecord']['countries'] = implode("\n", $raw['trackRecord']['countries'] ?? []);
    [$content, $errors] = validate_content($raw);
    if ($errors) {
        fwrite(STDERR, "data/content.json has problems:\n- " . implode("\n- ", $errors) . "\n");
        exit(1);
    }
    $result = publish_pages($content);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

foreach ($result['errors'] as $error) {
    fwrite(STDERR, $error . "\n");
}
fwrite(STDOUT, $result['pages'] ? 'Updated: ' . implode(', ', $result['pages']) . "\n" : "All pages already match data/content.json.\n");
exit($result['errors'] ? 1 : 0);
