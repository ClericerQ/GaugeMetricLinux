<?php

class cNetwork {
    private array $aPorts = [
        21   => 'ftp',      22   => 'ssh',       23   => 'telnet',   25   => 'smtp',
        53   => 'dns',      80   => 'http',      110  => 'pop3',     143  => 'imap',
        161  => 'snmp',     389  => 'ldap',      443  => 'https',    445  => 'smb',
        587  => 'submission', 631 => 'ipp',      993  => 'imaps',    995  => 'pop3s',
        1883 => 'mqtt',     3306 => 'mysql',     3389 => 'rdp',      5432 => 'postgres',
        5900 => 'vnc',      6379 => 'redis',     8080 => 'http-alt', 8443 => 'https-alt',
        9000 => 'php-fpm',  27017 => 'mongodb',
    ];

    private array $aTools = [];

    private ?bool $bIcmp = null;

    private const MAX_SWEEP_HOSTS = 1024;

    public function ip(string $ip, ?int $version = null): bool {
        $flags = match ($version) {
            4       => FILTER_FLAG_IPV4,
            6       => FILTER_FLAG_IPV6,
            default => 0,
        };
        return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
    }

    public function mac(string $mac): bool {
        return $this->normalizeMac($mac) !== false;
    }

    public function normalizeMac(string $mac): string|false {
        $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $mac));
        if (strlen($hex) !== 12) {
            return false;
        }
        return implode(':', str_split($hex, 2));
    }

    public function host(string $host): bool {
        if ($this->ip($host)) {
            return true;
        }
        return (bool) preg_match(
            '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?\.?$/i',
            $host
        );
    }

    public function ping(string $host, int $count = 3, int $timeout = 1): array|false {
        if (!$this->host($host) || $count < 1) {
            return false;
        }

        $count   = min($count, 50);
        $timeout = max(1, $timeout);

        $cmd = sprintf(
            'ping -n -c %d -W %d -w %d -i 0.3 %s',
            $count, $timeout, $count * $timeout + 1, escapeshellarg($host)
        );

        $out = $this->run($cmd);
        if ($out === '') {
            return false;
        }

        preg_match_all('/time[=<]\s*([\d.]+)\s*ms/', $out, $mTimes);
        $times = array_map('floatval', $mTimes[1]);
        if (!$times) {
            return false;
        }

        preg_match('/^PING\s+\S+\s+\(([^)]+)\)/m', $out, $mIp);
        preg_match('/(\d+) packets transmitted,\s*(\d+)/', $out, $mStat);
        preg_match('/ttl=(\d+)/i', $out, $mTtl);

        $sent = isset($mStat[1]) ? (int) $mStat[1] : $count;
        $recv = isset($mStat[2]) ? (int) $mStat[2] : count($times);
        $sent = max($sent, 1);

        return [
            'host'         => $host,
            'ip'           => $mIp[1] ?? ($this->ip($host) ? $host : null),
            'alive'        => true,
            'sent'         => $sent,
            'received'     => $recv,
            'lost'         => $sent - $recv,
            'loss_percent' => round(($sent - $recv) / $sent * 100, 1),
            'times'        => $times,
            'min_ms'       => round(min($times), 3),
            'avg_ms'       => round(array_sum($times) / count($times), 3),
            'max_ms'       => round(max($times), 3),
            'mdev_ms'      => $this->mdev($times),
            'ttl'          => isset($mTtl[1]) ? (int) $mTtl[1] : null,
        ];
    }

    public function alive(string $host, int $timeout = 1): bool {
        return $this->ping($host, 1, $timeout) !== false;
    }

    public function icmp(): bool {
        return $this->bIcmp ??= ($this->has('ping') && $this->ping('127.0.0.1', 1, 1) !== false);
    }

    public function port(string $host, int $port, float $timeout = 2.0): bool {
        if (!$this->host($host) || $port < 1 || $port > 65535) {
            return false;
        }

        $target = $this->ip($host, 6) ? "[$host]" : $host;
        $sock   = @fsockopen($target, $port, $errno, $errstr, max(0.1, $timeout));
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }

    public function portScan(string $host, array|string|null $ports = null, float $timeout = 1.0, bool $openOnly = true): array|false {
        if (!$this->host($host)) {
            return false;
        }

        $list = $this->portList($ports);
        if (!$list) {
            return false;
        }

        $result = [];
        foreach ($list as $port) {
            $start = microtime(true);
            $open  = $this->port($host, $port, $timeout);
            $ms    = round((microtime(true) - $start) * 1000, 2);

            if (!$open && $openOnly) {
                continue;
            }
            $result[] = [
                'port'       => $port,
                'service'    => $this->aPorts[$port] ?? null,
                'open'       => $open,
                'latency_ms' => $open ? $ms : null,
            ];
        }
        return $result;
    }

    public function trace(string $host, int $maxHops = 20, int $timeout = 1): array|false {
        if (!$this->host($host)) {
            return false;
        }

        $target = $this->ip($host) ? $host : $this->resolve($host);
        if ($target === false) {
            return false;
        }

        $hops    = [];
        $maxHops = max(1, min($maxHops, 40));

        for ($ttl = 1; $ttl <= $maxHops; $ttl++) {
            $start = microtime(true);
            $out   = $this->run(sprintf(
                'ping -n -c 1 -W %d -w %d -t %d %s',
                max(1, $timeout), max(1, $timeout) + 1, $ttl, escapeshellarg($target)
            ));
            $ms = round((microtime(true) - $start) * 1000, 2);

            $ip      = null;
            $reached = false;
            $error   = null;

            if (preg_match('/bytes from ([0-9a-f.:]+?):\s/i', $out, $m)) {
                $ip      = $this->cleanIp($m[1]);
                $reached = $ip !== null;
                if (preg_match('/time[=<]\s*([\d.]+)\s*ms/', $out, $mt)) {
                    $ms = (float) $mt[1];
                }
            } elseif (preg_match('/From ([0-9a-f.:]+)/', $out, $m)) {
                $ip = $this->cleanIp($m[1]);
                if (preg_match('/((?:Destination|Host|Net|Port|Protocol|Source)[\w ]*?[Uu]nreachable)/', $out, $mErr)) {
                    $error = $mErr[1];
                }
            }

            $hops[] = [
                'hop'     => $ttl,
                'ip'      => $ip,
                'time_ms' => $ip === null ? null : $ms,
                'timeout' => $ip === null,
                'reached' => $reached,
                'error'   => $error,
            ];

            if ($reached || $error !== null) {
                break;
            }
        }
        return $hops;
    }

    public function arp(string|false $filter = false, bool $resolve = false): array|false {
        $entries = $this->neighbours();
        if ($entries === false) {
            return false;
        }

        $needle = is_string($filter) ? trim($filter) : '';

        if ($needle !== '') {
            $mac = $this->normalizeMac($needle);

            if ($this->ip($needle)) {
                $entries = array_filter($entries, fn($e) => $e['ip'] === $needle);
            } elseif ($mac !== false) {
                $entries = array_filter($entries, fn($e) => $e['mac'] === $mac);
            } else {
                $ip      = $this->resolve($needle);
                $resolve = true;
                $entries = array_filter($entries, function ($e) use ($ip, $needle) {
                    if ($ip !== false && $e['ip'] === $ip) {
                        return true;
                    }
                    $host = $this->reverse($e['ip']);
                    return $host !== false && stripos($host, $needle) !== false;
                });
            }
            $entries = array_values($entries);
        }

        if ($resolve) {
            foreach ($entries as &$entry) {
                $host = $this->reverse($entry['ip']);
                $entry['hostname'] = $host === false ? null : $host;
            }
            unset($entry);
        }

        return $entries;
    }

    public function resolveMac(string $ip): string|false {
        if (!$this->ip($ip)) {
            return false;
        }

        $found = $this->arp($ip);
        if (!empty($found[0]['mac'])) {
            return $found[0]['mac'];
        }

        $this->ping($ip, 1, 1);
        $found = $this->arp($ip);
        return $found[0]['mac'] ?? false;
    }

    public function discover(string $cidr = '', int $timeout = 1, int $concurrency = 64, bool $resolve = false): array|false {
        if ($cidr === '') {
            $cidr = $this->localCidr();
        }

        $net = $this->cidr((string) $cidr);
        if ($net === false || $net['hosts'] > self::MAX_SWEEP_HOSTS) {
            return false;
        }
        if (!$this->has('ping')) {
            return false;
        }

        $timeout     = max(1, $timeout);
        $concurrency = max(1, min($concurrency, 256));

        $hosts = [];
        for ($ip = ip2long($net['first_host']); $ip <= ip2long($net['last_host']); $ip++) {
            $hosts[] = long2ip($ip);
        }

        $alive = [];
        foreach (array_chunk($hosts, $concurrency) as $chunk) {
            $parts = [];
            foreach ($chunk as $ip) {
                $arg     = escapeshellarg($ip);
                $parts[] = "(ping -n -c 1 -W $timeout $arg >/dev/null 2>&1 && echo $arg) &";
            }
            $out = $this->run(implode(' ', $parts) . ' wait');
            foreach (preg_split('/\R/', $out, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
                $line = trim($line, "' \t");
                if ($this->ip($line, 4)) {
                    $alive[] = $line;
                }
            }
        }

        $arp = [];
        foreach (($this->neighbours() ?: []) as $entry) {
            $arp[$entry['ip']] = $entry;
        }

        $result = [];
        sort($alive, SORT_NATURAL);
        foreach ($alive as $ip) {
            $host     = $resolve ? $this->reverse($ip) : false;
            $result[] = [
                'ip'        => $ip,
                'mac'       => $arp[$ip]['mac'] ?? null,
                'interface' => $arp[$ip]['interface'] ?? null,
                'hostname'  => $host === false ? null : $host,
            ];
        }
        return $result;
    }

    public function interfaces(?string $name = null): array|false {
        $rows = json_decode($this->run('ip -j addr'), true);
        if (!is_array($rows)) {
            return $this->interfacesFallback($name);
        }

        $all = [];
        foreach ($rows as $row) {
            $if    = $row['ifname'] ?? null;
            $flags = $row['flags'] ?? [];
            if ($if === null) {
                continue;
            }

            $ipv4 = $ipv6 = [];
            foreach ($row['addr_info'] ?? [] as $addr) {
                $entry = [
                    'ip'        => $addr['local'],
                    'prefix'    => $addr['prefixlen'],
                    'cidr'      => $addr['local'] . '/' . $addr['prefixlen'],
                    'scope'     => $addr['scope'] ?? null,
                    'broadcast' => $addr['broadcast'] ?? null,
                ];
                if (($addr['family'] ?? '') === 'inet') {
                    $entry['netmask'] = $this->prefixToMask((int) $addr['prefixlen']);
                    $ipv4[] = $entry;
                } else {
                    $ipv6[] = $entry;
                }
            }

            $speed = $this->sysfs($if, 'speed');

            $all[$if] = [
                'name'       => $if,
                'index'      => $row['ifindex'] ?? null,
                'mac'        => $row['address'] ?? null,
                'mtu'        => $row['mtu'] ?? null,
                'type'       => $row['link_type'] ?? null,
                'state'      => $row['operstate'] ?? 'UNKNOWN',
                'up'         => in_array('UP', $flags, true),
                'loopback'   => in_array('LOOPBACK', $flags, true),
                'carrier'    => $this->sysfs($if, 'carrier') === '1',
                'speed_mbit' => ($speed !== null && (int) $speed > 0) ? (int) $speed : null,
                'ipv4'       => $ipv4,
                'ipv6'       => $ipv6,
                'statistics' => $this->ifaceStats($if),
            ];
        }

        if ($name !== null) {
            return $all[$name] ?? false;
        }
        return $all;
    }

    public function gateway(): array|false {
        foreach ($this->routes() ?: [] as $route) {
            if ($route['destination'] === 'default' && $route['gateway'] !== null) {
                return [
                    'ip'        => $route['gateway'],
                    'interface' => $route['interface'],
                    'source'    => $route['source'],
                ];
            }
        }
        return false;
    }

    public function routes(): array|false {
        $rows = json_decode($this->run('ip -j route'), true);
        if (!is_array($rows)) {
            return false;
        }

        $routes = [];
        foreach ($rows as $row) {
            $routes[] = [
                'destination' => $row['dst'] ?? null,
                'gateway'     => $row['gateway'] ?? null,
                'interface'   => $row['dev'] ?? null,
                'source'      => $row['prefsrc'] ?? null,
                'metric'      => $row['metric'] ?? null,
                'protocol'    => $row['protocol'] ?? null,
            ];
        }
        return $routes;
    }

    public function localIp(): string|false {
        $gw = $this->gateway();
        if ($gw !== false && $gw['source'] !== null) {
            return $gw['source'];
        }

        $sock = @stream_socket_client('udp://1.1.1.1:53', $errno, $errstr, 1);
        if ($sock === false) {
            return false;
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        $ip = substr((string) $name, 0, (int) strrpos((string) $name, ':'));
        return $this->ip($ip) ? $ip : false;
    }

    public function listening(): array|false {
        if ($this->has('ss')) {
            $lines = $this->run('ss -H -tulnp');
            $regex = '/^(\S+)\s+(\S+)\s+\d+\s+\d+\s+(\S+)\s+\S+(?:\s+users:\((.*)\))?/';
            $map   = ['protocol' => 1, 'state' => 2, 'local' => 3, 'users' => 4];
        } elseif ($this->has('netstat')) {
            $lines = $this->run('netstat -tulnp');
            $regex = '/^(tcp\S*|udp\S*)\s+\d+\s+\d+\s+(\S+)\s+\S+\s+(\S*)\s*(\S*)$/';
            $map   = ['protocol' => 1, 'local' => 2, 'state' => 3, 'users' => 4];
        } else {
            return false;
        }

        $result = [];
        foreach (preg_split('/\R/', $lines, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            if (!preg_match($regex, trim($line), $m)) {
                continue;
            }
            [$address, $port] = $this->splitAddress($m[$map['local']]);
            if ($port === null) {
                continue;
            }

            $proc = $this->parseProcess($m[$map['users']] ?? '');
            $result[] = [
                'protocol' => $m[$map['protocol']],
                'address'  => $address,
                'port'     => $port,
                'state'    => $m[$map['state']] ?? null,
                'process'  => $proc['name'],
                'pid'      => $proc['pid'],
            ];
        }
        return $result;
    }

    public function connections(string $state = 'established'): array|false {
        if (!$this->has('ss')) {
            return false;
        }
        if (!preg_match('/^[a-z-]+$/', $state)) {
            return false;
        }

        $out    = $this->run('ss -H -tnp state ' . escapeshellarg($state));
        $result = [];

        foreach (preg_split('/\R/', $out, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            if (!preg_match('/^\d+\s+\d+\s+(\S+)\s+(\S+)(?:\s+users:\((.*)\))?/', trim($line), $m)) {
                continue;
            }
            [$lIp, $lPort] = $this->splitAddress($m[1]);
            [$rIp, $rPort] = $this->splitAddress($m[2]);
            $proc = $this->parseProcess($m[3] ?? '');

            $result[] = [
                'local_ip'    => $lIp,
                'local_port'  => $lPort,
                'remote_ip'   => $rIp,
                'remote_port' => $rPort,
                'process'     => $proc['name'],
                'pid'         => $proc['pid'],
            ];
        }
        return $result;
    }

    public function throughput(?string $iface = null, float $seconds = 1.0): array|false {
        $iface ??= ($this->gateway()['interface'] ?? null);
        if ($iface === null || $this->sysfs($iface, 'operstate') === null) {
            return false;
        }

        $seconds = max(0.1, min($seconds, 10.0));
        $first   = $this->ifaceStats($iface);
        usleep((int) ($seconds * 1_000_000));
        $second = $this->ifaceStats($iface);

        if ($first === null || $second === null) {
            return false;
        }

        $rx = max(0, $second['rx_bytes'] - $first['rx_bytes']);
        $tx = max(0, $second['tx_bytes'] - $first['tx_bytes']);

        return [
            'interface' => $iface,
            'seconds'   => $seconds,
            'rx_bytes'  => $rx,
            'tx_bytes'  => $tx,
            'rx_bps'    => (int) round($rx * 8 / $seconds),
            'tx_bps'    => (int) round($tx * 8 / $seconds),
            'rx_human'  => $this->formatBits($rx * 8 / $seconds),
            'tx_human'  => $this->formatBits($tx * 8 / $seconds),
        ];
    }

    public function resolve(string $host): string|false {
        if ($this->ip($host)) {
            return $host;
        }
        if (!$this->host($host)) {
            return false;
        }
        $ip = gethostbyname($host);
        return ($ip !== $host && $this->ip($ip)) ? $ip : false;
    }

    public function reverse(string $ip): string|false {
        if (!$this->ip($ip)) {
            return false;
        }
        $host = gethostbyaddr($ip);
        return ($host === false || $host === $ip) ? false : $host;
    }

    public function dns(string $host, string $type = 'A'): array|false {
        if (!$this->host($host)) {
            return false;
        }

        $const = match (strtoupper($type)) {
            'A'     => DNS_A,     'AAAA' => DNS_AAAA, 'MX'  => DNS_MX,
            'TXT'   => DNS_TXT,   'NS'   => DNS_NS,   'SOA' => DNS_SOA,
            'CNAME' => DNS_CNAME, 'SRV'  => DNS_SRV,  'PTR' => DNS_PTR,
            'ANY'   => DNS_ALL,
            default => null,
        };
        if ($const === null) {
            return false;
        }

        $start   = microtime(true);
        $records = @dns_get_record($host, $const);
        $ms      = round((microtime(true) - $start) * 1000, 2);

        if (!is_array($records) || !$records) {
            return false;
        }

        $result = [];
        foreach ($records as $rec) {
            $result[] = [
                'type'     => $rec['type'] ?? null,
                'ttl'      => $rec['ttl'] ?? null,
                'value'    => $rec['ip'] ?? $rec['ipv6'] ?? $rec['target']
                              ?? $rec['txt'] ?? $rec['mname'] ?? null,
                'priority' => $rec['pri'] ?? null,
                'time_ms'  => $ms,
            ];
        }
        return $result;
    }

    public function nameservers(): array {
        $raw = @file_get_contents('/etc/resolv.conf');
        if ($raw === false) {
            return [];
        }
        preg_match_all('/^\s*nameserver\s+(\S+)/mi', $raw, $m);
        return array_values(array_filter($m[1], fn($ip) => $this->ip($ip)));
    }

    public function publicIp(int $timeout = 5): string|false {
        foreach (['https://api.ipify.org', 'https://ifconfig.me/ip', 'https://icanhazip.com'] as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => max(1, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'cNetwork',
            ]);
            $body = curl_exec($ch);
            curl_close($ch);

            $ip = trim((string) $body);
            if ($this->ip($ip)) {
                return $ip;
            }
        }
        return false;
    }

    public function cidr(string $cidr): array|false {
        $parts = explode('/', trim($cidr), 2);
        if (count($parts) !== 2 || !$this->ip($parts[0], 4) || !ctype_digit($parts[1])) {
            return false;
        }

        $prefix = (int) $parts[1];
        if ($prefix > 32) {
            return false;
        }

        $mask      = $prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;
        $network   = ip2long($parts[0]) & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        $small = $prefix >= 31;

        return [
            'cidr'       => long2ip($network) . '/' . $prefix,
            'ip'         => $parts[0],
            'prefix'     => $prefix,
            'netmask'    => long2ip($mask),
            'network'    => long2ip($network),
            'broadcast'  => long2ip($broadcast),
            'first_host' => long2ip($small ? $network : $network + 1),
            'last_host'  => long2ip($small ? $broadcast : $broadcast - 1),
            'hosts'      => $small ? ($prefix === 32 ? 1 : 2) : $broadcast - $network - 1,
        ];
    }

    public function inSubnet(string $ip, string $cidr): bool {
        $parts = explode('/', trim($cidr), 2);
        if (count($parts) !== 2 || !ctype_digit($parts[1]) || !$this->ip($ip) || !$this->ip($parts[0])) {
            return false;
        }

        $a = inet_pton($ip);
        $b = inet_pton($parts[0]);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }

        $prefix = (int) $parts[1];
        if ($prefix > strlen($a) * 8) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        $bits  = $prefix % 8;

        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        if ($bits > 0) {
            $mask = chr((0xFF << (8 - $bits)) & 0xFF);
            return ($a[$bytes] & $mask) === ($b[$bytes] & $mask);
        }
        return true;
    }

    public function check(bool $internet = true, string $probe = '1.1.1.1', string $probeHost = 'one.one.one.one'): array {
        $start    = microtime(true);
        $problems = [];
        $icmp     = $this->icmp();

        $ifaces = $this->interfaces();
        $up     = [];
        foreach (($ifaces ?: []) as $if) {
            if ($if['up'] && !$if['loopback']) {
                $up[] = $if['name'];
            }
        }
        if (!$up) {
            $problems[] = 'Kein aktives Netzwerk-Interface';
        }

        $localIp = $this->localIp();
        if ($localIp === false) {
            $problems[] = 'Keine lokale IP-Adresse';
        }

        $gw     = $this->gateway();
        $gwInfo = ['ip' => null, 'interface' => null, 'reachable' => null, 'latency_ms' => null, 'method' => 'skipped'];
        if ($gw === false) {
            $problems[] = 'Kein Standard-Gateway gesetzt';
        } else {
            $gwInfo['ip']        = $gw['ip'];
            $gwInfo['interface'] = $gw['interface'];

            if ($icmp) {
                $ping                 = $this->ping($gw['ip'], 2, 1);
                $gwInfo['reachable']  = $ping !== false;
                $gwInfo['latency_ms'] = $ping['avg_ms'] ?? null;
                $gwInfo['method']     = 'icmp';
                if ($ping === false) {
                    $problems[] = "Gateway {$gw['ip']} antwortet nicht";
                }
            }
        }

        $dnsStart = microtime(true);
        $resolved = $this->resolve($probeHost);
        $dnsInfo  = [
            'servers'  => $this->nameservers(),
            'ok'       => $resolved !== false,
            'test'     => $probeHost,
            'resolved' => $resolved === false ? null : $resolved,
            'time_ms'  => round((microtime(true) - $dnsStart) * 1000, 2),
        ];
        if (!$dnsInfo['ok']) {
            $problems[] = "DNS-Auflösung fehlgeschlagen ($probeHost)";
        }

        $netInfo  = ['checked' => $internet, 'target' => $probe, 'reachable' => null, 'latency_ms' => null, 'method' => null];
        $publicIp = null;
        if ($internet) {
            if ($icmp) {
                $ping                  = $this->ping($probe, 2, 2);
                $netInfo['reachable']  = $ping !== false;
                $netInfo['latency_ms'] = $ping['avg_ms'] ?? null;
                $netInfo['method']     = 'icmp';
            } else {
                $tcpStart              = microtime(true);
                $netInfo['reachable']  = $this->port($probe, 443, 3);
                $netInfo['latency_ms'] = $netInfo['reachable'] ? round((microtime(true) - $tcpStart) * 1000, 2) : null;
                $netInfo['method']     = 'tcp/443';
            }

            if ($netInfo['reachable']) {
                $publicIp = $this->publicIp(5) ?: null;
            } else {
                $problems[] = "Internet nicht erreichbar ($probe, {$netInfo['method']})";
            }
        }

        return [
            'ok'          => $problems === [],
            'hostname'    => gethostname() ?: null,
            'local_ip'    => $localIp === false ? null : $localIp,
            'icmp'        => $icmp,
            'interfaces'  => ['up' => $up, 'count' => is_array($ifaces) ? count($ifaces) : 0],
            'gateway'     => $gwInfo,
            'dns'         => $dnsInfo,
            'internet'    => $netInfo,
            'public_ip'   => $publicIp,
            'problems'    => $problems,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ];
    }

    private function run(string $cmd): string {
        if (!function_exists('shell_exec')) {
            return '';
        }
        $out = @shell_exec($cmd . ' 2>/dev/null');
        return is_string($out) ? trim($out) : '';
    }

    private function has(string $tool): bool {
        return $this->aTools[$tool] ??= ($this->run('command -v ' . escapeshellarg($tool)) !== '');
    }

    private function neighbours(): array|false {
        $rows = json_decode($this->run('ip -j neigh'), true);

        if (is_array($rows)) {
            $entries = [];
            foreach ($rows as $row) {
                if (empty($row['lladdr'])) {
                    continue;
                }
                $entries[] = [
                    'ip'        => $row['dst'],
                    'mac'       => strtolower($row['lladdr']),
                    'interface' => $row['dev'] ?? null,
                    'state'     => implode(',', (array) ($row['state'] ?? [])),
                    'hostname'  => null,
                ];
            }
            return $entries;
        }

        if (!$this->has('arp')) {
            return false;
        }

        preg_match_all(
            '/\(([^)]+)\) at ([0-9a-f:]{17})(?:\s+\[\w+\])?\s+on\s+(\S+)/i',
            $this->run('arp -an'),
            $matches,
            PREG_SET_ORDER
        );

        $entries = [];
        foreach ($matches as $m) {
            $entries[] = [
                'ip'        => $m[1],
                'mac'       => strtolower($m[2]),
                'interface' => $m[3],
                'state'     => '',
                'hostname'  => null,
            ];
        }
        return $entries;
    }

    private function interfacesFallback(?string $name): array|false {
        if (!function_exists('net_get_interfaces')) {
            return false;
        }
        $raw = @net_get_interfaces();
        if ($raw === false) {
            return false;
        }

        $all = [];
        foreach ($raw as $if => $data) {
            $ipv4 = $ipv6 = [];
            foreach ($data['unicast'] ?? [] as $addr) {
                if (!isset($addr['address'])) {
                    continue;
                }
                $isV4    = $this->ip($addr['address'], 4);
                $netmask = $addr['netmask'] ?? null;
                $prefix  = $this->maskToPrefix((string) $netmask);

                $entry = [
                    'ip'        => $addr['address'],
                    'prefix'    => $prefix,
                    'cidr'      => $prefix === null ? null : $addr['address'] . '/' . $prefix,
                    'scope'     => null,
                    'broadcast' => null,
                ];
                if ($isV4) {
                    $entry['netmask'] = $netmask;
                    $ipv4[] = $entry;
                } else {
                    $ipv6[] = $entry;
                }
            }
            $all[$if] = [
                'name'       => $if,
                'index'      => null,
                'mac'        => $this->sysfs($if, 'address'),
                'mtu'        => $data['mtu'] ?? null,
                'type'       => null,
                'state'      => strtoupper((string) ($this->sysfs($if, 'operstate') ?? 'UNKNOWN')),
                'up'         => (bool) ($data['up'] ?? false),
                'loopback'   => $if === 'lo',
                'carrier'    => $this->sysfs($if, 'carrier') === '1',
                'speed_mbit' => null,
                'ipv4'       => $ipv4,
                'ipv6'       => $ipv6,
                'statistics' => $this->ifaceStats($if),
            ];
        }

        if ($name !== null) {
            return $all[$name] ?? false;
        }
        return $all;
    }

    private function ifaceStats(string $iface): ?array {
        $keys  = ['rx_bytes', 'tx_bytes', 'rx_packets', 'tx_packets',
                  'rx_errors', 'tx_errors', 'rx_dropped', 'tx_dropped'];
        $stats = [];

        foreach ($keys as $key) {
            $value = $this->sysfs($iface, 'statistics/' . $key);
            if ($value === null) {
                return null;
            }
            $stats[$key] = (int) $value;
        }

        $stats['rx_human'] = $this->formatBytes($stats['rx_bytes']);
        $stats['tx_human'] = $this->formatBytes($stats['tx_bytes']);
        return $stats;
    }

    private function sysfs(string $iface, string $file): ?string {
        if (!preg_match('/^[A-Za-z0-9._:@-]+$/', $iface)) {
            return null;
        }
        $path = "/sys/class/net/$iface/$file";
        if (!is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        return $raw === false ? null : trim($raw);
    }

    private function localCidr(): string {
        $gw = $this->gateway();
        $ip = $gw['source'] ?? $this->localIp();
        if ($ip === false || $ip === null) {
            return '';
        }

        foreach ($this->interfaces() ?: [] as $if) {
            foreach ($if['ipv4'] as $addr) {
                if ($addr['ip'] === $ip) {
                    return $addr['cidr'];
                }
            }
        }
        return "$ip/24";
    }

    private function portList(array|string|null $ports): array {
        if ($ports === null) {
            return array_keys($this->aPorts);
        }

        if (is_string($ports)) {
            $list = [];
            foreach (explode(',', $ports) as $chunk) {
                $chunk = trim($chunk);
                if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $chunk, $m) && $m[1] <= $m[2]) {
                    $list = array_merge($list, range((int) $m[1], (int) $m[2]));
                } elseif (ctype_digit($chunk)) {
                    $list[] = (int) $chunk;
                }
            }
            $ports = $list;
        }

        $ports = array_filter(array_map('intval', $ports), fn($p) => $p >= 1 && $p <= 65535);
        $ports = array_values(array_unique($ports));
        sort($ports);
        return $ports;
    }

    private function splitAddress(string $address): array {
        $clean = fn(string $h): string => trim(explode('%', $h)[0], '[]');

        $pos = strrpos($address, ':');
        if ($pos === false) {
            return [$clean($address), null];
        }

        $host = substr($address, 0, $pos);
        $port = substr($address, $pos + 1);

        if (!str_contains($address, ']') && substr_count($address, ':') > 1) {
            return [$clean($address), null];
        }

        return [$clean($host), ctype_digit($port) ? (int) $port : null];
    }

    private function parseProcess(string $users): array {
        if (preg_match('/"([^"]+)",pid=(\d+)/', $users, $m)) {
            return ['name' => $m[1], 'pid' => (int) $m[2]];
        }
        if (preg_match('#^(\d+)/(.+)$#', trim($users), $m)) {
            return ['name' => $m[2], 'pid' => (int) $m[1]];
        }
        return ['name' => null, 'pid' => null];
    }

    private function cleanIp(string $raw): ?string {
        if ($this->ip($raw)) {
            return $raw;
        }
        $trimmed = rtrim($raw, ':');
        return $this->ip($trimmed) ? $trimmed : null;
    }

    private function prefixToMask(int $prefix): string {
        $prefix = max(0, min($prefix, 32));
        return long2ip($prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF);
    }

    private function maskToPrefix(string $mask): ?int {
        if (!$this->ip($mask, 4)) {
            return null;
        }
        return substr_count(decbin(ip2long($mask)), '1');
    }

    private function mdev(array $times): float {
        $avg = array_sum($times) / count($times);
        $sum = 0.0;
        foreach ($times as $time) {
            $sum += abs($time - $avg);
        }
        return round($sum / count($times), 3);
    }

    private function formatBytes(int|float $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    private function formatBits(int|float $bits, int $precision = 2): string {
        $units = ['bit/s', 'Kbit/s', 'Mbit/s', 'Gbit/s'];
        $i = 0;
        while ($bits >= 1000 && $i < count($units) - 1) {
            $bits /= 1000;
            $i++;
        }
        return round($bits, $precision) . ' ' . $units[$i];
    }
}
