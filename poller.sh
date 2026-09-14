#!/usr/bin/env bash
set -euo pipefail

# Steuert den Hintergrund-Poller (cGaugePoller) - Gegenstueck zu webserver.sh.
# Getrennte Prozesse: der Poller misst, der Webserver zeigt nur den Snapshot an.
# Stirbt die Ansicht, laeuft die Messung weiter und umgekehrt.

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/"
CONFIG="${DIR}config.json"

[[ -r "$CONFIG" ]] || { echo "poller: $CONFIG nicht lesbar" >&2; exit 1; }

# Liest einen jq-Pfad aus der config.json und loest {dir} auf; ohne jq springt PHP ein.
cfg() {
    local filter="$1" default="${2-}" value
    if command -v jq >/dev/null 2>&1; then
        value="$(jq -r "${filter} // empty" "$CONFIG")"
    else
        value="$(php -r '
            $c = json_decode(file_get_contents($argv[1]), true);
            $v = $c;
            foreach (array_filter(explode(".", trim($argv[2], ".")), "strlen") as $k) {
                $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null;
            }
            echo is_array($v) ? "" : (string)$v;
        ' "$CONFIG" "${filter#.}")"
    fi
    [[ -n "$value" ]] || value="$default"
    echo "${value//\{dir\}/$DIR}"
}

PID_FILE="$(cfg '.gauge.pid_file' "${DIR}log/poller.pid")"
LOCK_FILE="$(cfg '.gauge.lock_file' "${DIR}log/poller.lock")"
LOG_FILE="$(cfg '.gauge.console_log' "${DIR}log/poller.out")"
SNAPSHOT="$(cfg '.gauge.snapshot' "${DIR}db/metrics.json")"

mkdir -p "$(dirname "$LOCK_FILE")" "$(dirname "$PID_FILE")" "$(dirname "$LOG_FILE")"

# exec ersetzt die Subshell durch PHP: gleiche PID wie in der PID-Datei, und die
# geerbte flock-Sperre auf FD 9 faellt erst mit dem PHP-Prozess.
serve() {
    echo "$BASHPID" > "$PID_FILE"
    exec php -r 'require $argv[1]; exit($GLOBALS["cGaugePoller"]->run() ? 0 : 1);' -- "${DIR}autoload.php"
}

start() {
    local mode="${1:-background}"

    flock -n 9 || {
        echo "poller: laeuft bereits (PID $(cat "$PID_FILE" 2>/dev/null || echo '?')) - kein zweiter Start"
        exit 0
    }

    if [[ "$mode" == "foreground" ]]; then
        echo "poller: Vordergrund, Snapshot ${SNAPSHOT} - Strg+C beendet"
        serve
    else
        serve >>"$LOG_FILE" 2>&1 &
        local pid=$!
        # Der Vorlauf misst einmal vorab - erst danach steht fest, ob sysinfo laeuft.
        sleep 1.5
        if kill -0 "$pid" 2>/dev/null; then
            echo "poller: gestartet (PID $pid), Snapshot ${SNAPSHOT}, Log ${LOG_FILE}"
        else
            echo "poller: Start fehlgeschlagen - siehe ${LOG_FILE}" >&2
            tail -n 5 "$LOG_FILE" >&2 || true
            exit 1
        fi
    fi
}

# Schuetzt vor PID-Wiederverwendung: die Kommandozeile muss unser Poller sein.
owns_pid() {
    local pid="$1" cmdline
    [[ -n "$pid" && "$pid" =~ ^[0-9]+$ ]] || return 1
    kill -0 "$pid" 2>/dev/null || return 1

    cmdline="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)"
    [[ "$cmdline" == *"php"* && "$cmdline" == *"cGaugePoller"* && "$cmdline" == *"$DIR"* ]]
}

running_pid() {
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    owns_pid "$pid" && echo "$pid"
}

stop() {
    local pid
    pid="$(running_pid || true)"
    if [[ -n "$pid" ]]; then
        kill "$pid"
        for _ in {1..30}; do
            kill -0 "$pid" 2>/dev/null || break
            sleep 0.2
        done
        kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null || true
        echo "poller: gestoppt (PID $pid)"
    else
        echo "poller: laeuft nicht"
    fi
    rm -f "$PID_FILE"
}

status() {
    local pid
    pid="$(running_pid || true)"
    if [[ -n "$pid" ]]; then
        echo "poller: laeuft (PID $pid)"
        if command -v jq >/dev/null 2>&1 && [[ -r "$SNAPSHOT" ]]; then
            jq -r '.tiers | to_entries[] | "  \(.key): \(.value.interval_ms) ms, Messung \(.value.duration_ms) ms, Jitter \(.value.jitter_ms) ms, verpasst \(.value.missed)\(if .value.error then ", Fehler: " + .value.error else "" end)"' "$SNAPSHOT"
        fi
    else
        echo "poller: laeuft nicht"
        return 1
    fi
}

wait_for_lock() {
    local tries="${1:-50}" i
    for ((i = 0; i < tries; i++)); do
        if ( flock -n 8 ) 8>>"$LOCK_FILE"; then
            return 0
        fi
        sleep 0.1
    done
    return 1
}

case "${1:-start}" in
    start)      start background 9>>"$LOCK_FILE" ;;
    foreground) start foreground 9>>"$LOCK_FILE" ;;
    stop)       stop ;;
    restart)    stop
                wait_for_lock || echo "poller: Sperre noch belegt - Start wird trotzdem versucht" >&2
                start background 9>>"$LOCK_FILE" ;;
    status)     status ;;
    *)          echo "Aufruf: $0 [start|stop|restart|status|foreground]" >&2; exit 2 ;;
esac
