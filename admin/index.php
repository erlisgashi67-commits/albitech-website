<?php
/*
 * Albitech admin panel: sign in, edit the site content, publish.
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

start_admin_session();

$notice = null;   // ['type' => 'success'|'error', 'text' => ..., 'list' => [...]]
$formInput = null; // posted values to show again after a validation error
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';

function redirect_with(array $notice): void
{
    $_SESSION['notice'] = $notice;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?'), true, 303);
    exit;
}

if ($action !== '' && !csrf_ok()) {
    $notice = ['type' => 'error', 'text' => 'Your session expired. Please try again.'];
    $action = '';
}

/* ---------- Sign in / out ---------- */

if ($action === 'login') {
    $wait = login_locked_for();
    $creds = credentials();
    if ($wait > 0) {
        $notice = ['type' => 'error', 'text' => 'Too many failed attempts. Try again in ' . (int) ceil($wait / 60) . ' minutes.'];
    } elseif ($creds === null) {
        $notice = ['type' => 'error', 'text' => 'No admin password has been set yet. See the setup steps below.'];
    } elseif (password_verify((string) ($_POST['password'] ?? ''), $creds['hash'])) {
        record_login(true);
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['last'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        if (password_needs_rehash($creds['hash'], PASSWORD_DEFAULT)) {
            set_password((string) $_POST['password']);
        }
        redirect_with(['type' => 'success', 'text' => 'Signed in.']);
    } else {
        record_login(false);
        usleep(400000);
        $notice = ['type' => 'error', 'text' => 'That password is not correct.'];
    }
}

if ($action === 'logout') {
    $_SESSION = [];
    session_regenerate_id(true);
    redirect_with(['type' => 'success', 'text' => 'Signed out.']);
}

/* ---------- Signed-in actions ---------- */

if ($action !== '' && $action !== 'login' && !is_signed_in()) {
    $notice = ['type' => 'error', 'text' => 'Please sign in first.'];
    $action = '';
}

if ($action === 'save') {
    [$content, $errors] = validate_content($_POST);
    if ($errors) {
        $notice = ['type' => 'error', 'text' => 'Nothing was saved. Please fix these first:', 'list' => $errors];
        $formInput = $content;
    } else {
        $result = save_and_publish($content);
        if ($result['errors']) {
            $notice = ['type' => 'error', 'text' => 'The content was saved, but some pages could not be updated:', 'list' => $result['errors']];
        } else {
            $pages = $result['pages'] ? implode(', ', $result['pages']) : 'no pages needed changes';
            redirect_with(['type' => 'success', 'text' => 'Saved and published. Updated: ' . $pages . '.']);
        }
    }
}

if ($action === 'restore') {
    $path = backup_path((string) ($_POST['file'] ?? ''));
    if ($path === null) {
        $notice = ['type' => 'error', 'text' => 'That saved version could not be found.'];
    } else {
        try {
            [$content, $errors] = validate_content(content_to_form(load_content($path)));
            if ($errors) {
                throw new RuntimeException(implode(' ', $errors));
            }
            $result = save_and_publish($content);
            if ($result['errors']) {
                $notice = ['type' => 'error', 'text' => 'Restored, but some pages could not be updated:', 'list' => $result['errors']];
            } else {
                redirect_with(['type' => 'success', 'text' => 'Restored the version from ' . backup_label((int) filemtime($path)) . ' and published it.']);
            }
        } catch (Throwable $e) {
            $notice = ['type' => 'error', 'text' => 'That version could not be restored: ' . $e->getMessage()];
        }
    }
}

if ($action === 'password') {
    $creds = credentials();
    $problem = password_problem((string) ($_POST['new'] ?? ''), (string) ($_POST['confirm'] ?? ''));
    if ($creds === null || !password_verify((string) ($_POST['current'] ?? ''), $creds['hash'])) {
        $notice = ['type' => 'error', 'text' => 'Your current password is not correct.'];
    } elseif ($problem !== null) {
        $notice = ['type' => 'error', 'text' => $problem];
    } elseif (!set_password((string) $_POST['new'])) {
        $notice = ['type' => 'error', 'text' => 'The new password could not be saved. Check that the data folder is writable.'];
    } else {
        redirect_with(['type' => 'success', 'text' => 'Password changed.']);
    }
}

if (is_signed_in() && ($_GET['download'] ?? '') === 'content') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="content.json"');
    readfile(CONTENT_FILE);
    exit;
}

if ($notice === null && !empty($_SESSION['notice'])) {
    $notice = $_SESSION['notice'];
}
unset($_SESSION['notice']);
if ($notice === null && !empty($_SESSION['expired'])) {
    $notice = ['type' => 'error', 'text' => 'You were signed out after two hours without activity.'];
}
unset($_SESSION['expired']);

/* ---------- Helpers for the page ---------- */

/** Content in the shape the form posts, so a backup can go through the same checks. */
function content_to_form(array $content): array
{
    $content['trackRecord']['countries'] = implode("\n", $content['trackRecord']['countries'] ?? []);
    return $content;
}

function backup_label(int $time): string
{
    return gmdate('j M Y, H:i', $time) . ' UTC';
}

$content = null;
$loadError = null;
if (is_signed_in()) {
    try {
        $content = $formInput ?? load_content();
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
    }
}
$token = h(csrf_token());
$asset = function (string $file): string {
    return h($file . '?v=' . @filemtime(__DIR__ . '/' . $file));
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Albitech admin</title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= $asset('admin.css') ?>">
  <script src="<?= $asset('admin.js') ?>" defer></script>
</head>
<body class="<?= is_signed_in() ? 'is-app' : 'is-login' ?>">

<?php if (!is_signed_in()): ?>
  <main class="login">
    <img class="login-logo" src="../assets/img/logo.png" alt="AlbiTech" width="220" height="44">
    <h1>Admin sign-in</h1>
    <?php render_notice($notice); ?>
    <?php if (credentials() === null): ?>
      <div class="notice notice--info">
        <p><strong>Set an admin password first.</strong> On your computer, in the website folder, run:</p>
        <pre>php admin/set-password.php</pre>
        <p>Then upload the file it creates, <code>data/admin-credentials.php</code>, to the same folder on the server.</p>
      </div>
    <?php else: ?>
      <form method="post" class="login-form">
        <input type="hidden" name="csrf" value="<?= $token ?>">
        <input type="hidden" name="action" value="login">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
        <button class="btn btn-primary" type="submit">Sign in</button>
      </form>
    <?php endif; ?>
    <p class="login-back"><a href="../index.html">Back to the website</a></p>
  </main>

<?php else: ?>
  <header class="topbar">
    <div class="topbar-inner">
      <a class="topbar-brand" href="index.php"><img src="../assets/img/logo.png" alt="" width="150" height="30"><span>Admin</span></a>
      <nav class="topbar-nav" aria-label="Admin">
        <a href="#numbers">Numbers</a>
        <a href="#record">Track record</a>
        <a href="#contact">Contact</a>
        <a href="#hours">Hours</a>
        <a href="#history">History</a>
      </nav>
      <div class="topbar-actions">
        <a class="btn btn-ghost btn-sm" href="../index.html" target="_blank" rel="noopener">View site</a>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $token ?>">
          <button class="btn btn-ghost btn-sm" type="submit" name="action" value="logout">Sign out</button>
        </form>
      </div>
    </div>
  </header>

  <main class="app">
    <?php render_notice($notice); ?>
    <noscript><div class="notice notice--error"><p>Turn on JavaScript to add, remove and reorder items.</p></div></noscript>

    <?php if ($loadError !== null): ?>
      <div class="notice notice--error"><p><?= h($loadError) ?></p></div>
    <?php else: ?>
    <form method="post" id="content-form" class="content-form" novalidate>
      <input type="hidden" name="csrf" value="<?= $token ?>">
      <input type="hidden" name="action" value="save">

      <!-- Numbers -->
      <section class="panel" id="numbers" aria-labelledby="numbers-title">
        <div class="panel-head">
          <h2 id="numbers-title">Numbers on the home page</h2>
          <p>The figures under the hero, such as devices tested and countries reached. They count up as visitors scroll to them. Use the unit for a short ending such as <b>M</b>, <b>M+</b>, <b>K</b> or <b>%</b>.</p>
        </div>
        <div class="rows" data-list="stats" data-min="1" data-max="<?= MAX_STATS ?>">
          <div class="row-head row--stat" aria-hidden="true"><span>Number</span><span>Unit</span><span>Label</span><span></span><span></span></div>
          <?php foreach ($content['stats'] as $i => $s): render_stat_row($i, $s); endforeach; ?>
        </div>
        <template data-template="stats"><?php render_stat_row('__i__', ['value' => '', 'suffix' => '', 'label' => '', 'isYear' => false]); ?></template>
        <button class="btn btn-outline btn-sm" type="button" data-add="stats">Add a number</button>
        <p class="hint" data-preview="stats" aria-live="polite"></p>
      </section>

      <!-- Track record -->
      <section class="panel" id="record" aria-labelledby="record-title">
        <div class="panel-head">
          <h2 id="record-title">Track record</h2>
          <p>The section beside the world map on the home page.</p>
        </div>
        <div class="field">
          <label for="tr-heading">Heading</label>
          <input id="tr-heading" name="trackRecord[heading]" value="<?= h($content['trackRecord']['heading']) ?>" maxlength="120" required>
        </div>
        <div class="field">
          <label for="tr-intro">Text</label>
          <textarea id="tr-intro" name="trackRecord[intro]" rows="3" maxlength="400"><?= h($content['trackRecord']['intro']) ?></textarea>
        </div>
        <div class="field">
          <label for="tr-countries">Countries shown as tags</label>
          <p class="field-hint" id="tr-countries-hint">One country per line. Leave empty to hide the tags. <span data-count-lines="tr-countries"></span></p>
          <textarea id="tr-countries" name="trackRecord[countries]" rows="8" aria-describedby="tr-countries-hint"><?= h(is_array($content['trackRecord']['countries']) ? implode("\n", $content['trackRecord']['countries']) : $content['trackRecord']['countries']) ?></textarea>
        </div>
      </section>

      <!-- Contact -->
      <section class="panel" id="contact" aria-labelledby="contact-title">
        <div class="panel-head">
          <h2 id="contact-title">Address and contact</h2>
          <p>Shown in the footer of every page, on the Contact page and in the details search engines read.</p>
        </div>
        <?php $c = $content['contact']; ?>
        <div class="grid">
          <?php
          contact_field('company', 'Building name', $c, 'span-2');
          contact_field('street', 'Street', $c, 'span-2');
          contact_field('number', 'Building number', $c);
          contact_field('postalCode', 'Postal code', $c);
          contact_field('town', 'Town', $c);
          contact_field('municipality', 'Municipality', $c);
          contact_field('country', 'Country', $c);
          contact_field('countryCode', 'Country code', $c, '', 'Two letters, XK for Kosovo.');
          contact_field('phone', 'Phone', $c, '', '', 'tel');
          contact_field('email', 'Email', $c, '', '', 'email');
          contact_field('mapUrl', 'Google Maps link', $c, 'span-2', 'Used by the "Open in Google Maps" button. Copy it from Share in Google Maps.', 'url');
          contact_field('latitude', 'Latitude', $c, '', 'Places the map on the Contact page. In Google Maps, right-click the building and click the numbers to copy them.');
          contact_field('longitude', 'Longitude', $c, '', 'The second of the two numbers.');
          contact_field('locationNote', 'Location note', $c, 'span-2', 'Shown under the map, for example: on the Pejë-Prishtinë highway.');
          ?>
        </div>
        <p class="hint">Footer address: <strong data-preview="address"></strong></p>
      </section>

      <!-- Hours -->
      <section class="panel" id="hours" aria-labelledby="hours-title">
        <div class="panel-head">
          <h2 id="hours-title">Business hours</h2>
          <p>The table on the Contact page. The first row is also shown in the footer.</p>
        </div>
        <div class="rows" data-list="hours" data-min="1" data-max="<?= MAX_HOURS ?>">
          <div class="row-head row--hours" aria-hidden="true"><span>Days</span><span>Hours</span><span></span></div>
          <?php foreach ($content['hours'] as $i => $row): render_hours_row($i, $row); endforeach; ?>
        </div>
        <template data-template="hours"><?php render_hours_row('__i__', ['days' => '', 'time' => '']); ?></template>
        <button class="btn btn-outline btn-sm" type="button" data-add="hours">Add a row</button>
      </section>

      <div class="savebar">
        <p class="savebar-status" data-dirty-status aria-live="polite">No unsaved changes.</p>
        <a class="btn btn-ghost btn-sm" href="?download=content">Download a copy</a>
        <button class="btn btn-primary" type="submit">Save and publish</button>
      </div>
    </form>
    <?php endif; ?>

    <!-- History -->
    <section class="panel" id="history" aria-labelledby="history-title">
      <div class="panel-head">
        <h2 id="history-title">History</h2>
        <p>Every save keeps a copy of what was there before (the last <?= MAX_BACKUPS ?>). Restore one to put the site back the way it was.</p>
      </div>
      <?php $backups = list_backups(); ?>
      <?php if (!$backups): ?>
        <p class="hint">No earlier versions yet. They appear here after the first save.</p>
      <?php else: ?>
        <ul class="history">
          <?php foreach ($backups as $b): ?>
            <li>
              <span>Before the save of <?= h(backup_label($b['time'])) ?></span>
              <form method="post" data-confirm="Restore this version and publish it? The current content is kept in the history.">
                <input type="hidden" name="csrf" value="<?= $token ?>">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="file" value="<?= h($b['file']) ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Restore</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <!-- Password -->
    <section class="panel" id="password" aria-labelledby="password-title">
      <div class="panel-head">
        <h2 id="password-title">Change password</h2>
      </div>
      <form method="post" class="grid">
        <input type="hidden" name="csrf" value="<?= $token ?>">
        <input type="hidden" name="action" value="password">
        <div class="field"><label for="pw-current">Current password</label><input id="pw-current" name="current" type="password" autocomplete="current-password" required></div>
        <div class="field"><label for="pw-new">New password</label><input id="pw-new" name="new" type="password" autocomplete="new-password" minlength="10" required></div>
        <div class="field"><label for="pw-confirm">New password again</label><input id="pw-confirm" name="confirm" type="password" autocomplete="new-password" minlength="10" required></div>
        <div class="field field--end"><button class="btn btn-outline" type="submit">Change password</button></div>
      </form>
    </section>
  </main>
<?php endif; ?>
</body>
</html>
<?php

/* ---------- Page fragments ---------- */

function render_notice(?array $notice): void
{
    if (!$notice) {
        return;
    }
    echo '<div class="notice notice--' . h($notice['type']) . '" role="' . ($notice['type'] === 'error' ? 'alert' : 'status') . '">';
    echo '<p>' . h($notice['text']) . '</p>';
    if (!empty($notice['list'])) {
        echo '<ul>';
        foreach ($notice['list'] as $item) {
            echo '<li>' . h($item) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

function row_buttons(string $what): string
{
    return '<span class="row-tools">'
        . '<button type="button" class="icon-btn" data-move="-1" aria-label="Move this ' . $what . ' up" title="Move up">&#8593;</button>'
        . '<button type="button" class="icon-btn" data-move="1" aria-label="Move this ' . $what . ' down" title="Move down">&#8595;</button>'
        . '<button type="button" class="icon-btn icon-btn--danger" data-remove aria-label="Remove this ' . $what . '" title="Remove">&#215;</button>'
        . '</span>';
}

function render_stat_row($i, array $s): void
{
    $n = 'stats[' . $i . ']';
    $id = 'stat-' . $i;
    echo '<div class="row row--stat" data-row>';
    echo '<span class="cell"><label class="cell-label" for="' . $id . '-value">Number</label><input id="' . $id . '-value" name="' . $n . '[value]" value="' . h($s['value']) . '" inputmode="decimal" maxlength="12" required></span>';
    echo '<span class="cell"><label class="cell-label" for="' . $id . '-suffix">Unit</label><input id="' . $id . '-suffix" name="' . $n . '[suffix]" value="' . h($s['suffix']) . '" maxlength="4"></span>';
    echo '<span class="cell"><label class="cell-label" for="' . $id . '-label">Label</label><input id="' . $id . '-label" name="' . $n . '[label]" value="' . h($s['label']) . '" maxlength="40" required></span>';
    echo '<span class="cell cell--check"><input id="' . $id . '-year" type="checkbox" name="' . $n . '[isYear]" value="1"' . (!empty($s['isYear']) ? ' checked' : '') . '><label for="' . $id . '-year">Year</label></span>';
    echo row_buttons('number');
    echo '</div>';
}

function render_hours_row($i, array $r): void
{
    $n = 'hours[' . $i . ']';
    $id = 'hours-' . $i;
    echo '<div class="row row--hours" data-row>';
    echo '<span class="cell"><label class="cell-label" for="' . $id . '-days">Days</label><input id="' . $id . '-days" name="' . $n . '[days]" value="' . h($r['days']) . '" maxlength="40" required></span>';
    echo '<span class="cell"><label class="cell-label" for="' . $id . '-time">Hours</label><input id="' . $id . '-time" name="' . $n . '[time]" value="' . h($r['time']) . '" maxlength="40" required></span>';
    echo row_buttons('row');
    echo '</div>';
}

function contact_field(string $key, string $label, array $c, string $class = '', string $hint = '', string $type = 'text'): void
{
    $id = 'contact-' . $key;
    echo '<div class="field ' . h($class) . '">';
    echo '<label for="' . $id . '">' . h($label) . '</label>';
    if ($hint !== '') {
        echo '<p class="field-hint" id="' . $id . '-hint">' . h($hint) . '</p>';
    }
    echo '<input id="' . $id . '" name="contact[' . h($key) . ']" type="' . h($type) . '" value="' . h($c[$key] ?? '') . '"'
        . ($hint !== '' ? ' aria-describedby="' . $id . '-hint"' : '') . ' data-contact="' . h($key) . '">';
    echo '</div>';
}
