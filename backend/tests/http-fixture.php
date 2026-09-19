<?php
// Test-only HTTP fixture. Never included in a deployment package.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/redirect') {
    header('Location: /must-not-follow', true, 302);
    exit;
}
if ($path === '/large') {
    echo str_repeat('a', 300000);
    exit;
}
if ($path === '/compressed') {
    header('Content-Encoding: gzip');
    echo gzencode(str_repeat('b', 300000));
    exit;
}
header('Content-Type: text/html');
header('X-Robots-Tag: noindex');
echo '<head><title>' . htmlspecialchars($_SERVER['HTTP_HOST']) . '</title></head>';
