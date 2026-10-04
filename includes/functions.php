<?php
/**
 * AutoCare Cloud - Shared helper functions.
 * Include this at the top of every page.
 */

declare(strict_types=1);

// ---- Session (used for flash messages and CSRF tokens) ----
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

// ---- App-wide settings ----
date_default_timezone_set('Asia/Kolkata');

define('APP_NAME', 'AutoCare Cloud');
define('BASE_URL', '/autocare-cloud');      // folder name under /var/www/html
define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/config/database.php';

// ---- Output escaping (protects against XSS) ----
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---- URLs and redirects ----
function url(string $path = ''): string
{
    return BASE_URL . $path;
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

// ---- Flash messages (one-time messages shown after an action) ----
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function render_flash(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }

    $allowed = ['success', 'danger', 'warning', 'info'];
    $html = '';

    foreach ($_SESSION['flash'] as $flash) {
        $type = in_array($flash['type'], $allowed, true) ? $flash['type'] : 'info';
        $html .= '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert" data-autohide="true">'
               . e($flash['message'])
               . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
               . '</div>';
    }

    unset($_SESSION['flash']);
    return $html;
}

// ---- CSRF protection (blocks forged form submissions) ----
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && hash_equals($_SESSION['csrf_token'] ?? '', $sent);
}

// ---- Formatting ----

/** Formats a number as Indian Rupees, e.g. 1234567.5 -> "Rs 12,34,567.50" with the rupee symbol. */
function format_money($amount): string
{
    $amount   = (float)$amount;
    $negative = $amount < 0;
    $amount   = abs($amount);

    [$int, $dec] = explode('.', number_format($amount, 2, '.', ''));

    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest  = substr($int, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int   = $rest . ',' . $last3;
    }

    return ($negative ? '-' : '') . "\u{20B9}" . $int . ($dec === '00' ? '' : '.' . $dec);
}

/** Formats a date like "20 Sep 2026". Returns "-" for empty dates. */
function format_date(?string $date): string
{
    if ($date === null || $date === '' || $date === '0000-00-00') {
        return '-';
    }
    $time = strtotime($date);
    return $time === false ? '-' : date('d M Y', $time);
}

/** Formats a number with Indian-style grouping and no decimals, e.g. 32450 -> "32,450". */
function format_number($number): string
{
    $int = (string)(int)$number;
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3));
        return $rest . ',' . $last3;
    }
    return $int;
}

// ---- Phase 6 additions ----

/** Returns a positive integer ID, or null if the value is not a valid ID. */
function valid_id($value): ?int
{
    if (is_int($value)) {
        $id = $value;
    } elseif (is_string($value) && ctype_digit($value) && strlen($value) <= 10) {
        $id = (int)$value;
    } else {
        return null;
    }
    return ($id > 0 && $id <= 4294967295) ? $id : null;
}

/**
 * Works out the status of an expiry date.
 * Returns: label (Valid / Expiring Soon / Expired / No date),
 *          class (Bootstrap colour name), days (int or null), text (readable).
 */
function expiry_status(?string $date, int $soonDays = 30): array
{
    $none = ['label' => 'No date', 'class' => 'secondary', 'days' => null, 'text' => 'No expiry date'];

    if ($date === null || $date === '') {
        return $none;
    }

    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($expiry === false) {
        return $none;
    }

    $today = new DateTimeImmutable('today');
    $days  = (int)$today->diff($expiry)->format('%r%a');

    if ($days < 0) {
        return ['label' => 'Expired', 'class' => 'danger', 'days' => $days,
                'text' => abs($days) . ' day(s) ago'];
    }
    if ($days <= $soonDays) {
        return ['label' => 'Expiring Soon', 'class' => 'warning', 'days' => $days,
                'text' => $days === 0 ? 'Expires today' : $days . ' day(s) remaining'];
    }
    return ['label' => 'Valid', 'class' => 'success', 'days' => $days,
            'text' => $days . ' day(s) remaining'];
}

/** Shows a friendly error page (inside the normal layout) and stops. */
function render_error_page(string $title, string $message, int $code = 400,
                           string $backUrl = '/vehicles/', string $active = 'vehicles'): void
{
    http_response_code($code);
    $pageTitle  = $title;
    $activePage = $active;
    require ROOT_PATH . '/includes/header.php';
    echo '<div class="page-header mb-4"><h1>' . e($title) . '</h1></div>';
    echo '<div class="alert alert-danger">' . e($message) . '</div>';
    echo '<a href="' . e(url($backUrl)) . '" class="btn btn-primary"><i class="bi bi-arrow-left me-1"></i>Go back</a>';
    require ROOT_PATH . '/includes/footer.php';
    exit;
}

/** Returns a database connection, or shows a friendly error page and stops. */
function require_db(string $active = ''): mysqli
{
    $db = try_db();
    if ($db === null) {
        render_error_page('Service unavailable',
            'The application could not connect to the database. Please try again later.',
            503, '/dashboard.php', $active);
    }
    return $db;
}
