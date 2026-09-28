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

SVC_POLLER="$(cfg '.service.poller' 'gaugemetric-poller')"
SVC_UNIT_DIR="$(cfg '.service.unit_dir' '/etc/systemd/system')"

mkdir -p "$(dirname "$LOCK_FILE")" "$(dirname "$PID_FILE")" "$(dirname "$LOG_FILE")"

# Eingerichtet wird der Dienst von "webserver.sh service-install". Nur eine
# Unit, die auf diese Projektkopie zeigt, wird ferngesteuert.
service_managed() {
    command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]] \
        && grep -qsF "\"${DIR}poller.sh\"" "${SVC_UNIT_DIR}/${SVC_POLLER}.service"
}

# Ein Handstart neben dem Dienst hielte die Sperre; der Dienst endete dann mit
# "laeuft bereits" und der Poller hinge an einer Shell statt an systemd.
service_ctl() {
    local sudo=""
    if [[ "$(id -u)" != 0 ]]; then
        command -v sudo >/dev/null 2>&1 || { echo "poller: Dienst ${SVC_POLLER} steuern braucht root oder sudo" >&2; exit 1; }
        sudo="sudo"
    fi
    $sudo systemctl reset-failed "$SVC_POLLER" 2>/dev/null || true
    $sudo systemctl "$1" "$SVC_POLLER"
    echo "poller: $1 ueber systemd - ${SVC_POLLER} $(systemctl is-active "$SVC_POLLER" 2>/dev/null || true)"
}

# exec ersetzt die Subshell durch PHP: gleiche PID wie in der PID-Datei, und die
# geerbte flock-Sperre auf FD 9 faellt erst mit dem PHP-Prozess.
serve() {
    echo "$BASHPID" > "$PID_FILE"
    exec php -r 'require $argv[1]; exit($GLOBALS["cGaugePoller"]->run() ? 0 : 1);' -- "${DIR}autoload.php"
}

start() {
    local mode="${1:-background}"

    # Im Dienst (LOCK_WAIT=1) auf eine andere Instanz warten statt zu enden -
    # sonst fiele die Unit still auf "inactive" oder liefe in Neustarts.
    if ! flock -n 9 && [[ "$mode" == foreground && "${LOCK_WAIT:-0}" == 1 ]]; then
        echo "poller: Sperre ${LOCK_FILE} von anderer Instanz belegt (PID $(cat "$PID_FILE" 2>/dev/null || echo '?')) - warte, bis sie endet"
        flock 9
        echo "poller: Sperre frei - starte"
    fi

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
    start)      if service_managed; then service_ctl start; else start background 9>>"$LOCK_FILE"; fi ;;
    foreground) start foreground 9>>"$LOCK_FILE" ;;
    stop)       if service_managed; then service_ctl stop; else stop; fi ;;
    restart)    if service_managed; then
                    service_ctl restart
                else
                    stop
                    wait_for_lock || echo "poller: Sperre noch belegt - Start wird trotzdem versucht" >&2
                    start background 9>>"$LOCK_FILE"
                fi ;;
    status)     status ;;
    *)          echo "Aufruf: $0 [start|stop|restart|status|foreground]" >&2; exit 2 ;;
esac
