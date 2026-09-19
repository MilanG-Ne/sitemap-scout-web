<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

require dirname(__DIR__) . '/backend/src/bootstrap.php';

try {
    foreach (['curl','dom','filter','json','zlib'] as $extension) {
        if (!extension_loaded($extension)) throw new RuntimeException();
    }
    $file = dirname(__DIR__) . '/config.php';
    if (!is_file($file)) throw new RuntimeException();
    $config = require $file;
    if (!is_array($config) || !is_string($config['origin'] ?? null) || !($config['enabled'] ?? false)) throw new RuntimeException();
    $api = new ScoutWeb\Api(new ScoutWeb\JobStore($config['storage'] ?? dirname(__DIR__) . '/var'), new ScoutWeb\ScanEngine(new ScoutWeb\SafeClient()), $config['origin']);
    $headers = [];
    foreach (getallheaders() as $key => $value) $headers[strtolower($key)] = $value;
    $body = file_get_contents('php://input', false, null, 0, 4097);
    [$status, $result] = $api->handle($_SERVER['REQUEST_METHOD'], is_string($_GET['action'] ?? null) ? $_GET['action'] : '', $headers, $body ?: '', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
} catch (Throwable) {
    $status = 503;
    $result = ['ready' => false, 'error' => 'Live scans are temporarily unavailable. You can still explore the example report.'];
}
http_response_code($status);
if ($status === 429) header('Retry-After: 60');
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
