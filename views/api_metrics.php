<?php
/**
 * Messwerte als JSON - liest nur den Snapshot von cGaugePoller, misst nie selbst.
 *
 * ?since=<zeitstempel> kuerzt die Verlaeufe auf Punkte nach diesem Zeitpunkt:
 * das Dashboard holt die volle Historie einmal und danach nur noch Neues,
 * statt alle 250 ms dieselben paar hundert Zahlen zu uebertragen.
 *
 * @var cRoute $cRoute
 */

$file = (string) (CONFIG['gauge']['snapshot'] ?? '');
$raw  = ($file !== '' && is_file($file)) ? @file_get_contents($file) : false;
$snap = $raw === false ? null : json_decode($raw, true);

$cRoute->send('Cache-Control', 'no-store');

if (!is_array($snap)) {
    $cRoute->json([
        'error' => 'Kein Snapshot vorhanden - laeuft der Poller? (./poller.sh start)',
        'now'   => microtime(true),
    ], 503);
    return;
}

$since = (float) $cRoute->query('since', 0);
if ($since > 0) {
    foreach ($snap['history'] ?? [] as $tier => $h) {
        $t     = $h['t'] ?? [];
        $first = count($t);
        while ($first > 0 && $t[$first - 1] > $since) {
            $first--;
        }

        $snap['history'][$tier]['t'] = array_slice($t, $first);
        foreach ($h['series'] ?? [] as $key => $values) {
            $snap['history'][$tier]['series'][$key] = array_slice($values, $first);
        }
    }
}

// /proc statt posix_kill(): laeuft der Webserver unter einem anderen Benutzer
// als der Poller, meldet kill() EPERM, obwohl der Prozess lebt.
$pid     = (int) ($snap['poller']['pid'] ?? 0);
$cmdline = $pid > 0 ? (string) @file_get_contents("/proc/$pid/cmdline") : '';
$snap['poller']['alive'] = str_contains($cmdline, 'cGaugePoller');

$snap['now'] = microtime(true);

$cRoute->status(200)->send('Content-Type', 'application/json; charset=utf-8');
echo json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
