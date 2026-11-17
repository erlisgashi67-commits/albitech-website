<?php
/*
 * Set the admin password. Run on your computer, in the website folder:
 *
 *   php admin/set-password.php
 *
 * It writes data/admin-credentials.php (a hash, never the password itself).
 * Upload that file to the data folder on the server.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib.php';

function ask(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && stream_isatty(STDIN);
    if ($hide) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($hide) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return rtrim((string) $line, "\r\n");
}

$new = ask('New admin password (at least 10 characters): ');
$confirm = ask('Type it again: ');
$problem = password_problem($new, $confirm);
if ($problem !== null) {
    fwrite(STDERR, $problem . "\n");
    exit(1);
}
if (!set_password($new)) {
    fwrite(STDERR, "Could not write data/admin-credentials.php.\n");
    exit(1);
}
fwrite(STDOUT, "Saved to data/admin-credentials.php. Upload it to the data folder on the server.\n");
