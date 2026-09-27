<?php

/**
 * cGaugePoller - Hintergrund-Poller fuer das Dashboard.
 *
 * Ruft sysinfo in festen Takten auf (gauge.tiers in config.json) und schreibt
 * das Ergebnis als Snapshot nach db/. Die Weboberflaeche liest nur diese
 * Datei: ein Seitenaufruf loest nie selbst eine Messung aus, und beliebig
 * viele Kiosk-Bildschirme sehen dieselben Werte mit derselben Abtastrate.
 *
 * Gestartet wird ueber poller.sh (start|stop|restart|status|foreground).
 */
class cGaugePoller {

    public ?string $lastError = null;

    // sysinfo-Abschnitt -> Schluessel im Ergebnis von cSysinfo::normalize().
    private const SECTION_KEYS = [
        'system' => 'system',
        'cpu'    => 'cpu',
        'temp'   => 'temperatures',
        'mem'    => 'memory',
        'disk'   => 'filesystems',
        'gpu'    => 'gpus',
        'diskio' => 'disk_io',
        'net'    => 'network',
        'wan'    => 'wan',
        'smart'  => 'smart',
    ];

    // Abschnitte, deren Werte Raten aus zwei Zaehlerstaenden sind.
    private const RATE_SECTIONS = ['cpu', 'diskio', 'net'];

    // Abschnitte, die unberechenbar lange brauchen koennen. Ihr Takt laeuft als
    // eigener Prozess, damit die CPU trotzdem im Takt weitermisst: wan wartet auf
    // einen fremden Server, nvidia-smi ohne Persistence Mode laedt bei jedem
    // Aufruf den Treiber neu und braucht dann gern eine Sekunde und mehr,
    // smartctl wartet je nach Controller mehrere Sekunden auf die Laufwerke.
    private const BACKGROUND_SECTIONS = ['wan', 'gpu', 'smart'];

    private bool    $stop       = false;
    private array   $cfg        = [];
    private array   $tiers      = [];
    private array   $data       = [];
    private array   $history    = [];
    private float   $started    = 0.0;
    private ?string $lastLogged = null;

    public function __construct() {
        // Absichtlich leer: autoload.php legt die Klasse auch bei jedem Web-Aufruf an.
    }

    /** Hauptschleife - kehrt erst nach SIGTERM/SIGINT zurueck. */
    public function run(): bool {
        if (PHP_SAPI !== 'cli') {
            return $this->fail('cGaugePoller: run() laeuft nur auf der Kommandozeile');
        }

        $this->cfg = defined('CONFIG') ? (CONFIG['gauge'] ?? []) : [];
        if (!$this->loadTiers()) return false;

        $snapshot = trim((string) ($this->cfg['snapshot'] ?? ''));
        if ($snapshot === '') {
            return $this->fail('cGaugePoller: gauge.snapshot fehlt in config.json');
        }

        $sysinfo = $GLOBALS['cSysinfo'] ?? new cSysinfo();

        // Laeuft meist als root und kann /usr/bin/sysinfo daher auch dann
        // nachziehen, wenn der Webserver es nicht darf.
        $installed = $sysinfo->install();
        if ($installed === 'installed' || $installed === 'updated') {
            $this->log("sysinfo nach gauge.install.target kopiert ($installed)");
        } elseif ($installed === 'failed') {
            $this->log((string) $sysinfo->lastError);
        }

        $script  = $sysinfo->script();
        if ($script === null) {
            return $this->fail((string) $sysinfo->lastError);
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $halt = function (): void { $this->stop = true; };
            pcntl_signal(SIGTERM, $halt);
            pcntl_signal(SIGINT, $halt);
        }

        $this->started = microtime(true);
        $this->log(sprintf(
            'gestartet (PID %d), sysinfo %s, Takte: %s',
            getmypid(),
            $script,
            implode(', ', array_map(static fn(string $n, array $t): string => "$n {$t['interval_ms']} ms",
                                    array_keys($this->tiers), $this->tiers))
        ));

        // Vorlauf: Zaehlerstaende aus einem frueheren Lauf koennen Stunden alt sein.
        // Ein Aufruf vorab ueberschreibt sie, damit schon der erste Takt nur seine
        // eigene Zeitspanne mittelt.
        $rate = array_values(array_intersect(self::RATE_SECTIONS, $this->allSections()));
        if ($rate !== []) {
            $sysinfo->raw($rate);
        }

        $next = array_fill_keys(array_keys($this->tiers), hrtime(true));
        $jobs = [];

        while (!$this->stop) {
            if ($this->reapJobs($sysinfo, $jobs)) {
                $this->writeSnapshot($snapshot);
            }

            $now = hrtime(true);
            $due = array_keys(array_filter($next, static fn(int $t): bool => $t <= $now));

            if ($due === []) {
                $this->waitNs($sysinfo, $jobs, min($next) - $now);
                continue;
            }

            $local = [];
            foreach ($due as $name) {
                if (!$this->tiers[$name]['background']) {
                    $local[] = $name;
                    continue;
                }
                $this->launchJob($sysinfo, $jobs, $name, $next[$name]);
                $this->advance($name, $next[$name], hrtime(true));
            }
            if ($local === []) {
                continue;
            }
            $due = $local;

            // Faellige Takte teilen sich einen Aufruf. Da alle Takte am selben
            // Startpunkt haengen, fallen z. B. 500 ms und 1 s exakt zusammen.
            $sections = [];
            foreach ($due as $name) {
                $sections = array_merge($sections, $this->tiers[$name]['sections']);
            }

            // Zaehler zuerst: sysinfo arbeitet die Abschnitte der Reihe nach ab.
            // Stuenden df oder nvidia-smi davor, laese es die Zaehler je nach
            // Zusammensetzung des Aufrufs mal 10, mal 80 ms spaeter - und jede
            // Rate haette eine andere Zeitbasis.
            $sections = array_values(array_unique($sections));
            usort($sections, static fn(string $a, string $b): int =>
                (int) !in_array($a, self::RATE_SECTIONS, true) <=> (int) !in_array($b, self::RATE_SECTIONS, true));

            // Zeitstempel vor dem Aufruf: die Zaehler liest sysinfo gleich zu Beginn,
            // danach dauert der Aufruf je nach Abschnitten unterschiedlich lang.
            $ts        = microtime(true);
            $callStart = hrtime(true);
            $result    = $sysinfo->read($sections);
            $callEnd   = hrtime(true);

            if ($result === null) {
                $this->logOnce('Messung fehlgeschlagen: ' . $sysinfo->lastError);
            } else {
                $this->lastLogged = null;
            }

            foreach ($due as $name) {
                $this->finishTier($name, $result, $sysinfo->lastError, $ts, $next[$name], $callStart, $callEnd);
                $this->advance($name, $next[$name], $callEnd);
            }

            $this->writeSnapshot($snapshot);
        }

        // Ein noch wartendes curl soll den Poller nicht ueberleben.
        foreach ($jobs as $job) {
            $sysinfo->finish($job);
        }

        $this->log('beendet');
        return true;
    }

    // -----------------------------------------------------------------------
    // Innenleben
    // -----------------------------------------------------------------------

    private function loadTiers(): bool {
        $this->tiers = [];

        foreach ((array) ($this->cfg['tiers'] ?? []) as $name => $tier) {
            $sections = array_values((array) ($tier['sections'] ?? []));
            $unknown  = array_diff($sections, array_keys(self::SECTION_KEYS));
            $interval = (int) ($tier['interval_ms'] ?? 0);

            if ($sections === [] || $unknown !== []) {
                return $this->fail("cGaugePoller: Takt '$name' hat ungueltige sections: " . implode(', ', $unknown ?: ['(leer)']));
            }
            if ($interval < 100) {
                return $this->fail("cGaugePoller: Takt '$name' braucht interval_ms >= 100");
            }

            // Ein Hintergrund-Takt nimmt alle seine Abschnitte mit in den eigenen
            // Prozess. Fuer temp o. ae. ist das egal - aber ein zweiter Prozess mit
            // cpu oder net wuerde neben dem Haupttakt dieselben Zaehlerstaende fortschreiben.
            $background = array_intersect($sections, self::BACKGROUND_SECTIONS) !== [];
            $rates      = array_intersect($sections, self::RATE_SECTIONS);
            if ($background && $rates !== []) {
                return $this->fail("cGaugePoller: Takt '$name' mischt " . implode(', ', array_intersect($sections, self::BACKGROUND_SECTIONS))
                    . ' mit ' . implode(', ', $rates) . ' - bitte in einen eigenen Takt');
            }

            $this->tiers[(string) $name] = [
                'label'       => (string) ($tier['label'] ?? $name),
                'interval_ms' => $interval,
                'interval_ns' => $interval * 1_000_000,
                'sections'    => $sections,
                'keys'        => array_map(static fn(string $s): string => self::SECTION_KEYS[$s], $sections),
                'history'     => max(0, (int) ($tier['history'] ?? 0)),
                'background'  => $background,
                'seq'         => 0,
                'ts'          => null,
                'duration_ms' => null,
                'jitter_ms'   => null,
                'missed'      => 0,
                'error'       => null,
            ];
        }

        if ($this->tiers === []) {
            return $this->fail('cGaugePoller: keine Takte unter gauge.tiers in config.json');
        }
        return true;
    }

    private function allSections(): array {
        return array_values(array_unique(array_merge(...array_column($this->tiers, 'sections'))));
    }

    /**
     * Naechsten Soll-Zeitpunkt setzen. Verpasste Takte auslassen statt nachholen:
     * nachgeholte Messungen kaemen dicht hintereinander und ihre Raten waeren
     * ueber Sekundenbruchteile gemittelt.
     */
    private function advance(string $name, int &$next, int $now): void {
        $interval = $this->tiers[$name]['interval_ns'];
        $next    += $interval;

        if ($next <= $now) {
            $skip = intdiv($now - $next, $interval) + 1;
            $this->tiers[$name]['missed'] += $skip;
            $next += $skip * $interval;
        }
    }

    private function launchJob(cSysinfo $sysinfo, array &$jobs, string $name, int $due): void {
        // Vorige Messung haengt noch (Server antwortet nicht): nicht stapeln.
        if (isset($jobs[$name])) {
            $this->tiers[$name]['missed']++;
            return;
        }

        $ts    = microtime(true);
        $start = hrtime(true);
        $job   = $sysinfo->start($this->tiers[$name]['sections']);

        if ($job === null) {
            $this->logOnce("Takt $name: " . $sysinfo->lastError);
            $this->finishTier($name, null, $sysinfo->lastError, $ts, $due, $start, hrtime(true));
            return;
        }

        $jobs[$name] = $job + ['ts' => $ts, 'due' => $due, 'started' => $start];
    }

    /** Fertige Hintergrund-Messungen abholen; true = es gibt Neues fuer den Snapshot. */
    private function reapJobs(cSysinfo $sysinfo, array &$jobs): bool {
        if ($jobs === []) return false;

        $sysinfo->pump($jobs);

        $changed = false;
        foreach ($jobs as $name => $job) {
            if (!$job['done']) continue;

            $result = $sysinfo->finish($job);
            if ($result !== null) {
                $result = $sysinfo->normalize($result);
            } else {
                $this->logOnce("Takt $name: " . $sysinfo->lastError);
            }
            $this->finishTier($name, $result, $sysinfo->lastError, $job['ts'], $job['due'], $job['started'], hrtime(true));
            unset($jobs[$name]);
            $changed = true;
        }
        return $changed;
    }

    /** Bis zum naechsten Takt warten - oder frueher, sobald eine Hintergrund-Messung fertig ist. */
    private function waitNs(cSysinfo $sysinfo, array &$jobs, int $ns): void {
        if ($jobs === []) {
            $this->sleepNs($ns);
            return;
        }
        $sysinfo->pump($jobs, max(0, $ns) / 1e9);
    }

    private function finishTier(string $name, ?array $result, ?string $error, float $ts, int $due, int $callStart, int $callEnd): void {
        $tier = &$this->tiers[$name];

        // Jitter = wie spaet die Messung gegenueber ihrem Soll-Zeitpunkt begann.
        $tier['jitter_ms']   = round(($callStart - $due) / 1e6, 2);
        $tier['duration_ms'] = round(($callEnd - $callStart) / 1e6, 1);

        if ($result === null) {
            $tier['error'] = $error;
            return;
        }

        $tier['seq']++;
        $tier['ts']    = round($ts, 3);
        $tier['error'] = null;

        $point = [];
        foreach ($tier['keys'] as $key) {
            if (!array_key_exists($key, $result)) continue;
            $this->data[$key] = $this->filter($key, $result[$key]);
            $point += $this->historyPoint($key, $this->data[$key]);
        }

        if ($tier['history'] > 0 && $point !== []) {
            $this->pushHistory($name, round($ts, 3), $point, $tier['history']);
        }
    }

    /** Schnittstellen und Mountpunkte ausblenden, die auf dem Kiosk nur stoeren. */
    private function filter(string $key, mixed $value): mixed {
        if ($key === 'network') {
            $exclude  = (array) ($this->cfg['network']['exclude'] ?? ['lo']);
            $hideDown = (bool) ($this->cfg['network']['hide_down'] ?? true);

            return array_values(array_filter($value, function (array $if) use ($exclude, $hideDown): bool {
                if ($hideDown && $if['state'] === 'down') return false;
                return !$this->matches($if['interface'], $exclude);
            }));
        }

        if ($key === 'filesystems') {
            $exclude = (array) ($this->cfg['storage']['exclude_mounts'] ?? []);
            return array_values(array_filter($value, fn(array $fs): bool => !$this->matches($fs['mount'], $exclude)));
        }

        if ($key === 'smart') {
            return $this->filterSmart($value);
        }

        return $value;
    }

    /**
     * Ein Laufwerk im Standby weckt sysinfo absichtlich nicht auf und liefert
     * dann keine Werte. Statt die Kachel leer zu raeumen, bleiben die Werte der
     * letzten Messung stehen - SMART-Zaehler aendern sich im Schlaf ohnehin nicht.
     */
    private function filterSmart(array $smart): array {
        $exclude  = (array) ($this->cfg['smart']['exclude'] ?? []);
        $previous = [];
        foreach ((array) ($this->data['smart']['devices'] ?? []) as $dev) {
            $previous[$dev['device']] = $dev;
        }

        $devices = [];
        foreach ($smart['devices'] as $dev) {
            if ($this->matches($dev['device'], $exclude)) continue;

            $old = $previous[$dev['device']] ?? null;
            if ($dev['state'] === 'standby' && $old !== null && $old['power_on_hours'] !== null) {
                $dev = ['state' => 'standby', 'error' => null] + $old;
            }
            $devices[] = $dev;
        }
        $smart['devices'] = $devices;
        return $smart;
    }

    private function matches(string $name, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (fnmatch((string) $pattern, $name)) return true;
        }
        return false;
    }

    /** Welche Werte eines Abschnitts in den Verlauf fuer die Diagramme gehen. */
    private function historyPoint(string $key, mixed $value): array {
        $point = [];

        switch ($key) {
            case 'cpu':
                $point['total'] = $value['total'];
                break;
            case 'memory':
                $point['used'] = $value['used'];
                break;
            case 'network':
                foreach ($value as $if) {
                    $point['rx:' . $if['interface']] = $if['rx_bps'];
                    $point['tx:' . $if['interface']] = $if['tx_bps'];
                }
                break;
            case 'disk_io':
                foreach ($value as $dev) {
                    $point['read:' . $dev['device']]   = $dev['read_bps'];
                    $point['write:' . $dev['device']]  = $dev['write_bps'];
                    $point['active:' . $dev['device']] = $dev['active_percent'];
                }
                break;
            case 'gpus':
                foreach ($value as $gpu) {
                    $point['util:' . $gpu['id']] = $gpu['percent'];
                    $point['mem:' . $gpu['id']]  = $gpu['memory_percent'] ?? null;
                }
                break;
            case 'wan':
                $point['latency'] = $value['latency_ms'];
                break;
        }

        return $point;
    }

    /**
     * Ringpuffer als Spalten: ein Zeitstempel-Array und je Serie ein Werte-Array
     * gleicher Laenge. Taucht eine Serie neu auf (Schnittstelle angesteckt),
     * wird sie vorne mit null aufgefuellt, damit die Indizes zusammenpassen.
     */
    private function pushHistory(string $name, float $ts, array $point, int $max): void {
        $h   = $this->history[$name] ?? ['t' => [], 'series' => []];
        $len = count($h['t']);

        $h['t'][] = $ts;

        foreach (array_keys($point) as $key) {
            if (!isset($h['series'][$key])) {
                $h['series'][$key] = array_fill(0, $len, null);
            }
        }
        foreach ($h['series'] as $key => $values) {
            $values[] = $point[$key] ?? null;
            $h['series'][$key] = $values;
        }

        $cut = count($h['t']) - $max;
        if ($cut > 0) {
            $h['t'] = array_slice($h['t'], $cut);
            foreach ($h['series'] as $key => $values) {
                $values = array_slice($values, $cut);
                // Serie ohne einen einzigen Wert mehr (Schnittstelle weg): entfernen.
                if (array_filter($values, static fn($v): bool => $v !== null) === []) {
                    unset($h['series'][$key]);
                } else {
                    $h['series'][$key] = $values;
                }
            }
        }

        $this->history[$name] = $h;
    }

    private function writeSnapshot(string $file): void {
        $tiers = [];
        foreach ($this->tiers as $name => $tier) {
            unset($tier['interval_ns']);
            $tiers[$name] = $tier;
        }

        $json = json_encode([
            'version'   => 1,
            'generated' => round(microtime(true), 3),
            'poller'    => [
                'pid'     => getmypid(),
                'started' => round($this->started, 3),
            ],
            'tiers'   => $tiers,
            'data'    => $this->data,
            'history' => $this->history,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            $this->logOnce('Snapshot nicht kodierbar: ' . json_last_error_msg());
            return;
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // tmp + rename: der Webserver liest nie eine halb geschriebene Datei.
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
            $this->logOnce("Snapshot nicht schreibbar: $file");
        }
    }

    private function sleepNs(int $ns): void {
        if ($ns <= 0) return;
        // Unterbricht ein Signal den Schlaf, prueft die Schleife ohnehin $stop.
        @time_nanosleep(intdiv($ns, 1_000_000_000), $ns % 1_000_000_000);
    }

    private function log(string $message): void {
        $cLog = $GLOBALS['cLog'] ?? null;
        if ($cLog instanceof cLog) {
            $cLog->log('poller.log', 'cGaugePoller: ' . $message);
        }
    }

    // Ein haengendes sysinfo wuerde sonst zweimal pro Sekunde dieselbe Zeile loggen.
    private function logOnce(string $message): void {
        if ($message === $this->lastLogged) return;
        $this->lastLogged = $message;
        $this->log($message);
    }

    private function fail(string $message): false {
        $this->lastError = $message;
        $this->log($message);
        return false;
    }
}
