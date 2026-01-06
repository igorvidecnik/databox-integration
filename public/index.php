<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use App\Logging\LoggerFactory;
use App\Storage\SqliteStore;
use App\OAuth\StravaOAuth;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$logger = LoggerFactory::create();

$store = new SqliteStore(__DIR__ . '/../data/app.db');
$store->init();

$http = new Client(['timeout' => 20]);
$stravaOAuth = new StravaOAuth($http, $store, $logger, $_ENV);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

header('Content-Type: text/plain; charset=utf-8');

if ($path === '/') {
    echo "OK\n";
    echo "Try: /auth/strava\n";
    exit;
}

if ($path === '/auth/strava') {
    $state = bin2hex(random_bytes(16));
    $url = $stravaOAuth->buildAuthorizeUrl($state);
    header('Location: ' . $url, true, 302);
    exit;
}

if ($path === '/auth/strava/callback') {
    if (!empty($_GET['error'])) {
        http_response_code(400);
        echo "OAuth error: " . $_GET['error'] . "\n";
        exit;
    }

    $code = $_GET['code'] ?? null;
    if (!$code) {
        http_response_code(400);
        echo "Missing ?code\n";
        exit;
    }

    $stravaOAuth->exchangeCodeForToken($code);

    echo "Strava connected ✅\n";
    echo "Next: run `php bin/ingest`\n";
    exit;
}

http_response_code(404);
echo "Not Found\n";
