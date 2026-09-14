<?php
/**
 * Routentabelle des Projekts - wird von cRoute beim ersten Zugriff eingelesen
 * (Pfad steht in config.json unter route.file).
 *
 * Schluessel: "GET /pfad", "GET|POST /pfad" oder nur "/pfad" (alle Methoden).
 *             Platzhalter: {name}, {id:\d+}, * fuer den Rest des Pfads.
 * Wert:       Closure | "views/seite.php" | "verzeichnis/" | "redirect:/ziel"
 *             | "cKlasse@methode" | ['target' => ..., weitere Optionen]
 *
 * Relative Pfade gelten ab dem Projektverzeichnis. $cRoute ist in dieser Datei
 * bereits gesetzt: Pfeilfunktionen (fn() => ...) uebernehmen es von selbst,
 * klassische Closures brauchen use ($cRoute). In eingebundenen Dateien stehen
 * $cRoute, die Instanzen aus autoload.php ($cFile, $cNetwork ...) und die
 * Platzhalter als eigene Variablen bereit.
 */

return [

    // Kiosk-Dashboard
    'GET /'            => 'views/dashboard.php',

    // Snapshot des Pollers als JSON; ?since=<ts> liefert nur neue Verlaufspunkte
    'GET /api/metrics' => 'views/api_metrics.php',

    'GET /status'      => 'views/status.php',
];
