<?php
/**
 * Kiosk-Dashboard - eine Seite, alle Werte auf einen Blick.
 *
 * Die Karten stehen in der Reihenfolge ihrer Abtastrate: CPU, Arbeitsspeicher,
 * Netzwerk, Grafikkarte, Datentraeger-I/O, Laufwerke. Die Grafikkarte blendet
 * gauge.js erst ein, wenn nvidia-smi eine meldet - ohne GPU bleibt das Raster
 * wie gehabt. Die Werte selbst holt gauge.js ueber /api/metrics; hier wird nur
 * das Geruest und die Konfiguration ausgeliefert.
 *
 * @var cRoute $cRoute
 * @var string $dir
 */

$gauge = CONFIG['gauge'] ?? [];

$tiers = [];
foreach ((array) ($gauge['tiers'] ?? []) as $name => $tier) {
    $tiers[$name] = [
        'label'       => (string) ($tier['label'] ?? $name),
        'interval_ms' => (int) ($tier['interval_ms'] ?? 1000),
        'history'     => (int) ($tier['history'] ?? 0),
        'keys'        => [],
    ];
}

$intervals = array_column($tiers, 'interval_ms') ?: [1000];

// Pfade ohne Host: cRoute::url() haengt base_path an, der Host kommt vom Browser.
$path  = static fn(string $p): string => (string) parse_url($cRoute->url($p), PHP_URL_PATH);
$asset = static fn(string $f): string => $path('/' . $f) . '?v=' . (int) @filemtime($dir . 'public/' . $f);

$ui = [
    'api'     => $path('/api/metrics'),
    // Halbe kuerzeste Taktzeit: ein neuer Messwert steht spaetestens nach
    // einem halben Takt auf dem Schirm, egal wie Poller und Browser zueinander liegen.
    'poll_ms' => max(100, intdiv(min($intervals), 2)),
    'tiers'   => $tiers,
    'storage' => [
        'warn_percent'     => (float) ($gauge['storage']['warn_percent'] ?? 80),
        'critical_percent' => (float) ($gauge['storage']['critical_percent'] ?? 90),
    ],
];

$host = htmlspecialchars((string) gethostname(), ENT_QUOTES);
?>
<!doctype html>
<html lang="de" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= $host ?> &middot; GaugeMetric</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($asset('gauge.css'), ENT_QUOTES) ?>">
</head>
<body>
<div class="kiosk" id="kiosk">

    <header class="top">
        <div class="ident">
            <span class="host" id="sys-host"><?= $host ?></span>
            <span class="sub" id="sys-sub">&nbsp;</span>
        </div>
        <div class="facts">
            <div class="fact"><span class="k">Laufzeit</span><span class="v" id="sys-uptime">&ndash;</span></div>
            <div class="fact"><span class="k">Last 1 / 5 / 15 min</span><span class="v" id="sys-load">&ndash;</span></div>
            <div class="fact clock"><span class="k" id="clock-date">&nbsp;</span><span class="v" id="clock-time">&ndash;</span></div>
            <div class="link" id="link" role="status"><span class="dot"></span><span id="link-text">Verbinde &hellip;</span></div>
        </div>
    </header>

    <main class="grid" id="grid">

        <section class="card" id="card-cpu" data-key="cpu">
            <header class="card-head">
                <h2>CPU</h2>
                <span class="rate"><span class="stale-note" hidden></span><i class="pulse"></i><span class="every"></span></span>
            </header>
            <div class="card-body">
                <div class="hero-row">
                    <div class="hero">
                        <span class="hero-val" id="cpu-total">&ndash;</span><span class="hero-unit">%</span>
                        <span class="hero-label">Auslastung gesamt</span>
                    </div>
                    <dl class="stats">
                        <dt>Takt &Oslash;</dt><dd id="cpu-mhz">&ndash;</dd>
                        <dt>Kerne</dt><dd id="cpu-count">&ndash;</dd>
                        <dt>Temperatur max</dt><dd id="cpu-temp">&ndash;</dd>
                    </dl>
                </div>
                <div class="chart" id="chart-cpu"></div>
                <div class="cores" id="cpu-cores"></div>
                <div class="sensors" id="sensors" data-key="temperatures">
                    <div class="sub-head"><span>Sensoren</span><span class="rate"><i class="pulse"></i><span class="every"></span></span></div>
                    <div class="chips" id="sensor-chips"><span class="empty">Keine Sensoren gefunden</span></div>
                </div>
            </div>
        </section>

        <section class="card" id="card-mem" data-key="memory">
            <header class="card-head">
                <h2>Arbeitsspeicher</h2>
                <span class="rate"><span class="stale-note" hidden></span><i class="pulse"></i><span class="every"></span></span>
            </header>
            <div class="card-body">
                <div class="hero">
                    <span class="hero-val" id="mem-used">&ndash;</span><span class="hero-unit" id="mem-used-unit"></span>
                    <span class="hero-label" id="mem-of">in Verwendung</span>
                </div>
                <div class="stack" id="mem-stack" aria-hidden="true">
                    <i class="used"></i><i class="cache"></i><i class="free"></i>
                </div>
                <div class="legend">
                    <i class="sw" style="background:var(--s1)"></i><span>In Verwendung</span><b id="mem-l-used">&ndash;</b>
                    <i class="sw" style="background:var(--s1-deep)"></i><span>Cache / Puffer</span><b id="mem-l-cache">&ndash;</b>
                    <i class="sw" style="background:var(--grid)"></i><span>Frei</span><b id="mem-l-free">&ndash;</b>
                </div>
                <dl class="stats wide">
                    <dt>Verf&uuml;gbar</dt><dd id="mem-avail">&ndash;</dd>
                    <dt>Auslagerung</dt><dd id="mem-swap">&ndash;</dd>
                </dl>
                <div class="chart" id="chart-mem"></div>
            </div>
        </section>

        <section class="card" id="card-net" data-key="network">
            <header class="card-head">
                <h2>Netzwerk</h2>
                <span class="rate"><span class="stale-note" hidden></span><i class="pulse"></i><span class="every"></span></span>
            </header>
            <div class="card-body">
                <div class="wan" id="net-wan" data-key="wan" hidden>
                    <div class="sub-head"><span>Internet</span><span class="rate"><span class="stale-note" hidden></span><i class="pulse"></i><span class="every"></span></span></div>
                    <div class="pair">
                        <div class="val"><span class="k">&Ouml;ffentliche IP</span><span class="v" id="wan-ip">&ndash;</span></div>
                        <div class="val"><span class="k"><i class="sw" style="background:var(--s1)"></i>Latenz</span><span class="v" id="wan-latency">&ndash;</span></div>
                    </div>
                    <div class="chart" id="chart-wan"></div>
                    <div class="row-foot" id="wan-foot">&nbsp;</div>
                </div>
                <div class="rows" id="net-list">
                    <p class="empty">Warte auf Messwerte &hellip;</p>
                </div>
            </div>
        </section>

        <section class="card" id="card-gpu" data-key="gpus" hidden>
            <header class="card-head">
                <h2>Grafikkarte</h2>
                <span class="rate"><span class="stale-note" hidden></span><i class="pulse"></i><span class="every"></span></span>
            </header>
            <div class="card-body rows" id="gpu-list">
                <p class="empty">Warte auf Messwerte &hellip;</p>
            </div>
        </section>

        <section class="card" id="card-io" data-key="disk_io">
            <header class="card-head">
                <h2>Datentr&auml;ger-I/O</h2>
                <span class="rate"><span class="stale-note" hidden></span><i class="pulse"></i><span class="every"></span></span>
            </header>
            <div class="card-body rows" id="io-list">
                <p class="empty">Warte auf Messwerte &hellip;</p>
            </div>
        </section>

        <section class="card" id="card-fs" data-key="filesystems">
            <header class="card-head">
                <h2>Laufwerke</h2>
                <span class="rate"><span class="stale-note" hidden></span><i class="pulse"></i><span class="every"></span></span>
            </header>
            <div class="card-body">
                <div class="fs-summary" id="fs-summary">&nbsp;</div>
                <div class="drives" id="fs-list"></div>
            </div>
        </section>

    </main>

    <footer class="foot" id="foot"></footer>
</div>

<script>window.GAUGE = <?= json_encode($ui, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= htmlspecialchars($asset('gauge.js'), ENT_QUOTES) ?>"></script>
</body>
</html>
