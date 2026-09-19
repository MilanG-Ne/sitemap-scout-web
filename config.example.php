<?php
// Copy to config.php outside the public document root. Never expose that directory over HTTP.
return [
    'origin' => 'https://scout.ivig.dev',
    'storage' => __DIR__ . '/var',
    'enabled' => true,
];
