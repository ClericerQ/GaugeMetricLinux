<?php
/**
 * Einstiegspunkt des Webservers. Die Routen selbst stehen in routing.php
 * (bzw. in config.json unter route.routes) - hier bleibt nur der Start.
 */

$dir = dirname(__DIR__) . '/';
require_once $dir . 'autoload.php';

// Vorhandene Dateien (Bilder, CSS ...) reicht der eingebaute PHP-Server selbst
// durch; 'return false' geht nur hier im Router-Skript, nicht in der Klasse.
if ($cRoute->isStatic()) {
    return false;
}

$cRoute->run();
