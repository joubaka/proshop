<?php

// Dedicated local sandbox. Never loads the shop .env or its compiled configuration.
require_once dirname(__DIR__, 2).'/vendor/autoload.php';
$project = dirname(__DIR__, 2);
$runtime = $project.'/.local-acceptance';
foreach (['cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'public/uploads', 'mysql'] as $directory) {
    if (!is_dir($runtime.'/'.$directory)) {
        mkdir($runtime.'/'.$directory, 0755, true);
    }
}
$environment = [
    'APP_ENV' => 'acceptance', 'APP_DEBUG' => 'true', 'APP_URL' => 'http://127.0.0.1:8097',
    'APP_KEY' => 'base64:'.base64_encode(hash('sha256', 'proshop-local-acceptance-only', true)),
    'APP_CONFIG_CACHE' => '.local-acceptance/cache/config.php', 'APP_ROUTES_CACHE' => '.local-acceptance/cache/routes.php',
    'APP_PACKAGES_CACHE' => '.local-acceptance/cache/packages.php', 'APP_SERVICES_CACHE' => '.local-acceptance/cache/services.php',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '13317',
    'DB_DATABASE' => 'proshop_acceptance', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
    'DB_SOCKET' => '', 'DATABASE_URL' => '', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'file',
    'SESSION_COOKIE' => 'proshop_acceptance_session', 'SESSION_DOMAIN' => '', 'SESSION_SECURE_COOKIE' => 'false',
    'QUEUE_CONNECTION' => 'database', 'MAIL_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'single', 'WEB_INSTALLER_ENABLED' => 'false',
    'BROADCAST_CONNECTION' => 'log', 'BROADCAST_DRIVER' => 'log',
];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

// Verify server identity BEFORE creating databases, booting providers or migrating.
$server = new PDO('mysql:host=127.0.0.1;port=13317;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$actual = str_replace('\\', '/', (string) $server->query('SELECT @@datadir')->fetchColumn());
$expected = str_replace('\\', '/', realpath($runtime.'/mysql'));
if (strcasecmp(rtrim($actual, '/'), rtrim($expected, '/')) !== 0) {
    throw new RuntimeException('Refusing to use a MySQL server outside the dedicated acceptance data directory.');
}
if (PHP_SAPI === 'cli') {
    $server->exec('CREATE DATABASE IF NOT EXISTS proshop_acceptance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
unset($server);

$app = require $project.'/bootstrap/app.php';
$app->useEnvironmentPath($runtime);
$app->loadEnvironmentFrom('.env.never-load');
$app->useStoragePath($runtime.'/storage');
$app->usePublicPath($runtime.'/public');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('mail.default', 'array');
$app['config']->set('mail.driver', 'array');
$app['config']->set('mail.mailers.array', ['transport' => 'array']);
$app['config']->set('broadcasting.default', 'log');
$app['config']->set('filesystems.default', 'local');
$app['config']->set('filesystems.disks.local.root', $runtime.'/public/uploads');
$app['config']->set('session.files', $runtime.'/storage/framework/sessions');
// Separate Lights database on the already identity-verified disposable server.
$app['config']->set('database.connections.lights', array_merge($app['config']->get('database.connections.mysql'), [
    'database' => 'proshop_lights_acceptance', 'url' => null, 'strict' => true,
]));
$app['config']->set('lights.enabled', true);
$app['config']->set('lights.mode', 'simulation');
$app['config']->set('lights.shelly_setup', true);
$hardwareCommissioning = getenv('LIGHTS_HARDWARE_COMMISSIONING') === '1';
$customerHardware = $hardwareCommissioning && getenv('LIGHTS_CUSTOMER_HARDWARE_ACCEPTANCE') === '1';
$app['config']->set('lights.control.live_enabled', $hardwareCommissioning);
$app['config']->set('lights.control.local_approval_required', ! $customerHardware);
$app['config']->set('lights.control.customer_enabled', $customerHardware);
// External integrations are unavailable in this sandbox, including inherited machine credentials.
foreach (['stripe', 'paypal', 'paystack', 'razorpay', 'pesapal', 'vonage', 'pusher'] as $integration) {
    $app['config']->set($integration, []);
}
$app['config']->set('twilio.twilio', ['default' => 'local', 'connections' => ['local' => ['sid' => 'local-only', 'token' => 'local-only', 'from' => '']]]);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Notification::fake();
Illuminate\Support\Facades\Queue::fake();
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'proshop_acceptance') {
    throw new RuntimeException('Unexpected acceptance database.');
}
return $app;
