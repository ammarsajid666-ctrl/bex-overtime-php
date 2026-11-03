<?php
declare(strict_types=1);

/* Front controller. Every request goes through here: ?page=name */

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/views.php';
require dirname(__DIR__) . '/src/importer.php';
require dirname(__DIR__) . '/src/pages.php';
require dirname(__DIR__) . '/src/request_edit.php';
require dirname(__DIR__) . '/src/security.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cross-Origin-Opener-Policy: same-origin');
if (!empty($_SERVER['HTTPS'])) header('Strict-Transport-Security: max-age=31536000');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

$routes = [
    'login' => 'page_login',
    'logout' => 'page_logout',
    'home' => 'page_home',
    'file' => 'page_file',
    'approvals' => 'page_approvals',
    'decide' => 'page_decide',
    'export' => 'page_export',
    'admin' => 'page_admin',
    'edit' => 'page_edit',
    'toggle' => 'page_toggle',
    'import' => 'page_import',
    'template' => 'page_template',
    'request' => 'page_request_edit',
];

try {
    start_session();
    $page = (string)($_GET['page'] ?? '');

    if ($page === '') {
        $user = current_user();
        redirect($user ? home_for_role($user['role']) : url('login'));
    }
    if (!isset($routes[$page])) {
        http_response_code(404);
        render_error_page('Page not found', 'That page does not exist.');
        exit;
    }
    $routes[$page]();
} catch (Throwable $e) {
    error_log('[ibex-portal] ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    render_error_page('Something went wrong', 'Please try again. If it keeps happening, contact IT.');
}
