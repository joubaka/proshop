<?php

// Run ONLY via the loopback PHP server, never the deployed web entry point.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403); exit;
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0")) {
    http_response_code(400); exit;
}
$project = dirname(__DIR__, 2);
$isUpload = str_starts_with($path, '/uploads/');
$static = ($isUpload ? $project.'/.local-acceptance/public' : $project.'/public').$path;
// Opt-in build preview only affects this isolated local portal.
if (!$isUpload && is_file($project.'/.local-acceptance/frontend'.$path)) {
    $static = $project.'/.local-acceptance/frontend'.$path;
}
if (is_file($static) && preg_match('/\.(css|js|png|jpe?g|gif|svg|ico|woff2?|ttf|map|webmanifest)$/i', $path)) {
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'webmanifest' => 'application/manifest+json'];
    header('Content-Type: '.($types[strtolower(pathinfo($static, PATHINFO_EXTENSION))] ?? mime_content_type($static)));
    header('X-Content-Type-Options: nosniff');
    readfile($static); exit;
}
if ($isUpload) { http_response_code(404); exit; }
$app = require __DIR__.'/bootstrap.php';
if ($path === '/__local_acceptance_health') {
    header('Content-Type: application/json');
    echo json_encode(['environment' => 'local-acceptance', 'database' => 'proshop_acceptance',
        'migrations' => Illuminate\Support\Facades\DB::table('migrations')->count(),
        'hardware_commissioning' => (bool) config('lights.control.live_enabled'),
        'customer_hardware' => (bool) config('lights.control.customer_enabled')]);
    exit;
}
$request = Illuminate\Http\Request::capture();
// Do not run live gateway, backup, installer or external-integration operations here.
if (preg_match('#^/(backup|install|api/ecom)(/|$)#', $path)) {
    http_response_code(403); echo 'Disabled in the local acceptance sandbox.'; exit;
}
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$response->headers->set('X-Proshop-Environment', 'local-acceptance');
$response->send();
$kernel->terminate($request, $response);
