<?php
/**
 * Eckdaten als JSON - eingebunden von cRoute (siehe routing.php).
 *
 * @var cRoute   $cRoute
 * @var cNetwork $cNetwork
 */

$cRoute->json([
    'app'      => 'BaseFrameworkVC',
    'version'  => CONFIG['info']['version'] ?? null,
    'php'      => PHP_VERSION,
    'host'     => CONFIG['web']['host'] ?? null,
    'port'     => CONFIG['web']['port'] ?? null,
    'local_ip' => $cNetwork->localIp() ?: null,
    'time'     => date('c'),
]);
