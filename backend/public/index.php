<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Db;
use App\Tide;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];
$db = Db::conn();

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input') ?: '{}';
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

if ($uri === '/api/health' && $method === 'GET') {
    json_out(['ok' => true]);
}

if ($uri === '/api/stations' && $method === 'GET') {
    json_out(['items' => Tide::listStations($db)]);
}

if (preg_match('#^/api/stations/([a-z0-9\-]+)$#', $uri, $m) && $method === 'GET') {
    $row = Tide::getStation($db, $m[1]);
    $row ? json_out($row) : json_out(['error' => 'station not found'], 404);
}

if (preg_match('#^/api/stations/([a-z0-9\-]+)/constituents$#', $uri, $m) && $method === 'GET') {
    $items = Tide::listConstituents($db, $m[1]);
    $items === null ? json_out(['error' => 'station not found'], 404) : json_out(['items' => $items]);
}

if (preg_match('#^/api/stations/([a-z0-9\-]+)/constituents$#', $uri, $m) && $method === 'PUT') {
    $b = body();
    try {
        json_out(Tide::saveConstituents($db, $m[1], $b['items'] ?? []));
    } catch (InvalidArgumentException $e) {
        json_out(['error' => $e->getMessage()], 400);
    }
}

if (preg_match('#^/api/stations/([a-z0-9\-]+)/forecast$#', $uri, $m) && $method === 'GET') {
    try {
        [$hours, $step] = Tide::validateWindow($_GET['hours'] ?? 48, $_GET['step_min'] ?? 30);
    } catch (InvalidArgumentException $e) {
        json_out(['error' => $e->getMessage()], 400);
    }
    $row = Tide::forecast($db, $m[1], $hours, $step);
    $row === null ? json_out(['error' => 'station not found'], 404) : json_out($row);
}

if ($uri === '/api/forecast-diff' && $method === 'GET') {
    $slugA = (string)($_GET['station_a'] ?? '');
    $slugB = (string)($_GET['station_b'] ?? '');
    try {
        [$hours, $step] = Tide::validateWindow($_GET['hours'] ?? 48, $_GET['step_min'] ?? 30);
        $row = Tide::diffForecast($db, $slugA, $slugB, $hours, $step);
    } catch (InvalidArgumentException $e) {
        json_out(['error' => $e->getMessage()], 400);
    }
    $row === null ? json_out(['error' => 'station not found'], 404) : json_out($row);
}

if (preg_match('#^/api/stations/([a-z0-9\-]+)/residuals$#', $uri, $m) && $method === 'GET') {
    $row = Tide::residuals($db, $m[1]);
    $row === null ? json_out(['error' => 'station not found'], 404) : json_out($row);
}

if ($uri === '/api/settings' && $method === 'GET') {
    json_out(Tide::settings($db));
}

if ($uri === '/api/settings' && $method === 'PUT') {
    try {
        json_out(Tide::saveSettings($db, body()));
    } catch (InvalidArgumentException $e) {
        json_out(['error' => $e->getMessage()], 400);
    }
}

json_out(['error' => 'not found'], 404);
