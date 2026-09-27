/* GaugeMetricLinux - Kiosk-Dashboard.
 *
 * Holt den Snapshot des Pollers ueber /api/metrics und zeichnet ihn. Die
 * Verlaeufe kommen mit Zeitstempeln vom Server: der Browser tastet nichts
 * selbst ab, deshalb gehen keine Messpunkte verloren, egal wie Abfrage und
 * Messtakt zueinander liegen. Nach dem ersten Abruf werden per ?since= nur
 * noch neue Punkte uebertragen.
 */
(() => {
    'use strict';

    const CFG = window.GAUGE || {};
    const $ = (id) => document.getElementById(id);
    const root = getComputedStyle(document.documentElement);
    const token = (name) => root.getPropertyValue(name).trim();

    const C = {
        s1: token('--s1'), s2: token('--s2'), grid: token('--grid'), axis: token('--axis'),
        muted: token('--muted'), surface: token('--surface'),
    };

    // Sequenzielle Blau-Rampe fuer die Kern-Kacheln: dunkel = ruhig, hell = Volllast
    const HEAT = ['#0d366b', '#104281', '#184f95', '#1c5cab', '#256abf', '#2a78d6', '#3987e5', '#5598e7', '#6da7ec', '#86b6ef'];

    // Welche Karte zeigt welchen Datenschluessel des Snapshots
    const CARDS = { cpu: 'card-cpu', memory: 'card-mem', network: 'card-net', gpus: 'card-gpu', disk_io: 'card-io', filesystems: 'card-fs', temperatures: 'sensors', wan: 'net-wan', smart: 'fs-smart' };

    // Kurzcodes aus sysinfo (Abschnitt wan) -> Anzeigetext
    const WAN_ERRORS = {
        dns: 'Namensauflösung fehlgeschlagen', connect: 'Server nicht erreichbar', timeout: 'Zeitüberschreitung',
        tls: 'TLS-Fehler', 'no-ip': 'Antwort enthält keine IP-Adresse', 'no-curl': 'curl nicht installiert',
    };
    const wanError = (e) => WAN_ERRORS[e] || (/^http-\d+$/.test(e) ? 'HTTP ' + e.slice(5) : e);

    // Kurzcodes aus sysinfo (Abschnitt smart) -> Anzeigetext
    const SMART_ERRORS = {
        'no-smartctl': 'smartctl nicht installiert – apt install smartmontools',
        permission: 'Keine Berechtigung – Poller als root starten oder sudo-Regel für smartctl',
        unsupported: 'Kein SMART (virtuelles Laufwerk oder USB-Brücke)',
        disabled: 'SMART ist abgeschaltet (smartctl -s on)',
        timeout: 'Laufwerk antwortet nicht (Zeitüberschreitung)',
        missing: 'Gerät nicht gefunden',
        'no-data': 'Laufwerk meldet keine SMART-Werte',
    };
    const smartError = (e) => SMART_ERRORS[e] || (/^rc-\d+$/.test(e) ? 'smartctl meldet Fehler ' + e.slice(3) : e);

    const SENSOR_WARN = 80;
    const SENSOR_CRIT = 95;
    // Laufwerke: SMART-Grenzwerte kennen keine Warnstufe, 20 / 10 % Restlebensdauer
    // sind die ueblichen Schwellen der Hersteller-Tools
    const LIFE_WARN = 20;
    const LIFE_CRIT = 10;
    const HOURS_PER_YEAR = 8766;

    // --- Formatierung --------------------------------------------------------

    const NF = [0, 1, 2].map((d) => new Intl.NumberFormat('de-DE', { minimumFractionDigits: d, maximumFractionDigits: d }));
    const ok = (v) => v !== null && v !== undefined && Number.isFinite(v);
    const num = (v, d = 0) => (ok(v) ? NF[d].format(v) : '–');
    const pct = (v, d = 0) => (ok(v) ? num(v, d) + ' %' : '–');
    const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

    // 1024er-Schritte mit "GB" - so wie der Windows-Explorer es anzeigt
    const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    function bytesParts(b) {
        if (!ok(b)) return ['–', ''];
        let i = 0;
        while (Math.abs(b) >= 1024 && i < BYTE_UNITS.length - 1) { b /= 1024; i++; }
        const d = i === 0 || b >= 100 ? 0 : 1;
        return [NF[d].format(b), BYTE_UNITS[i]];
    }
    const bytes = (b) => (ok(b) ? bytesParts(b).join(' ') : '–');
    const byteRate = (b) => (ok(b) ? bytes(b) + '/s' : '–');

    // Netzwerk in Bit/s (dezimal), wie Leitungen und Switches es angeben
    function bitRate(bps) {
        if (!ok(bps)) return '–';
        let v = bps * 8, i = 0;
        const units = ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s'];
        while (v >= 1000 && i < units.length - 1) { v /= 1000; i++; }
        const d = i === 0 || v >= 100 ? 0 : v >= 10 ? 1 : 2;
        return NF[d].format(v) + ' ' + units[i];
    }

    function linkSpeed(mbit) {
        if (!ok(mbit) || mbit <= 0) return '';
        return mbit >= 1000 ? num(mbit / 1000, mbit % 1000 ? 1 : 0) + ' Gbit/s' : mbit + ' Mbit/s';
    }

    function uptime(s) {
        if (!ok(s)) return '–';
        const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
        return (d ? d + ' T ' : '') + h + ' Std ' + String(m).padStart(2, '0') + ' Min';
    }

    // Betriebsstunden: "4 J 186 T" - Jahre sagen bei Laufwerken mehr als 39.512 Std
    function hoursSpan(h) {
        if (!ok(h)) return '–';
        const y = Math.floor(h / HOURS_PER_YEAR), d = Math.floor((h % HOURS_PER_YEAR) / 24);
        if (y) return `${y} J ${d} T`;
        if (d) return `${d} T ${Math.floor(h % 24)} Std`;
        return num(h) + ' Std';
    }

    // Hochrechnung, keine Messung: grob runden, sonst wirkt sie genauer als sie ist
    function remainingSpan(h) {
        if (!ok(h)) return null;
        if (h <= 0) return '0';
        const years = h / HOURS_PER_YEAR;
        if (years >= 20) return 'über 20 Jahre';
        if (years >= 1) {
            const y = Math.floor(years), m = Math.floor((years - y) * 12);
            return `ca. ${y} J` + (m ? ` ${m} Mon` : '');
        }
        const months = h / (HOURS_PER_YEAR / 12);
        if (months >= 1) return `ca. ${Math.floor(months)} Mon`;
        return `ca. ${Math.max(1, Math.floor(h / 24))} T`;
    }

    const every = (ms) => (ms >= 1000 ? num(ms / 1000, ms % 1000 ? 1 : 0) + ' s' : ms + ' ms');
    const span = (s) => (s >= 120 ? num(s / 60, 0) + ' min' : num(s, 0) + ' s');

    function el(tag, cls, text) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    }

    function hexAlpha(hex, a) {
        const n = parseInt(hex.slice(1), 16);
        return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${a})`;
    }

    // --- Zeitreihen-Diagramm ------------------------------------------------

    function niceCeil(v) {
        if (!(v > 0)) return 1;
        const e = Math.pow(10, Math.floor(Math.log10(v)));
        const f = v / e;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * e;
    }

    class TimeChart {
        /**
         * opts.series: [{key, label, color, fill}]
         * opts.yMax:   feste Obergrenze (z. B. 100 %) oder null = automatisch
         * opts.minMax: kleinste automatische Obergrenze - Leerlauf soll nicht wie Volllast aussehen
         * opts.scale:  Faktor der Anzeigeeinheit (8 fuer Bit), damit die Skala dort "rund" ist
         */
        constructor(host, opts) {
            this.host = host;
            this.o = Object.assign({ series: [], yMax: null, minMax: 1, scale: 1, format: String }, opts);
            this.canvas = el('canvas');
            this.tip = el('div', 'tip');
            this.tip.hidden = true;
            host.append(this.canvas, this.tip);

            this.t = [];
            this.s = {};
            this.win = 60;
            this.w = this.h = 0;
            this.dpr = 1;
            this.hoverX = null;

            if ('ResizeObserver' in window) new ResizeObserver(() => this.resize()).observe(host);
            else window.addEventListener('resize', () => this.resize());

            host.addEventListener('pointermove', (e) => { this.hoverX = e.clientX - host.getBoundingClientRect().left; this.draw(); });
            host.addEventListener('pointerleave', () => { this.hoverX = null; this.draw(); });
            this.resize();
        }

        resize() {
            const w = this.host.clientWidth, h = this.host.clientHeight, dpr = window.devicePixelRatio || 1;
            if (w === this.w && h === this.h && dpr === this.dpr) return;
            this.w = w; this.h = h; this.dpr = dpr;
            this.fontPx = parseFloat(getComputedStyle(this.host).fontSize) || 11;
            this.canvas.width = Math.max(1, Math.round(w * dpr));
            this.canvas.height = Math.max(1, Math.round(h * dpr));
            this.canvas.style.width = w + 'px';
            this.canvas.style.height = h + 'px';
            this.draw();
        }

        setData(t, series, win) {
            this.t = t || [];
            this.s = series || {};
            this.win = win;
            this.draw();
        }

        draw() {
            const { w, h, o, t } = this;
            // In verdichteten Listen ist das Diagramm nur eine Zeile hoch: dann
            // ohne Achsenbeschriftung, sonst bliebe fuer die Linie kaum Platz
            const bare = this.host.closest('.compact') !== null;
            if (w < 30 || h < (bare ? 16 : 30)) return;

            const ctx = this.canvas.getContext('2d');
            ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
            ctx.clearRect(0, 0, w, h);
            ctx.font = `${this.fontPx}px system-ui, -apple-system, "Segoe UI", sans-serif`;

            const tEnd = t.length ? t[t.length - 1] : 0;
            const tStart = tEnd - this.win;

            let max = 0;
            for (const ser of o.series) {
                const v = this.s[ser.key];
                if (!v) continue;
                for (let i = 0; i < v.length; i++) if (t[i] >= tStart && v[i] > max) max = v[i];
            }
            const yMax = o.yMax ?? niceCeil(Math.max(o.minMax, max * 1.08) * o.scale) / o.scale;

            const top = o.format(yMax), mid = o.format(yMax / 2);
            const padR = bare ? 0 : Math.ceil(Math.max(ctx.measureText(top).width, ctx.measureText(mid).width)) + 10;
            const padT = bare ? 2 : Math.ceil(this.fontPx * 0.7);
            const padB = bare ? 2 : Math.ceil(this.fontPx * 1.6);
            const x0 = 0, x1 = w - padR, y0 = padT, y1 = h - padB;

            const X = (tt) => x0 + ((tt - tStart) / this.win) * (x1 - x0);
            const Y = (v) => y1 - (clamp(v, 0, yMax) / yMax) * (y1 - y0);

            // Gitter: feine Linien, eine Stufe ueber der Flaeche
            ctx.lineWidth = 1;
            ctx.strokeStyle = C.grid;
            for (const f of [1, 0.5]) {
                const y = Math.round(Y(yMax * f)) + 0.5;
                ctx.beginPath(); ctx.moveTo(x0, y); ctx.lineTo(x1, y); ctx.stroke();
            }
            ctx.strokeStyle = C.axis;
            ctx.beginPath(); ctx.moveTo(x0, Math.round(y1) + 0.5); ctx.lineTo(x1, Math.round(y1) + 0.5); ctx.stroke();

            if (!bare) {
                ctx.fillStyle = C.muted;
                ctx.textBaseline = 'middle';
                ctx.textAlign = 'left';
                ctx.fillText(top, x1 + 6, Y(yMax));
                ctx.fillText(mid, x1 + 6, Y(yMax / 2));
                ctx.textBaseline = 'alphabetic';
                ctx.fillText('−' + span(this.win), x0, h - 3);
                ctx.textAlign = 'right';
                ctx.fillText('jetzt', x1, h - 3);
            }

            ctx.save();
            ctx.beginPath();
            ctx.rect(x0, 0, x1 - x0, y1 + 1);
            ctx.clip();

            for (const ser of o.series) {
                const v = this.s[ser.key];
                if (!v) continue;

                // Luecken (null) unterbrechen die Linie statt sie auf 0 zu ziehen
                const segments = [];
                let seg = [];
                for (let i = 0; i < v.length; i++) {
                    if (v[i] === null || v[i] === undefined) { if (seg.length) segments.push(seg); seg = []; continue; }
                    seg.push([X(t[i]), Y(v[i])]);
                }
                if (seg.length) segments.push(seg);

                if (ser.fill) {
                    ctx.fillStyle = hexAlpha(ser.color, 0.16);
                    for (const s of segments) {
                        ctx.beginPath();
                        ctx.moveTo(s[0][0], y1);
                        for (const [px, py] of s) ctx.lineTo(px, py);
                        ctx.lineTo(s[s.length - 1][0], y1);
                        ctx.closePath();
                        ctx.fill();
                    }
                }

                ctx.strokeStyle = ser.color;
                ctx.lineWidth = 2;
                ctx.lineJoin = 'round';
                ctx.lineCap = 'round';
                for (const s of segments) {
                    ctx.beginPath();
                    s.forEach(([px, py], i) => (i ? ctx.lineTo(px, py) : ctx.moveTo(px, py)));
                    ctx.stroke();
                }
            }
            ctx.restore();

            this.drawHover(ctx, { X, Y, x0, x1, y0, y1, tStart });
        }

        drawHover(ctx, g) {
            const { t, o } = this;
            if (this.hoverX === null || !t.length || this.hoverX > g.x1) { this.tip.hidden = true; return; }

            const want = g.tStart + ((this.hoverX - g.x0) / (g.x1 - g.x0)) * this.win;
            let best = -1, dist = Infinity;
            for (let i = 0; i < t.length; i++) {
                const d = Math.abs(t[i] - want);
                if (d < dist) { dist = d; best = i; }
            }
            if (best < 0 || t[best] < g.tStart) { this.tip.hidden = true; return; }

            const x = Math.round(g.X(t[best])) + 0.5;
            ctx.strokeStyle = C.axis;
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(x, g.y0); ctx.lineTo(x, g.y1); ctx.stroke();

            this.tip.textContent = '';
            const time = new Date(t[best] * 1000).toLocaleTimeString('de-DE');
            this.tip.append(el('div', 't', time));

            for (const ser of o.series) {
                const v = this.s[ser.key] ? this.s[ser.key][best] : null;
                if (v === null || v === undefined) continue;
                // Punkt mit 2px Ring in Flaechenfarbe
                ctx.beginPath();
                ctx.arc(x, g.Y(v), 4, 0, Math.PI * 2);
                ctx.fillStyle = ser.color;
                ctx.strokeStyle = C.surface;
                ctx.lineWidth = 2;
                ctx.fill();
                ctx.stroke();

                const row = el('div');
                const sw = el('i', 'sw');
                sw.style.background = ser.color;
                row.append(sw, el('span', '', ser.label), el('b', '', o.format(v)));
                this.tip.append(row);
            }

            this.tip.hidden = false;
            const tw = this.tip.offsetWidth;
            this.tip.style.left = (x + 12 + tw > g.x1 ? x - 12 - tw : x + 12) + 'px';
        }
    }

    // --- Zustand -------------------------------------------------------------

    const state = {
        snap: null,
        generated: 0,
        started: null,
        offset: 0,
        seqs: {},
        hist: {},
        charts: {},
        rows: { net: new Map(), io: new Map(), gpu: new Map() },
        link: null,
        failStreak: 0,
    };

    // Ein einzelner haengender Poll (Tunnel-Jitter, kurzer Verbindungsaufbau)
    // darf die Anzeige nicht sofort auf "nicht erreichbar" springen lassen -
    // erst mehrere Fehlschlaege in Folge sind ein echter Ausfall.
    const FAIL_THRESHOLD = 3;

    const serverNow = () => Date.now() / 1000 + state.offset;

    function tierOf(key) {
        const tiers = (state.snap && state.snap.tiers) || {};
        for (const [name, tier] of Object.entries(tiers)) {
            if ((tier.keys || []).includes(key)) return [name, tier];
        }
        return [null, null];
    }

    function windowOf(tier) {
        return tier ? Math.max(10, (tier.history || 60) * (tier.interval_ms || 1000) / 1000) : 60;
    }

    /** Neue Verlaufspunkte an den eigenen Puffer haengen, auf die Taktlaenge kuerzen. */
    function mergeHistory(name, h, max) {
        const b = state.hist[name] || (state.hist[name] = { t: [], series: {} });
        const n = (h.t || []).length;
        const last = b.t.length ? b.t[b.t.length - 1] : -Infinity;

        let start = 0;
        while (start < n && h.t[start] <= last) start++;
        if (start >= n) return;

        const oldLen = b.t.length;
        const keys = new Set([...Object.keys(b.series), ...Object.keys(h.series || {})]);
        for (const k of keys) {
            if (!b.series[k]) b.series[k] = new Array(oldLen).fill(null);
            const src = (h.series || {})[k];
            for (let i = start; i < n; i++) b.series[k].push(src ? src[i] : null);
        }
        for (let i = start; i < n; i++) b.t.push(h.t[i]);

        const cut = b.t.length - max;
        if (cut > 0) {
            b.t.splice(0, cut);
            for (const k of Object.keys(b.series)) b.series[k].splice(0, cut);
        }
    }

    function feed(chart, key) {
        const [name, tier] = tierOf(key);
        const b = name && state.hist[name];
        if (b) chart.setData(b.t, b.series, windowOf(tier));
    }

    function beat(cardId) {
        const card = $(cardId);
        const p = card && card.querySelector('.pulse');
        if (!p) return;
        p.classList.remove('beat');
        void p.offsetWidth; // Animation neu starten
        p.classList.add('beat');
    }

    // --- Platz: erst verdichten, dann scrollen --------------------------------

    /**
     * Passt eine Liste (Schnittstellen, Datentraeger, Laufwerke) nicht mehr in
     * ihre Karte, wird sie verdichtet: eine Zeile je Eintrag, Diagramm als
     * Sparkline. Reicht auch das nicht, scrollt die Liste - auf dem Kiosk von
     * selbst, dort bedient niemand die Maus.
     */
    const fitHosts = new Set();

    function refit(host) {
        host.classList.remove('compact');
        if (host.scrollHeight > host.clientHeight + 2) host.classList.add('compact');
    }

    function fit(host) {
        if (!fitHosts.has(host)) {
            fitHosts.add(host);
            // Die Liste selbst beobachten, nicht die Karte: auch der Internet-Block
            // oder die Laufwerksliste darueber aendern ihren Platz
            if ('ResizeObserver' in window) new ResizeObserver(() => refit(host)).observe(host);
            else window.addEventListener('resize', () => refit(host));
            host.addEventListener('pointerenter', () => { host.dataset.hold = '1'; });
            host.addEventListener('pointerleave', () => { delete host.dataset.hold; });
        }
        refit(host);
    }

    /** Fuer Listen, die jede Sekunde neu gezeichnet werden: nur bei neuer Anzahl messen. */
    function fitCount(host, n) {
        if (host.dataset.n === String(n)) return;
        host.dataset.n = n;
        fit(host);
    }

    // Alle 6 s eine Seite weiter, am Ende zurueck nach oben. Solange die Maus
    // ueber der Liste steht, bleibt sie stehen.
    setInterval(() => {
        for (const host of fitHosts) {
            if (host.dataset.hold || host.scrollHeight <= host.clientHeight + 2) continue;
            const atEnd = host.scrollTop + host.clientHeight >= host.scrollHeight - 2;
            host.scrollTo({ top: atEnd ? 0 : host.scrollTop + host.clientHeight * 0.8, behavior: 'smooth' });
        }
    }, 6000);

    // --- Karten --------------------------------------------------------------

    function renderSystem(sys) {
        $('sys-host').textContent = sys.hostname || '–';
        $('sys-sub').textContent = [sys.cpu_model, sys.os, sys.kernel && 'Kernel ' + sys.kernel].filter(Boolean).join(' · ');
        $('sys-uptime').textContent = uptime(sys.uptime_s);
        $('sys-load').textContent = (sys.loadavg || []).map((v) => num(v, 2)).join(' / ');
        document.title = (sys.hostname || 'GaugeMetric') + ' · GaugeMetric';
    }

    function renderCpu(cpu) {
        $('cpu-total').textContent = num(cpu.total, 0);
        const mhz = cpu.cores.map((c) => c.mhz).filter(ok);
        $('cpu-mhz').textContent = mhz.length ? num(mhz.reduce((a, b) => a + b, 0) / mhz.length) + ' MHz' : '–';
        $('cpu-count').textContent = cpu.cores.length;
        renderCores(cpu.cores);

        const chart = state.charts.cpu || (state.charts.cpu = new TimeChart($('chart-cpu'), {
            series: [{ key: 'total', label: 'Auslastung', color: C.s1, fill: true }],
            yMax: 100,
            format: (v) => pct(v),
        }));
        feed(chart, 'cpu');
    }

    function renderCores(cores) {
        const host = $('cpu-cores');
        const dense = cores.length > 16;

        if (host.dataset.count !== String(cores.length)) {
            host.textContent = '';
            host.dataset.count = cores.length;
            host.classList.toggle('dense', dense);
            cores.forEach((c, i) => {
                if (dense) { host.append(el('div', 'cell')); return; }
                const row = el('div', 'core');
                const meter = el('span', 'meter');
                meter.append(el('i'));
                row.append(el('span', 'k', 'Kern ' + i), meter, el('span', 'p'), el('span', 'm'));
                host.append(row);
            });
            const legend = host.nextElementSibling && host.nextElementSibling.classList.contains('heat-legend') ? host.nextElementSibling : null;
            if (dense && !legend) {
                const l = el('div', 'heat-legend');
                l.append(el('span', '', '0 %'), el('i'), el('span', '', '100 % Last je Kern'));
                host.after(l);
            } else if (!dense && legend) {
                legend.remove();
            }
        }

        cores.forEach((c, i) => {
            const node = host.children[i];
            if (!node) return;
            if (dense) {
                node.style.background = HEAT[clamp(Math.floor((c.load / 100) * HEAT.length), 0, HEAT.length - 1)];
                node.title = `Kern ${i}: ${pct(c.load)}` + (ok(c.mhz) ? ` · ${num(c.mhz)} MHz` : '');
            } else {
                node.children[1].firstChild.style.width = clamp(c.load, 0, 100) + '%';
                node.children[2].textContent = pct(c.load);
                node.children[3].textContent = ok(c.mhz) ? num(c.mhz) + ' MHz' : '';
            }
        });
    }

    function sensorLabel(label) {
        return label
            .replace(/^Package id (\d+)$/, (m, id) => (id === '0' ? 'CPU-Paket' : 'CPU-Paket ' + id))
            .replace(/^Core (\d+)$/, 'Kern $1')
            .replace(/^Tctl$/, 'CPU (Tctl)')
            .replace(/^nvme Composite/, 'NVMe')
            .replace(/^nvme /, 'NVMe ');
    }

    function renderSensors(temps) {
        const host = $('sensor-chips');
        host.textContent = '';

        temps = temps || [];

        const cpuTemps = temps.filter((t) => t.group === 'cpu');
        const cpuMax = cpuTemps.length ? Math.max(...cpuTemps.map((t) => t.celsius)) : null;
        $('cpu-temp').textContent = ok(cpuMax) ? num(cpuMax) + ' °C' : '–';

        // Bei vielen Kernen nur Pakete zeigen - der Hoechstwert steht ohnehin oben
        let shown = temps;
        const coreTemps = cpuTemps.filter((t) => /^Core/.test(t.label));
        if (coreTemps.length > 6) shown = temps.filter((t) => !/^Core/.test(t.label));

        const chip = (label, value, level) => {
            const c = el('span', 'chip' + (level ? ' ' + level : ''));
            const b = el('b', '', (level ? '⚠ ' : '') + value);
            c.append(el('span', '', label), b);
            host.append(c);
        };

        for (const t of shown) {
            const level = t.celsius >= SENSOR_CRIT ? 'crit' : t.celsius >= SENSOR_WARN ? 'warn' : '';
            chip(sensorLabel(t.label), num(t.celsius) + ' °C', level);
        }
        if (!host.children.length) host.append(el('span', 'empty', 'Keine Sensoren gefunden'));
    }

    function renderMemory(m) {
        const [val, unit] = bytesParts(m.used);
        $('mem-used').textContent = val;
        $('mem-used-unit').textContent = ' ' + unit;
        $('mem-of').textContent = `in Verwendung von ${bytes(m.total)} (${pct(m.total ? (m.used / m.total) * 100 : null)})`;

        const [used, cache, free] = $('mem-stack').children;
        used.style.flexGrow = m.used || 0;
        cache.style.flexGrow = m.cache || 0;
        free.style.flexGrow = m.free || 0;

        $('mem-l-used').textContent = bytes(m.used);
        $('mem-l-cache').textContent = bytes(m.cache);
        $('mem-l-free').textContent = bytes(m.free);
        $('mem-avail').textContent = bytes(m.available);
        $('mem-swap').textContent = m.swap_total ? `${bytes(m.swap_used)} von ${bytes(m.swap_total)}` : 'keine';

        const chart = state.charts.mem || (state.charts.mem = new TimeChart($('chart-mem'), {
            series: [{ key: 'used', label: 'In Verwendung', color: C.s1, fill: true }],
            format: (v) => bytes(v),
        }));
        chart.o.yMax = m.total || null;
        feed(chart, 'memory');
    }

    /** Zeile fuer Netzwerk bzw. Datentraeger: Kopf, zwei Werte mit Legende, Diagramm, Fuss. */
    function buildRow(host, labels, chartOpts) {
        const empty = host.querySelector('.empty');
        if (empty) empty.remove();

        const row = el('div', 'row');
        const head = el('div', 'row-head');
        const name = el('span', 'name');
        const meta = el('span', 'meta');
        head.append(name, meta);

        const pair = el('div', 'pair');
        const values = labels.map(([label, color]) => {
            const box = el('div', 'val');
            const k = el('span', 'k');
            const sw = el('i', 'sw');
            sw.style.background = color;
            k.append(sw, document.createTextNode(label));
            const v = el('span', 'v', '–');
            box.append(k, v);
            pair.append(box);
            return v;
        });

        const chartHost = el('div', 'chart');
        const foot = el('div', 'row-foot');
        row.append(head, pair, chartHost, foot);
        host.append(row);

        return { row, name, meta, values, foot, chart: new TimeChart(chartHost, chartOpts) };
    }

    function dropMissing(map, seen, host) {
        for (const [key, r] of map) {
            if (!seen.has(key)) { r.row.remove(); map.delete(key); }
        }
        if (!map.size && !host.querySelector('.empty')) host.append(el('p', 'empty', 'Keine Einträge'));
    }

    function renderNetwork(list) {
        const host = $('net-list');
        const seen = new Set();

        for (const n of list) {
            const id = n.interface;
            seen.add(id);
            let r = state.rows.net.get(id);
            if (!r) {
                r = buildRow(host, [['Empfangen', C.s1], ['Gesendet', C.s2]], {
                    series: [
                        { key: 'rx:' + id, label: 'Empfangen', color: C.s1, fill: true },
                        { key: 'tx:' + id, label: 'Gesendet', color: C.s2 },
                    ],
                    minMax: 125000, // 1 Mbit/s
                    scale: 8,
                    format: bitRate,
                });
                state.rows.net.set(id, r);
            }

            r.name.textContent = '';
            const st = el('i', 'st ' + (n.state === 'up' ? 'up' : n.state === 'down' ? 'down' : ''));
            r.name.append(st, document.createTextNode(id));
            r.name.title = 'Status: ' + n.state;
            r.meta.textContent = [n.ipv4.join(', '), linkSpeed(n.speed_mbit)].filter(Boolean).join(' · ');
            r.values[0].textContent = bitRate(n.rx_bps);
            r.values[1].textContent = bitRate(n.tx_bps);
            r.foot.textContent = `Seit Systemstart: ↓ ${bytes(n.rx_total)} · ↑ ${bytes(n.tx_total)}`;
            feed(r.chart, 'network');
        }
        dropMissing(state.rows.net, seen, host);
        fitCount(host, state.rows.net.size);
    }

    function renderWan(w) {
        $('wan-ip').textContent = w.ip || '–';
        $('wan-ip').title = w.ip || '';
        $('wan-latency').textContent = ok(w.latency_ms) ? num(w.latency_ms, w.latency_ms < 10 ? 1 : 0) + ' ms' : '–';

        const foot = $('wan-foot');
        foot.textContent = [w.server, !w.error && ok(w.response_ms) && 'Antwort ' + num(w.response_ms) + ' ms'].filter(Boolean).join(' · ');
        if (w.error) foot.append(el('span', 'err', (foot.textContent ? ' · ' : '') + '⚠ ' + wanError(w.error)));

        const chart = state.charts.wan || (state.charts.wan = new TimeChart($('chart-wan'), {
            series: [{ key: 'latency', label: 'Latenz', color: C.s1, fill: true }],
            minMax: 20,
            format: (v) => num(v) + ' ms',
        }));
        feed(chart, 'wan');
    }

    function renderGpus(list) {
        const host = $('gpu-list');
        const seen = new Set();

        for (const g of list) {
            const id = String(g.id);
            seen.add(id);

            // Unified Memory (DGX Spark) meldet keinen eigenen Grafikspeicher: dann
            // weder Wert noch Linie, statt dauerhaft "–" und einer leeren Serie
            const hasMem = ok(g.memory_total);
            let r = state.rows.gpu.get(id);
            if (r && r.hasMem !== hasMem) { r.row.remove(); r = null; }
            if (!r) {
                const labels = [['Auslastung', C.s1]];
                const series = [{ key: 'util:' + id, label: 'Auslastung', color: C.s1, fill: true }];
                if (hasMem) {
                    labels.push(['Grafikspeicher', C.s2]);
                    series.push({ key: 'mem:' + id, label: 'Grafikspeicher', color: C.s2 });
                }
                r = buildRow(host, labels, { series, yMax: 100, format: (v) => pct(v) });
                r.hasMem = hasMem;
                state.rows.gpu.set(id, r);
            }

            // Bei einer Karte reicht der Name, bei mehreren braucht es den Index
            r.name.textContent = (list.length > 1 ? `GPU ${id} · ` : '') + g.name;
            r.name.title = g.name;

            r.meta.textContent = '';
            if (ok(g.celsius)) {
                const level = g.celsius >= SENSOR_CRIT ? 'crit' : g.celsius >= SENSOR_WARN ? 'warn' : '';
                r.meta.append(el('b', level, (level ? '⚠ ' : '') + num(g.celsius) + ' °C'));
            }
            const power = ok(g.watt) ? num(g.watt) + (ok(g.watt_limit) ? ' / ' + num(g.watt_limit) : '') + ' W' : '';
            if (power) r.meta.append(document.createTextNode((r.meta.childNodes.length ? ' · ' : '') + power));

            r.values[0].textContent = pct(g.percent);
            r.values[1].textContent = pct(g.memory_percent);
            r.foot.textContent = [
                ok(g.memory_total) && `${bytes(g.memory_used)} von ${bytes(g.memory_total)}`,
                ok(g.fan_percent) && 'Lüfter ' + pct(g.fan_percent),
                g.pstate,
                g.driver && 'Treiber ' + g.driver,
            ].filter(Boolean).join(' · ') || ' ';
            feed(r.chart, 'gpus');
        }
        dropMissing(state.rows.gpu, seen, host);
        fitCount(host, state.rows.gpu.size);
    }

    function renderDiskIo(list) {
        const host = $('io-list');
        const seen = new Set();

        for (const d of list) {
            const id = d.device;
            seen.add(id);
            let r = state.rows.io.get(id);
            if (!r) {
                r = buildRow(host, [['Lesen', C.s1], ['Schreiben', C.s2]], {
                    series: [
                        { key: 'read:' + id, label: 'Lesen', color: C.s1, fill: true },
                        { key: 'write:' + id, label: 'Schreiben', color: C.s2 },
                    ],
                    minMax: 1048576, // 1 MB/s
                    format: byteRate,
                });
                r.name.textContent = id;
                const active = el('span', 'active');
                const meter = el('span', 'meter');
                r.activeFill = el('i');
                meter.append(r.activeFill);
                r.activeText = el('span');
                active.append(el('span', '', 'Aktive Zeit'), meter, r.activeText);
                r.meta.append(active);
                state.rows.io.set(id, r);
            }

            r.values[0].textContent = byteRate(d.read_bps);
            r.values[1].textContent = byteRate(d.write_bps);
            r.activeFill.style.width = clamp(d.active_percent || 0, 0, 100) + '%';
            r.activeText.textContent = pct(d.active_percent);
            feed(r.chart, 'disk_io');
        }
        dropMissing(state.rows.io, seen, host);
        fitCount(host, state.rows.io.size);
    }

    const DRIVE_ICON = '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">'
        + '<rect x="5" y="12" width="38" height="24" rx="4"/><path d="M5 27h38"/>'
        + '<circle cx="36" cy="31.5" r="1.8" fill="currentColor" stroke="none"/><path d="M10 31.5h14"/></svg>';

    function renderFilesystems(list) {
        const warn = (CFG.storage && CFG.storage.warn_percent) || 80;
        const crit = (CFG.storage && CFG.storage.critical_percent) || 90;
        const host = $('fs-list');
        host.textContent = '';

        const sorted = [...list].sort((a, b) => (a.mount === '/' ? -1 : b.mount === '/' ? 1 : a.mount.localeCompare(b.mount)));
        let total = 0, free = 0;

        for (const fs of sorted) {
            total += fs.total;
            free += fs.free;

            const level = fs.percent >= crit ? 'crit' : fs.percent >= warn ? 'warn' : '';
            const tile = el('div', 'drive' + (level ? ' ' + level : ''));
            tile.innerHTML = DRIVE_ICON;

            const name = fs.mount === '/' ? 'System' : fs.mount.split('/').filter(Boolean).pop() || fs.mount;
            const title = el('div', 'title');
            title.append(el('span', '', `${name} (${fs.mount})`), el('small', '', pct(fs.percent) + ' belegt'));

            const bar = el('div', 'bar');
            const fill = el('i');
            fill.style.width = clamp(fs.percent, 0, 100) + '%';
            bar.append(fill);
            bar.title = `${bytes(fs.used)} belegt`;

            const freeLine = el('div', 'free', `${bytes(fs.free)} frei von ${bytes(fs.total)}`);
            if (level) {
                const s = el('span', 'state', level === 'crit' ? ' · ⚠ Kritisch voll' : ' · ⚠ Wird knapp');
                freeLine.append(s);
            }

            tile.append(title, bar, freeLine, el('div', 'dev', [fs.device, fs.fstype].filter(Boolean).join(' · ')));
            host.append(tile);
        }

        const count = sorted.length;
        $('fs-summary').textContent = count
            ? `${count} ${count === 1 ? 'Laufwerk' : 'Laufwerke'} · ${bytes(free)} frei von ${bytes(total)} gesamt`
            : 'Keine Laufwerke gefunden';
        fit(host);
    }

    const SSD_ICON = '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">'
        + '<rect x="6" y="10" width="36" height="28" rx="3"/><rect x="12" y="16" width="10" height="8" rx="1"/>'
        + '<rect x="26" y="16" width="10" height="8" rx="1"/><path d="M12 31h24"/></svg>';

    // Herstellerangaben sind dezimal: eine "4 TB"-Platte soll auch 4,0 TB heissen
    function capacity(b) {
        if (!ok(b)) return '';
        return b >= 1e12 ? num(b / 1e12, 1) + ' TB' : num(b / 1e9, 0) + ' GB';
    }

    function driveKind(d) {
        if (d.kind === 'nvme') return 'NVMe-SSD';
        if (d.kind === 'ssd') return 'SSD';
        if (d.kind === 'hdd') return 'HDD' + (d.rpm > 0 ? ` ${num(d.rpm)} U/min` : '');
        return '';
    }

    function gauge(label, value, percent, level) {
        const box = el('div', 'gauge' + (level ? ' ' + level : ''));
        const head = el('div', 'g-head');
        head.append(el('span', '', label), el('b', '', (level ? '⚠ ' : '') + value));
        const meter = el('span', 'meter');
        const fill = el('i');
        fill.style.width = clamp(percent, 0, 100) + '%';
        meter.append(fill);
        box.append(head, meter);
        return box;
    }

    /** Gelesen / Geschrieben in allen Einheiten - die Umrechnung liefert cSysinfo. */
    function transferTable(d) {
        const rows = [['Gelesen', d.read], ['Geschrieben', d.written]].filter(([, v]) => v);
        if (!rows.length) return el('div', 'xfer-none', 'Lese-/Schreibzähler meldet das Laufwerk nicht');

        const table = el('table', 'xfer');
        const head = el('tr');
        for (const h of ['', 'TB', 'GB', 'Mbit', 'bit']) head.append(el('th', '', h));
        table.append(head);
        for (const [label, v] of rows) {
            const tr = el('tr');
            tr.append(el('th', '', label), el('td', '', num(v.tb, 2)), el('td', '', num(v.gb, 1)),
                el('td', '', num(v.mbit, 0)), el('td', '', num(v.bit, 0)));
            table.append(tr);
        }
        return table;
    }

    function smartTile(d, design) {
        const hasData = ok(d.power_on_hours) || d.health !== '';
        const tile = el('div', 'disk');
        tile.innerHTML = d.kind === 'hdd' ? DRIVE_ICON : SSD_ICON;

        const title = el('div', 'title');
        title.append(el('span', '', [d.device, d.model].filter(Boolean).join(' · ')));
        let badge;
        if (d.healthy === true) badge = el('small', 'badge good', '✓ ' + (d.health === 'PASSED' ? 'OK' : d.health));
        else if (d.healthy === false) badge = el('small', 'badge crit', '⚠ ' + d.health);
        else if (d.state === 'standby') badge = el('small', 'badge', 'Standby');
        else badge = el('small', 'badge', '–');
        title.append(badge);
        tile.append(title);
        title.title = [d.model, d.serial && 'S/N ' + d.serial, d.firmware && 'Firmware ' + d.firmware].filter(Boolean).join(' · ');

        let level = d.healthy === false ? 'crit' : '';
        const raise = (l) => { if (l === 'crit' || (l === 'warn' && !level)) level = l; };

        if (!hasData) {
            const text = d.state === 'standby' ? '◐ Im Standby – wird für SMART nicht aufgeweckt' : '⚠ ' + smartError(d.error || 'no-data');
            tile.append(el('div', 'note', text));
            tile.classList.toggle('muted', true);
            return tile;
        }

        const temp = ok(d.celsius) ? num(d.celsius) + ' °C' : '';
        tile.append(el('div', 'meta', [driveKind(d), capacity(d.capacity), temp].filter(Boolean).join(' · ')));

        // Lebensdauer: SSDs melden ihren Verschleiss, Festplatten nicht - dort
        // zaehlt die Betriebszeit gegen die angenommene Auslegung (design_hours)
        const gauges = el('div', 'gauges');
        if (ok(d.life_left)) {
            const l = d.life_left <= LIFE_CRIT ? 'crit' : d.life_left <= LIFE_WARN ? 'warn' : '';
            raise(l);
            gauges.append(gauge('Lebensdauer', pct(d.life_left) + ' übrig', d.life_left, l));
        } else if (d.kind === 'hdd' && design > 0) {
            const left = Math.max(0, 100 - (d.power_on_hours / design) * 100);
            gauges.append(gauge(`Auslegung (${num(design / HOURS_PER_YEAR)} J)`, pct(left) + ' übrig', left, left <= LIFE_CRIT ? 'warn' : ''));
        }
        if (ok(d.spare)) {
            const thr = d.spare_threshold || 0;
            const l = d.spare <= thr ? 'crit' : d.spare <= thr + 20 ? 'warn' : '';
            raise(l);
            const blocks = ok(d.unused_reserve_blocks) ? ` · ${num(d.unused_reserve_blocks)} frei` : '';
            gauges.append(gauge('Reservesektoren', pct(d.spare) + blocks, d.spare, l));
        }
        if (gauges.children.length) tile.append(gauges);

        const stats = el('dl', 'stats disk-stats');
        // minor: verdichtet ausgeblendet
        const stat = (k, v, cls, minor) => {
            if (!v) return;
            const m = minor ? ' minor' : '';
            stats.append(el('dt', m.trim(), k), el('dd', ((cls || '') + m).trim(), v));
        };

        stat('Betriebszeit', ok(d.power_on_hours) ? `${hoursSpan(d.power_on_hours)} (${num(d.power_on_hours)} Std)` : '');
        stat('Einschaltungen', ok(d.power_cycles)
            ? num(d.power_cycles) + (ok(d.hours_per_cycle) ? ` · Ø ${num(d.hours_per_cycle, d.hours_per_cycle < 10 ? 1 : 0)} Std je Lauf` : '')
            : '', '', true);

        const basis = { wear: 'nach Verschleiß', spare: 'nach Reserveverbrauch', design: 'nach Auslegung' }[d.remaining_basis] || '';
        let rest = remainingSpan(d.remaining_hours);
        let restCls = '';
        if (rest === '0') {
            rest = d.remaining_basis === 'design' ? '⚠ Auslegung überschritten' : '⚠ aufgebraucht';
            restCls = d.remaining_basis === 'design' ? 'warn' : 'crit';
            raise(restCls);
        } else if (rest) {
            rest += ' · ' + basis;
        } else if (ok(d.life_left) && d.life_left >= 100) {
            rest = 'noch kein messbarer Verschleiß';
        }
        stat('Restlaufzeit (theor.)', rest, restCls);

        // Sektoren und Fehlerzaehler - ausstehende und nicht korrigierbare Sektoren
        // sind die fruehesten Vorboten eines Plattenausfalls
        const errs = [];
        const count = (v, text, l) => {
            if (!ok(v)) return;
            if (v > 0 && l) raise(l);
            errs.push((v > 0 && l ? '⚠ ' : '') + num(v) + ' ' + text);
        };
        count(d.reallocated, 'ersetzt', d.kind === 'nvme' ? '' : 'warn');
        count(d.pending, 'ausstehend', 'warn');
        count(d.uncorrectable, 'nicht korrigierbar', 'crit');
        count(d.media_errors, 'Medienfehler', 'crit');
        count(d.unsafe_shutdowns, '× unsicher aus', '');
        stat(d.kind === 'nvme' ? 'Fehler' : 'Sektoren', errs.join(' · '));
        tile.append(stats);

        tile.append(transferTable(d));
        // Verdichtet ersetzt diese eine Zeile die Tabelle
        const short = [d.read && '↓ ' + num(d.read.tb, 2) + ' TB', d.written && '↑ ' + num(d.written.tb, 2) + ' TB'].filter(Boolean).join(' · ');
        tile.append(el('div', 'xfer-short', short || ' '));

        if (d.state === 'standby') tile.append(el('div', 'note', '◐ Im Standby – Werte der letzten Messung'));
        if (level) tile.classList.add(level);
        return tile;
    }

    function renderSmart(s) {
        const host = $('smart-list');
        host.textContent = '';
        const list = s.devices || [];

        if (s.error) host.append(el('p', 'empty', '⚠ ' + smartError(s.error)));
        else if (!list.length) host.append(el('p', 'empty', 'Keine Laufwerke gefunden'));
        for (const d of list) host.append(smartTile(d, s.design_hours));

        // Kacheln wechseln zwischen Daten und Hinweis - jedes Mal neu messen
        fit(host);
    }

    function renderFoot(tiers) {
        const foot = $('foot');
        foot.textContent = '';
        for (const tier of Object.values(tiers)) {
            const item = el('span');
            item.append(el('b', '', tier.label), document.createTextNode(
                ` ${every(tier.interval_ms)} · Dauer ${num(tier.duration_ms, 0)} ms · Jitter ${num(tier.jitter_ms, 1)} ms`
                + (tier.missed ? ` · ${tier.missed} ausgelassen` : '')
            ));
            if (tier.error) item.append(el('span', 'err', ' · ' + tier.error));
            foot.append(item);
        }

        // Stand des sysinfo-Skripts; ein fehlgeschlagenes Ersetzen von
        // /usr/bin/sysinfo muss auf dem Schirm auffallen, nicht nur im Log
        const si = CFG.sysinfo || {};
        if (si.version || si.error) {
            const item = el('span');
            item.append(el('b', '', 'sysinfo'), document.createTextNode(si.version ? ' ' + si.version : ''));
            if (si.error) item.append(el('span', 'err', ' · ' + si.error));
            foot.append(item);
        }
    }

    // --- Status --------------------------------------------------------------

    function setLink(level, text) {
        const link = $('link');
        link.className = 'link ' + level;
        $('link-text').textContent = (level === 'ok' ? '' : '⚠ ') + text;
        $('kiosk').classList.toggle('offline', level === 'err');
    }

    function checkStale() {
        const snap = state.snap;
        if (!snap || !snap.tiers) return;
        const now = serverNow();

        for (const [key, cardId] of Object.entries(CARDS)) {
            const [, tier] = tierOf(key);
            const card = $(cardId);
            if (!card || !tier) continue;

            const age = tier.ts ? now - tier.ts : Infinity;
            const limit = Math.max((3 * tier.interval_ms) / 1000, tier.interval_ms / 1000 + 2);
            const stale = age > limit;

            card.classList.toggle('stale', stale);
            const note = card.querySelector('.stale-note');
            if (note) {
                note.hidden = !stale;
                note.textContent = stale ? (Number.isFinite(age) ? `⚠ keine Daten seit ${span(age)}` : '⚠ keine Daten') : '';
            }
        }
    }

    // --- Abruf ---------------------------------------------------------------

    function apply(snap) {
        if (state.started !== null && snap.poller && snap.poller.started !== state.started) {
            state.hist = {}; // Poller neu gestartet: alter Verlauf passt nicht mehr
        }
        state.started = snap.poller ? snap.poller.started : null;
        state.snap = snap;
        state.generated = snap.generated || 0;
        state.offset = (snap.now || Date.now() / 1000) - Date.now() / 1000;

        for (const [name, h] of Object.entries(snap.history || {})) {
            mergeHistory(name, h, (snap.tiers[name] || {}).history || 120);
        }

        const d = snap.data || {};
        const fresh = (key) => {
            const [name, tier] = tierOf(key);
            if (!name) return false;
            const was = state.seqs[key];
            state.seqs[key] = tier.seq;
            return was !== tier.seq;
        };

        // Taktanzeige an den Karten
        for (const [key, cardId] of Object.entries(CARDS)) {
            const [, tier] = tierOf(key);
            const card = $(cardId);
            const label = card && card.querySelector('.every');
            if (label && tier) label.textContent = 'alle ' + every(tier.interval_ms);
        }

        if (d.system && fresh('system')) renderSystem(d.system);
        if (d.cpu && fresh('cpu')) { renderCpu(d.cpu); beat('card-cpu'); }
        if (d.temperatures && fresh('temperatures')) { renderSensors(d.temperatures); beat('sensors'); }
        if (d.memory && fresh('memory')) { renderMemory(d.memory); beat('card-mem'); }
        if (d.network && fresh('network')) { renderNetwork(d.network); beat('card-net'); }
        // Ohne wan-Takt in config.json bleibt der Block aus, statt ewig "–" zu zeigen
        $('net-wan').hidden = !tierOf('wan')[0];
        if (d.wan && fresh('wan')) { renderWan(d.wan); beat('net-wan'); }
        // Grafikkarte nur, wenn nvidia-smi eine meldet - sonst behalten I/O und
        // Laufwerke ihre volle Breite
        const hasGpu = !!tierOf('gpus')[0] && (d.gpus || []).length > 0;
        $('card-gpu').hidden = !hasGpu;
        $('grid').classList.toggle('has-gpu', hasGpu);
        if (hasGpu && fresh('gpus')) { renderGpus(d.gpus); beat('card-gpu'); }
        if (d.disk_io && fresh('disk_io')) { renderDiskIo(d.disk_io); beat('card-io'); }
        if (d.filesystems && fresh('filesystems')) { renderFilesystems(d.filesystems); beat('card-fs'); }
        // Ohne smart-Takt in config.json bleibt der Block aus
        $('fs-smart').hidden = !tierOf('smart')[0];
        if (d.smart && fresh('smart')) { renderSmart(d.smart); beat('fs-smart'); }

        renderFoot(snap.tiers || {});

        const errors = Object.values(snap.tiers || {}).filter((t) => t.error);
        if (snap.poller && snap.poller.alive === false) setLink('err', 'Poller gestoppt');
        else if (errors.length) setLink('warn', 'Messfehler');
        else setLink('ok', 'Live');

        checkStale();
    }

    async function poll() {
        const started = performance.now();
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 3000);

        try {
            const url = CFG.api + (state.generated ? '?since=' + state.generated : '');
            const res = await fetch(url, { cache: 'no-store', signal: ctrl.signal });
            const body = await res.json();
            if (res.status === 503) {
                state.failStreak = 0;
                setLink('warn', 'Warte auf Poller …');
            } else if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            } else {
                state.failStreak = 0;
                apply(body);
            }
        } catch (e) {
            state.failStreak++;
            if (state.failStreak >= FAIL_THRESHOLD) setLink('err', 'Server nicht erreichbar');
            checkStale();
        } finally {
            clearTimeout(timer);
        }

        const pollMs = CFG.poll_ms || 250;
        setTimeout(poll, Math.max(50, pollMs - (performance.now() - started)));
    }

    function tickClock() {
        const now = new Date(serverNow() * 1000);
        $('clock-time').textContent = now.toLocaleTimeString('de-DE');
        $('clock-date').textContent = now.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
        checkStale();
    }

    tickClock();
    setInterval(tickClock, 1000);
    poll();
})();
