<?php

/**
 * cSysinfo - Messwerte ueber das Shell-Skript sysinfo (JSON-Modus).
 *
 * sysinfo liefert fuer Menschen formatierte Strings ("4.0Gi", "53.0°C",
 * "196.22 KB/s"); das Dashboard braucht Bytes, Prozent und Grad. normalize()
 * macht daraus Zahlen und kommt auch mit aelteren sysinfo-Versionen zurecht,
 * die nur die Strings kennen (z. B. ein altes /usr/bin/sysinfo).
 */
class cSysinfo {

    public ?string $lastError = null;

    // Mehr kennt sysinfo nicht - ein unbekannter Abschnitt bricht dort mit Code 2 ab.
    public const SECTIONS = ['system', 'cpu', 'temp', 'mem', 'disk', 'gpu', 'diskio', 'net', 'wan'];

    private array   $candidates;
    private ?string $stateDir;
    private int     $timeout;
    private ?string $wanUrl;
    private int     $wanTimeout;

    public function __construct(?array $options = null) {
        $cfg = $options ?? (defined('CONFIG') ? (CONFIG['gauge'] ?? []) : []);

        $this->candidates = array_values((array) ($cfg['sysinfo'] ?? ['/usr/bin/sysinfo']));

        $state = trim((string) ($cfg['state_dir'] ?? ''));
        $this->stateDir = $state === '' ? null : rtrim($state, '/');

        $this->timeout = max(1, (int) ($cfg['call_timeout'] ?? 5));

        $url = trim((string) ($cfg['wan']['url'] ?? ''));
        $this->wanUrl = $url === '' ? null : $url;
        // Unter call_timeout: sonst wuerde sysinfo abgeschossen, bevor curl
        // selbst "timeout" meldet, und der Takt stuende als Messfehler da.
        $this->wanTimeout = max(1, min((int) ($cfg['wan']['timeout'] ?? 3), $this->timeout - 1));
    }

    /** Erstes vorhandene sysinfo aus gauge.sysinfo. */
    public function script(): ?string {
        foreach ($this->candidates as $path) {
            if (is_string($path) && is_file($path) && is_readable($path)) {
                return $path;
            }
        }
        $this->lastError = 'cSysinfo: sysinfo nicht gefunden (' . implode(', ', $this->candidates) . ')';
        return null;
    }

    /** Messen und in Zahlen umwandeln; leere Liste = alle Abschnitte. */
    public function read(array $sections = []): ?array {
        $raw = $this->raw($sections);
        return $raw === null ? null : $this->normalize($raw);
    }

    /** JSON von sysinfo, so wie es kommt. */
    public function raw(array $sections = []): ?array {
        $job = $this->start($sections);
        if ($job === null) return null;

        $jobs = [$job];
        while (!$jobs[0]['done']) {
            $this->pump($jobs, $jobs[0]['deadline'] - microtime(true));
        }
        return $this->finish($jobs[0]);
    }

    /**
     * sysinfo starten, ohne auf das Ergebnis zu warten. Der Poller braucht das
     * fuer Abschnitte, die auf einen fremden Server warten: seine lokalen Takte
     * laufen weiter, bis pump() den Job als fertig meldet und finish() ihn abholt.
     */
    public function start(array $sections = []): ?array {
        $script = $this->script();
        if ($script === null) return null;

        $unknown = array_diff($sections, self::SECTIONS);
        if ($unknown !== []) {
            $this->lastError = 'cSysinfo: unbekannte Abschnitte: ' . implode(', ', $unknown);
            return null;
        }

        // Ueber bash statt direkt: ein kopiertes Skript hat oft kein x-Bit.
        $cmd = array_merge(['bash', $script, 'json'], array_values($sections));

        // LC_ALL=C: awk wuerde unter de_DE sonst "12,5" statt "12.5" schreiben.
        $env = [
            'PATH'   => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'LC_ALL' => 'C',
        ];
        if ($this->stateDir !== null) {
            $env['SYSINFO_STATE_DIR'] = $this->stateDir;
        }
        if ($this->wanUrl !== null) {
            $env['SYSINFO_WAN_URL'] = $this->wanUrl;
        }
        $env['SYSINFO_WAN_TIMEOUT'] = (string) $this->wanTimeout;

        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            $this->lastError = 'cSysinfo: sysinfo liess sich nicht starten';
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'proc'      => $proc,
            'pipes'     => $pipes,
            'deadline'  => microtime(true) + $this->timeout,
            'out'       => '',
            'err'       => '',
            'done'      => false,
            'timed_out' => false,
        ];
    }

    /**
     * Liest von allen laufenden Jobs, was anliegt, und wartet dabei hoechstens
     * $wait Sekunden. Der Poller schlaeft hier statt in time_nanosleep(): so
     * wacht er auf, sobald ein Hintergrund-Aufruf fertig ist.
     */
    public function pump(array &$jobs, float $wait = 0.0): void {
        $now  = microtime(true);
        $read = [];

        foreach ($jobs as &$job) {
            if ($job['done']) continue;
            if ($now >= $job['deadline']) {
                $job['done'] = $job['timed_out'] = true;
                continue;
            }
            $open = array_filter([$job['pipes'][1], $job['pipes'][2]], static fn($p): bool => !feof($p));
            if ($open === []) {
                $job['done'] = true;
                continue;
            }
            $wait = min($wait, $job['deadline'] - $now);
            array_push($read, ...$open);
        }
        unset($job);

        if ($read === []) return;

        $wait  = max(0.0, $wait);
        $write = $except = null;
        // false = von einem Signal unterbrochen (SIGTERM an den Poller) - der Aufrufer prueft ohnehin erneut.
        if (@stream_select($read, $write, $except, (int) $wait, (int) (fmod($wait, 1) * 1e6)) === false) {
            return;
        }

        foreach ($jobs as &$job) {
            if ($job['done']) continue;
            foreach ([1 => 'out', 2 => 'err'] as $fd => $buffer) {
                if (in_array($job['pipes'][$fd], $read, true)) {
                    $job[$buffer] .= (string) fread($job['pipes'][$fd], 65536);
                }
            }
            if (feof($job['pipes'][1]) && feof($job['pipes'][2])) {
                $job['done'] = true;
            }
        }
        unset($job);
    }

    /** Job abschliessen und sein JSON liefern; ein noch laufender Job wird beendet. */
    public function finish(array $job): ?array {
        $timedOut = $job['timed_out'] || !$job['done'];
        $out      = $job['out'];
        $err      = $job['err'];

        if ($timedOut) {
            proc_terminate($job['proc'], 9);
        }
        fclose($job['pipes'][1]);
        fclose($job['pipes'][2]);
        $code = proc_close($job['proc']);

        if ($timedOut) {
            $this->lastError = "cSysinfo: sysinfo nach {$this->timeout} s abgebrochen";
            return null;
        }

        // Aeltere Versionen schreiben Terminal-Steuerzeichen vor die JSON-Ausgabe.
        $start = strpos($out, '{');
        $data  = $start === false ? null : json_decode(substr($out, $start), true);

        if (!is_array($data)) {
            $hint = trim($err) !== '' ? trim($err) : 'keine gueltige JSON-Ausgabe';
            $this->lastError = "cSysinfo: sysinfo (Code $code): $hint";
            return null;
        }

        $this->lastError = null;
        return $data;
    }

    /** Strings aus sysinfo in Bytes, Prozent und Grad umrechnen. */
    public function normalize(array $raw): array {
        $out = [];

        if (isset($raw['system']) && is_array($raw['system'])) {
            $s    = $raw['system'];
            $load = array_map('floatval', array_values((array) ($s['loadavg'] ?? [])));

            $out['system'] = [
                'hostname'  => (string) ($s['hostname'] ?? ''),
                'kernel'    => (string) ($s['kernel'] ?? ''),
                'os'        => (string) ($s['os'] ?? ''),
                'cpu_model' => (string) ($s['cpu_model'] ?? ''),
                'uptime_s'  => (float) ($s['uptime_s'] ?? 0),
                'loadavg'   => array_pad($load, 3, 0.0),
            ];
        }

        if (isset($raw['cpu_load'])) {
            $loads = array_map('floatval', array_values((array) $raw['cpu_load']));
            $mhz   = $this->mhzList((array) ($raw['cpu_mhz'] ?? []), count($loads));

            $cores = [];
            foreach ($loads as $i => $load) {
                $cores[] = ['load' => round($load, 1), 'mhz' => isset($mhz[$i]) ? round($mhz[$i]) : null];
            }

            $total = isset($raw['cpu_total'])
                ? (float) $raw['cpu_total']
                : ($loads === [] ? 0.0 : array_sum($loads) / count($loads));

            $out['cpu'] = ['total' => round($total, 1), 'cores' => $cores];
        }

        if (isset($raw['temperatures'])) {
            $temps = [];
            foreach ((array) $raw['temperatures'] as $label => $value) {
                $celsius = $this->number((string) $value);
                if ($celsius === null) continue;

                $label   = (string) $label;
                $temps[] = [
                    'label'   => $label,
                    'celsius' => round($celsius, 1),
                    'group'   => preg_match('/^(Package|Core|Tctl|Tdie|Tccd|CPU)/i', $label) ? 'cpu' : 'other',
                ];
            }
            $out['temperatures'] = $temps;
        }

        if (isset($raw['memory']) && is_array($raw['memory'])) {
            $out['memory'] = $this->memory($raw['memory']);
        }

        if (isset($raw['filesystems']) || isset($raw['disk_usage'])) {
            $out['filesystems'] = $this->filesystems($raw);
        }

        if (isset($raw['gpus'])) {
            $gpus = [];
            foreach ((array) $raw['gpus'] as $gpu) {
                $memUsed  = isset($gpu['memory_used_bytes']) ? (int) $gpu['memory_used_bytes'] : null;
                $memTotal = isset($gpu['memory_total_bytes']) ? (int) $gpu['memory_total_bytes'] : null;

                $gpus[] = [
                    'id'           => (int) ($gpu['id'] ?? count($gpus)),
                    'name'         => (string) ($gpu['name'] ?? 'GPU'),
                    'celsius'      => $this->number((string) ($gpu['temp'] ?? '')),
                    'watt'         => $this->number((string) ($gpu['power'] ?? '')),
                    'watt_limit'   => isset($gpu['power_limit_w']) ? (float) $gpu['power_limit_w'] : null,
                    'percent'      => $this->number((string) ($gpu['utilization'] ?? '')),
                    'memory_used'  => $memUsed,
                    'memory_total' => $memTotal,
                    // Unified Memory (z. B. DGX Spark) meldet keinen eigenen VRAM:
                    // null statt 0 %, sonst saehe es aus wie ein leerer Speicher.
                    'memory_percent' => ($memUsed !== null && $memTotal > 0) ? round($memUsed / $memTotal * 100, 1) : null,
                    'fan_percent'  => isset($gpu['fan_percent']) ? (float) $gpu['fan_percent'] : null,
                    'pstate'       => (string) ($gpu['pstate'] ?? ''),
                    'driver'       => (string) ($gpu['driver'] ?? ''),
                ];
            }
            $out['gpus'] = $gpus;
        }

        if (isset($raw['disk_io'])) {
            $io = [];
            foreach ((array) $raw['disk_io'] as $dev) {
                $io[] = [
                    'device'         => (string) ($dev['device'] ?? '?'),
                    'read_bps'       => (int) ($dev['read_bps'] ?? $this->parseSize((string) ($dev['read'] ?? '')) ?? 0),
                    'write_bps'      => (int) ($dev['write_bps'] ?? $this->parseSize((string) ($dev['write'] ?? '')) ?? 0),
                    'active_percent' => isset($dev['active_percent']) ? round((float) $dev['active_percent'], 1) : null,
                ];
            }
            $out['disk_io'] = $io;
        }

        if (isset($raw['net_io'])) {
            $net = [];
            foreach ((array) $raw['net_io'] as $if) {
                $speed = (int) ($if['speed_mbit'] ?? -1);
                $net[] = [
                    'interface'  => (string) ($if['interface'] ?? '?'),
                    'rx_bps'     => (int) ($if['rx_bps'] ?? 0),
                    'tx_bps'     => (int) ($if['tx_bps'] ?? 0),
                    'rx_total'   => (int) ($if['rx_total_bytes'] ?? 0),
                    'tx_total'   => (int) ($if['tx_total_bytes'] ?? 0),
                    'state'      => (string) ($if['state'] ?? 'unknown'),
                    'speed_mbit' => $speed > 0 ? $speed : null,
                    'ipv4'       => array_values(array_filter(explode(' ', (string) ($if['ipv4'] ?? '')))),
                ];
            }
            $out['network'] = $net;
        }

        if (isset($raw['wan']) && is_array($raw['wan'])) {
            $w     = $raw['wan'];
            $ip    = trim((string) ($w['ip'] ?? ''));
            $error = trim((string) ($w['error'] ?? ''));

            // Kein Internet ist ein Messwert, kein Messfehler: error steht in den
            // Daten, der Takt selbst bleibt fehlerfrei.
            $out['wan'] = [
                'ip'          => $ip === '' ? null : $ip,
                'latency_ms'  => isset($w['latency_ms']) ? round((float) $w['latency_ms'], 1) : null,
                'response_ms' => isset($w['response_ms']) ? round((float) $w['response_ms'], 1) : null,
                'error'       => $error === '' ? null : $error,
                'server'      => (string) parse_url((string) ($w['url'] ?? ''), PHP_URL_HOST),
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Innenleben
    // -----------------------------------------------------------------------

    private function memory(array $m): array {
        $total = $this->bytes($m, 'total');
        $avail = $this->bytes($m, 'available');

        // "In Verwendung" wie im Task-Manager: alles, was nicht verfuegbar ist.
        // free zaehlt den Cache weder als belegt noch als frei - total - available
        // ist die Zahl, die bei Speicherdruck wirklich zaehlt.
        $used = ($total !== null && $avail !== null)
            ? max(0, $total - $avail)
            : $this->bytes($m, 'used');

        $cache = (int) ($m['cache_bytes'] ?? 0);
        if ($total !== null && $used !== null) {
            $cache = min($cache, max(0, $total - $used));
        }

        return [
            'total'      => $total,
            'used'       => $used,
            'available'  => $avail,
            'cache'      => $cache,
            'free'       => ($total !== null && $used !== null) ? max(0, $total - $used - $cache) : null,
            'swap_total' => isset($m['swap_total_bytes']) ? (int) $m['swap_total_bytes'] : null,
            'swap_used'  => isset($m['swap_used_bytes']) ? (int) $m['swap_used_bytes'] : null,
        ];
    }

    private function filesystems(array $raw): array {
        $list = [];

        foreach ((array) ($raw['filesystems'] ?? []) as $fs) {
            $total = (int) ($fs['total_bytes'] ?? 0);
            if ($total <= 0) continue;

            // Belegt = Gesamt - Frei wie im Explorer: die fuer root reservierten
            // Bloecke (ext4: 5 %) stehen niemandem sonst zur Verfuegung.
            $free = max(0, min($total, (int) ($fs['free_bytes'] ?? 0)));

            $list[] = [
                'device'  => (string) ($fs['device'] ?? ''),
                'fstype'  => (string) ($fs['fstype'] ?? ''),
                'mount'   => (string) ($fs['mount'] ?? ''),
                'total'   => $total,
                'used'    => $total - $free,
                'free'    => $free,
                'percent' => round(($total - $free) / $total * 100, 1),
            ];
        }

        // Aelteres sysinfo ohne "filesystems": wenigstens das Wurzel-Dateisystem.
        if (!isset($raw['filesystems']) && is_array($raw['disk_usage'] ?? null)) {
            $total = $this->parseSize((string) ($raw['disk_usage']['total'] ?? ''));
            $free  = $this->parseSize((string) ($raw['disk_usage']['free'] ?? ''));
            if ($total) {
                $free   = min($total, (int) $free);
                $list[] = [
                    'device' => '', 'fstype' => '', 'mount' => '/',
                    'total'  => $total, 'used' => $total - $free, 'free' => $free,
                    'percent' => round(($total - $free) / $total * 100, 1),
                ];
            }
        }

        return $list;
    }

    // Aeltere sysinfo-Versionen kleben alle Kerne zu einem String zusammen ("2900.2782918.399").
    private function mhzList(array $values, int $cores): array {
        if (count($values) === 1 && $cores > 1 && preg_match_all('/\d+\.\d{3}/', (string) reset($values), $m)) {
            $values = $m[0];
        }
        return array_map('floatval', array_values($values));
    }

    private function bytes(array $m, string $key): ?int {
        if (isset($m[$key . '_bytes'])) return (int) $m[$key . '_bytes'];
        if (isset($m[$key]))            return $this->parseSize((string) $m[$key]);
        return null;
    }

    /** "4.0Gi", "978Mi", "49G", "196.22 KB/s" -> Bytes (1024er-Schritte wie free/df -h). */
    private function parseSize(string $text): ?int {
        if (!preg_match('/^\s*([\d.,]+)\s*([KMGTPE]?)/i', $text, $m)) return null;

        $value = (float) str_replace(',', '.', $m[1]);
        if ($m[2] === '') return (int) round($value);

        return (int) round($value * 1024 ** (stripos('KMGTPE', $m[2]) + 1));
    }

    /** Erste Zahl aus "53.0°C", "120.5W", "30%"; "[N/A]" -> null. */
    private function number(string $text): ?float {
        return preg_match('/-?\d+(?:\.\d+)?/', $text, $m) ? (float) $m[0] : null;
    }
}
