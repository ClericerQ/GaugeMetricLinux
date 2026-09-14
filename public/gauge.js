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
    const CARDS = { cpu: 'card-cpu', memory: 'card-mem', network: 'card-net', disk_io: 'card-io', filesystems: 'card-fs', temperatures: 'sensors' };

    const SENSOR_WARN = 80;
    const SENSOR_CRIT = 95;

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
            if (w < 30 || h < 30) return;

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
            const padR = Math.ceil(Math.max(ctx.measureText(top).width, ctx.measureText(mid).width)) + 10;
            const padT = Math.ceil(this.fontPx * 0.7);
            const padB = Math.ceil(this.fontPx * 1.6);
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

            ctx.fillStyle = C.muted;
            ctx.textBaseline = 'middle';
            ctx.textAlign = 'left';
            ctx.fillText(top, x1 + 6, Y(yMax));
            ctx.fillText(mid, x1 + 6, Y(yMax / 2));
            ctx.textBaseline = 'alphabetic';
            ctx.fillText('−' + span(this.win), x0, h - 3);
            ctx.textAlign = 'right';
            ctx.fillText('jetzt', x1, h - 3);

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
        rows: { net: new Map(), io: new Map() },
        link: null,
    };

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

    function renderSensors(temps, gpus) {
        const host = $('sensor-chips');
        host.textContent = '';

        temps = temps || [];
        gpus = gpus || [];

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
        for (const g of gpus) {
            const parts = [ok(g.percent) && pct(g.percent), ok(g.celsius) && num(g.celsius) + ' °C', ok(g.watt) && num(g.watt) + ' W'].filter(Boolean);
            chip(`GPU ${g.id} ${g.name}`, parts.join(' · ') || '–', ok(g.celsius) && g.celsius >= SENSOR_WARN ? 'warn' : '');
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
        if (d.temperatures && fresh('temperatures')) { renderSensors(d.temperatures, d.gpus); beat('sensors'); }
        if (d.memory && fresh('memory')) { renderMemory(d.memory); beat('card-mem'); }
        if (d.network && fresh('network')) { renderNetwork(d.network); beat('card-net'); }
        if (d.disk_io && fresh('disk_io')) { renderDiskIo(d.disk_io); beat('card-io'); }
        if (d.filesystems && fresh('filesystems')) { renderFilesystems(d.filesystems); beat('card-fs'); }

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
                setLink('warn', 'Warte auf Poller …');
            } else if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            } else {
                apply(body);
            }
        } catch (e) {
            setLink('err', 'Server nicht erreichbar');
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
