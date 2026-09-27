#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/"
CONFIG="${DIR}config.json"

[[ -r "$CONFIG" ]] || { echo "webserver: $CONFIG nicht lesbar" >&2; exit 1; }

# Fehlt PHP, kommt es aus dem Sury-Repository - die Distributionen hinken bei
# PHP-Versionen hinterher. Architektur und Release werden vom System gelesen,
# damit dieselbe Zeile auf Raspberry Pi (arm64/armhf) und PC (amd64) passt.
# Sury bedient nur Debian-Codenamen; auf Ubuntu liefe apt update in einen 404.
PHP_INSTALL_VERSION="8.4"

ensure_php() {
    command -v php >/dev/null 2>&1 && return 0

    local sudo="" arch codename id
    if [[ "$(id -u)" != 0 ]]; then
        command -v sudo >/dev/null 2>&1 || { echo "webserver: PHP fehlt - Installation braucht root oder sudo" >&2; exit 1; }
        sudo="sudo"
    fi

    id="$(. /etc/os-release 2>/dev/null && echo "${ID:-}")"
    case "$id" in
        debian|raspbian) ;;
        *) echo "webserver: PHP fehlt - Sury-Installation nur fuer Debian/Raspberry Pi OS, hier '${id:-unbekannt}'" >&2; exit 1 ;;
    esac

    echo "webserver: PHP fehlt - installiere PHP ${PHP_INSTALL_VERSION} aus packages.sury.org"
    export DEBIAN_FRONTEND=noninteractive
    $sudo apt-get update
    $sudo apt-get install -y apt-transport-https lsb-release ca-certificates curl gnupg2

    arch="$(dpkg --print-architecture)"
    codename="$(. /etc/os-release 2>/dev/null && echo "${VERSION_CODENAME:-}")"
    [[ -n "$codename" ]] || codename="$(lsb_release -sc)"

    # --yes: ein Schluessel von einem abgebrochenen Lauf wird ueberschrieben statt nachzufragen.
    curl -fsSL https://packages.sury.org/php/apt.gpg | $sudo gpg --dearmor --yes -o /usr/share/keyrings/deb.sury.org-php.gpg
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg arch=${arch}] https://packages.sury.org/php/ ${codename} main" \
        | $sudo tee /etc/apt/sources.list.d/php.list >/dev/null
    $sudo apt-get update
    $sudo apt-get install -y "php${PHP_INSTALL_VERSION}" "php${PHP_INSTALL_VERSION}-cli" "php${PHP_INSTALL_VERSION}-common" \
        "php${PHP_INSTALL_VERSION}-fpm" "php${PHP_INSTALL_VERSION}-mbstring" "php${PHP_INSTALL_VERSION}-curl" \
        "php${PHP_INSTALL_VERSION}-sqlite3"

    command -v php >/dev/null 2>&1 || { echo "webserver: PHP-Installation fehlgeschlagen" >&2; exit 1; }
}

# Vor cfg(): ohne jq liest schon die Konfiguration mit PHP.
case "${1:-start}" in
    start|foreground|restart) ensure_php ;;
esac

# Liest einen jq-Pfad aus der config.json und loest {dir} auf; ohne jq springt PHP ein.
cfg() {
    local filter="$1" default="${2-}" value
    if command -v jq >/dev/null 2>&1; then
        value="$(jq -r "${filter} // empty" "$CONFIG")"
    else
        value="$(php -r '
            $c = json_decode(file_get_contents($argv[1]), true);
            $v = $c;
            $path = rtrim(trim($argv[2], "."), "[]");
            foreach (array_filter(explode(".", $path), "strlen") as $k) {
                $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null;
            }
            echo is_array($v) ? implode("\n", $v) : (string)$v;
        ' "$CONFIG" "${filter#.}")"
    fi
    [[ -n "$value" ]] || value="$default"
    echo "${value//\{dir\}/$DIR}"
}

HOST="$(cfg '.web.host' '0.0.0.0')"
PORT="$(cfg '.web.port' '8080')"
DOCROOT="$(cfg '.web.docroot' "${DIR}public/")"
PID_FILE="$(cfg '.web.pid_file' "${DIR}log/webserver.pid")"
LOCK_FILE="$(cfg '.web.lock_file' "${DIR}log/webserver.lock")"
LOG_FILE="$(cfg '.web.access_log' "${DIR}log/webserver.log")"
mapfile -t LAUNCH_IPS < <(cfg '.web.launch_ips[]' '127.0.0.1')

# Kiosk: "auto" oeffnet das Dashboard nur, wenn am Geraet selbst eine grafische
# Sitzung laeuft. KIOSK=off ./webserver.sh erzwingt den reinen Listener.
KIOSK_MODE="${KIOSK:-$(cfg '.kiosk.mode' 'auto')}"
KIOSK_URL="$(cfg '.kiosk.url' 'http://127.0.0.1:{port}/')"
KIOSK_PROFILE_TPL="$(cfg '.kiosk.profile_dir' '{home}/.config/gaugemetric-kiosk')"
KIOSK_LOG="$(cfg '.kiosk.log_file' "${DIR}log/kiosk.log")"
KIOSK_WAIT="$(cfg '.kiosk.wait_seconds' '15')"
mapfile -t KIOSK_BROWSERS < <(cfg '.kiosk.browsers[]?' 'chromium')
mapfile -t KIOSK_FLAGS < <(cfg '.kiosk.extra_flags[]?' '')
KIOSK_URL="${KIOSK_URL//\{port\}/$PORT}"
# Unbekannte Schalter ignoriert Chromium - der Marker findet "unsere" Instanz
# wieder, egal fuer welchen Benutzer und mit welchem Profil sie gestartet wurde.
KIOSK_MARKER="--gaugemetric-kiosk=${DIR}"

[[ "$KIOSK_WAIT" =~ ^[0-9]+$ ]] || KIOSK_WAIT=15

# Ohne Poller zeigt das Dashboard nur einen alten Snapshot und graut aus.
# POLLER=off ./webserver.sh startet nur den Webserver.
POLLER_MODE="${POLLER:-$(cfg '.web.start_poller' 'true')}"

[[ "$PORT" =~ ^[0-9]+$ ]] || { echo "webserver: ungueltiger Port '$PORT'" >&2; exit 1; }
[[ -d "$DOCROOT" ]]       || { echo "webserver: Doc-Root '$DOCROOT' fehlt" >&2; exit 1; }

mkdir -p "$(dirname "$LOCK_FILE")" "$(dirname "$PID_FILE")" "$(dirname "$LOG_FILE")"

banner() {
    echo "webserver: PHP $(php -r 'echo PHP_VERSION;') auf ${HOST}:${PORT}, Doc-Root ${DOCROOT}"
    local ip
    for ip in "${LAUNCH_IPS[@]}"; do
        [[ -n "$ip" ]] && echo "           http://${ip}:${PORT}/"
    done
}

# exec ersetzt die Subshell durch PHP: gleiche PID wie in der PID-Datei, und die
# vom Aufrufer geerbte flock-Sperre auf FD 9 faellt erst mit dem PHP-Prozess.
serve() {
    echo "$BASHPID" > "$PID_FILE"
    exec php -S "${HOST}:${PORT}" -t "$DOCROOT" "${DOCROOT}index.php"
}

# poller.sh ist idempotent ("laeuft bereits" mit Exit 0). 9>&- gibt die
# Webserver-Sperre nicht an den Poller weiter - sonst hielte ein langlebiger
# Poller den Lock, und restart meldete "laeuft bereits".
poller_start() {
    case "$POLLER_MODE" in
        off|false|0|no) return 0 ;;
    esac
    [[ -x "${DIR}poller.sh" ]] || { echo "webserver: ${DIR}poller.sh fehlt - Poller nicht gestartet" >&2; return 1; }
    "${DIR}poller.sh" start 9>&- || { echo "webserver: Poller-Start fehlgeschlagen - Dashboard bleibt ohne Daten" >&2; return 1; }
}

# ---------------------------------------------------------------------------
# Kiosk
# ---------------------------------------------------------------------------

# Liest die Anzeige-Variablen aus /proc/<pid>/environ. Reines Bash ohne Fork,
# weil find_session ueber alle Prozesse laeuft.
read_session_env() {
    local pid="$1" kv
    S_WAYLAND="" S_DISPLAY="" S_RUNTIME="" S_XAUTH="" S_DBUS=""
    [[ -r "/proc/$pid/environ" ]] || return 1
    while IFS= read -r -d '' kv; do
        case "$kv" in
            WAYLAND_DISPLAY=*)          S_WAYLAND="${kv#*=}" ;;
            DISPLAY=*)                  S_DISPLAY="${kv#*=}" ;;
            XDG_RUNTIME_DIR=*)          S_RUNTIME="${kv#*=}" ;;
            XAUTHORITY=*)               S_XAUTH="${kv#*=}" ;;
            DBUS_SESSION_BUS_ADDRESS=*) S_DBUS="${kv#*=}" ;;
        esac
    done 2>/dev/null < "/proc/$pid/environ" || return 1
    [[ -n "$S_WAYLAND$S_DISPLAY" ]]
}

# Zaehlt nur, was wirklich da ist: eine geerbte DISPLAY=:0 ohne X-Server
# (Container, systemd) oder ein ssh -X mit "localhost:10" oeffnet sonst ein
# Fenster ins Leere bzw. auf dem Rechner des Admins.
session_valid() {
    local uid="$1" sock
    [[ -n "$S_RUNTIME" ]] || S_RUNTIME="/run/user/$uid"
    if [[ -n "$S_WAYLAND" ]]; then
        sock="$S_WAYLAND"
        [[ "$sock" == /* ]] || sock="${S_RUNTIME}/${sock}"
        [[ -S "$sock" ]] || S_WAYLAND=""
    fi
    if ! [[ "$S_DISPLAY" =~ ^:([0-9]+)(\.[0-9]+)?$ && -S "/tmp/.X11-unix/X${BASH_REMATCH[1]}" ]]; then
        S_DISPLAY=""
    fi
    [[ -n "$S_WAYLAND$S_DISPLAY" ]]
}

# Sucht eine grafische Sitzung am Geraet - egal ob Wayland (labwc, wayfire),
# LXDE/X11 oder per lightdm-Autologin. Die Umgebung kommt aus einem Prozess der
# Sitzung, deshalb klappt es auch per SSH, cron oder systemd.
# UIDs 1..999 fallen raus: das sind Greeter (lightdm, gdm) und Dienstkonten,
# keine angemeldete Oberflaeche. Echte Benutzer schlagen root.
find_session() {
    local path pid uid found=""
    for path in /proc/[0-9]*; do
        pid="${path#/proc/}"
        read_session_env "$pid" || continue
        uid="$(stat -c %u "$path" 2>/dev/null)" || continue
        (( uid == 0 || uid >= 1000 )) || continue
        session_valid "$uid" || continue
        if (( uid >= 1000 )); then
            found="$pid"
            break
        fi
        [[ -n "$found" ]] || found="$pid"
    done
    [[ -n "$found" ]] || return 1

    read_session_env "$found" || return 1
    S_UID="$(stat -c %u "/proc/$found" 2>/dev/null)" || return 1
    session_valid "$S_UID" || return 1
    S_USER="$(id -nu "$S_UID")"
    S_HOME="$(getent passwd "$S_USER" | cut -d: -f6)"
    if [[ -n "$S_DISPLAY" && -z "$S_XAUTH" && -f "${S_HOME}/.Xauthority" ]]; then
        S_XAUTH="${S_HOME}/.Xauthority"
    fi
}

# Befehlspraefix, der als Besitzer der Sitzung laeuft - nur der darf auf den
# Wayland-/X-Socket, und das Profil gehoert sonst root.
session_cmd() {
    local envs=("HOME=${S_HOME}" "USER=${S_USER}" "XDG_RUNTIME_DIR=${S_RUNTIME}")
    [[ -z "$S_WAYLAND" ]] || envs+=("WAYLAND_DISPLAY=${S_WAYLAND}")
    [[ -z "$S_DISPLAY" ]] || envs+=("DISPLAY=${S_DISPLAY}")
    [[ -z "$S_XAUTH" ]]   || envs+=("XAUTHORITY=${S_XAUTH}")
    [[ -z "$S_DBUS" ]]    || envs+=("DBUS_SESSION_BUS_ADDRESS=${S_DBUS}")

    if [[ "$(id -u)" == "$S_UID" ]]; then
        SESSION_CMD=(env "${envs[@]}")
    elif [[ "$(id -u)" == 0 ]]; then
        SESSION_CMD=(runuser -u "$S_USER" -- env "${envs[@]}")
    else
        return 1
    fi
}

find_browser() {
    local b
    for b in "${KIOSK_BROWSERS[@]}"; do
        [[ -n "$b" ]] && command -v "$b" 2>/dev/null && return 0
    done
    return 1
}

# Hauptprozesse der Kiosk-Instanz; die Renderer (--type=...) enden mit ihnen.
kiosk_pids() {
    local pid cmdline pattern
    pattern="$(printf '%s' "$KIOSK_MARKER" | sed 's/[][\.*^$+?(){}|]/\\&/g')"
    for pid in $(pgrep -f -- "$pattern" 2>/dev/null || true); do
        cmdline="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)"
        [[ -n "$cmdline" && "$cmdline" != *"--type="* ]] && echo "$pid"
    done
    return 0
}

wait_for_port() {
    local host="$HOST" i
    [[ "$host" == "0.0.0.0" || "$host" == "::" || -z "$host" ]] && host="127.0.0.1"
    for ((i = 0; i < KIOSK_WAIT * 10; i++)); do
        (exec 3<>"/dev/tcp/${host}/${PORT}") 2>/dev/null && return 0
        sleep 0.1
    done
    return 1
}

# Ein fehlender Bildschirm ist bei "auto" kein Fehler, nur "on" meldet ihn.
# Als Ausgabe statt Rueckgabewert, weil ein nacktes "false" unter set -e das
# ganze Skript beenden wuerde ("./webserver.sh kiosk").
kiosk_rc() {
    if [[ "$KIOSK_MODE" == "on" ]]; then echo 1; else echo 0; fi
}

kiosk_start() {
    local browser profile pids
    local -a flags

    case "$KIOSK_MODE" in
        off|false|no|0) return 0 ;;
    esac

    if ! find_session; then
        if pgrep -x 'lightdm|gdm3|gdm|sddm|Xorg|Xwayland' >/dev/null 2>&1; then
            echo "kiosk: Anzeigedienst laeuft, aber keine angemeldete Sitzung - Autologin einrichten; nur Listener"
        else
            echo "kiosk: keine grafische Sitzung am Geraet - nur Listener"
        fi
        return "$(kiosk_rc)"
    fi

    pids="$(kiosk_pids)"
    if [[ -n "$pids" ]]; then
        echo "kiosk: laeuft bereits (PID ${pids//$'\n'/ })"
        return 0
    fi

    if ! browser="$(find_browser)"; then
        echo "kiosk: Sitzung von ${S_USER} gefunden, aber kein Browser (${KIOSK_BROWSERS[*]}) - apt install chromium" >&2
        return "$(kiosk_rc)"
    fi

    if ! session_cmd; then
        echo "kiosk: Sitzung gehoert ${S_USER} - als $(id -un) nicht startbar, als root oder ${S_USER} aufrufen" >&2
        return "$(kiosk_rc)"
    fi

    # Eigenes Profil: die Kiosk-Schalter greifen nur in einer neuen Instanz, und
    # das normale Chromium des Benutzers bleibt unberuehrt.
    profile="${KIOSK_PROFILE_TPL//\{home\}/$S_HOME}"
    profile="${profile//\{dir\}/$DIR}"

    flags=(
        "$KIOSK_MARKER"
        --kiosk
        "--user-data-dir=${profile}"
        --no-first-run
        --no-default-browser-check
        --noerrdialogs
        --disable-infobars
        --disable-translate
        --disable-features=Translate,TranslateUI
        --disable-session-crashed-bubble
        --disable-pinch
        --overscroll-history-navigation=0
        --check-for-update-interval=31536000
        --password-store=basic
    )
    if [[ -n "$S_WAYLAND" ]]; then
        flags+=(--ozone-platform=wayland)
    else
        flags+=(--ozone-platform=x11)
    fi
    # Chromium verweigert root ohne --no-sandbox; --test-type blendet den
    # Warnbalken dazu aus. Betrifft nur Geraete mit root-Autologin.
    if [[ "$S_UID" == 0 ]]; then
        flags+=(--no-sandbox --test-type)
    fi
    local f
    for f in "${KIOSK_FLAGS[@]}"; do
        [[ -n "$f" ]] && flags+=("$f")
    done

    mkdir -p "$(dirname "$KIOSK_LOG")"
    echo "kiosk: ${browser##*/} fuer ${S_USER} auf ${S_WAYLAND:-$S_DISPLAY} -> ${KIOSK_URL}, Log ${KIOSK_LOG}"

    # Losgeloest starten: FD 9 (flock-Sperre) darf Chromium nicht erben, sonst
    # gilt der Webserver nach einem Stopp weiter als "laeuft bereits". setsid und
    # HUP-Ignorieren halten das Fenster offen, wenn das Terminal zugeht.
    (
        exec 9>&-
        trap '' HUP
        echo "--- $(date '+%F %T') ${browser} fuer ${S_USER}"
        if ! wait_for_port; then
            echo "kiosk: Webserver antwortet nicht auf Port ${PORT} - kein Start"
            exit 1
        fi
        "${SESSION_CMD[@]}" mkdir -p "$profile"
        # Nach hartem Ausschalten fragt Chromium sonst "Seiten wiederherstellen?".
        if [[ -f "${profile}/Default/Preferences" ]]; then
            "${SESSION_CMD[@]}" sed -i \
                -e 's/"exited_cleanly":false/"exited_cleanly":true/' \
                -e 's/"exit_type":"[^"]*"/"exit_type":"Normal"/' \
                "${profile}/Default/Preferences" || true
        fi
        exec setsid "${SESSION_CMD[@]}" "$browser" "${flags[@]}" "$KIOSK_URL"
    ) >>"$KIOSK_LOG" 2>&1 </dev/null &
    disown
    return 0
}

kiosk_stop() {
    local pids pid
    pids="$(kiosk_pids)"
    if [[ -z "$pids" ]]; then
        echo "kiosk: laeuft nicht"
        return 0
    fi
    # shellcheck disable=SC2086
    kill $pids 2>/dev/null || true
    for _ in {1..25}; do
        [[ -z "$(kiosk_pids)" ]] && break
        sleep 0.2
    done
    for pid in $(kiosk_pids); do
        kill -9 "$pid" 2>/dev/null || true
    done
    echo "kiosk: geschlossen (PID ${pids//$'\n'/ })"
}

# -n = nicht warten: laeuft schon eine Instanz, endet der Aufruf statt zu blockieren.
start() {
    local mode="${1:-background}"

    # Ein zweiter Aufruf oeffnet das Kiosk-Fenster wieder, falls es zugemacht wurde.
    flock -n 9 || {
        echo "webserver: laeuft bereits (PID $(cat "$PID_FILE" 2>/dev/null || echo '?')) - kein zweiter Start"
        poller_start || true
        kiosk_start || true
        exit 0
    }

    if [[ "$mode" == "foreground" ]]; then
        banner
        # Vor dem exec: danach gibt es diese Shell nicht mehr. Der Kiosk wartet
        # selbst, bis der Port antwortet.
        poller_start || true
        kiosk_start || true
        serve
    else
        serve >>"$LOG_FILE" 2>&1 &
        local pid=$!
        sleep 1
        if kill -0 "$pid" 2>/dev/null; then
            banner
            echo "webserver: gestartet (PID $pid), Log ${LOG_FILE}"
            poller_start || true
            kiosk_start || true
        else
            echo "webserver: Start fehlgeschlagen - siehe ${LOG_FILE}" >&2
            exit 1
        fi
    fi
}

# Schuetzt vor PID-Wiederverwendung: die Kommandozeile muss unser php -S sein,
# sonst wuerde stop() nach einem Reboot einen fremden Prozess abschiessen.
owns_pid() {
    local pid="$1" cmdline
    [[ -n "$pid" && "$pid" =~ ^[0-9]+$ ]] || return 1
    kill -0 "$pid" 2>/dev/null || return 1

    cmdline="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)"
    [[ -z "$cmdline" ]] && return 1

    [[ "$cmdline" == *"php"* && "$cmdline" == *"-S"* && "$cmdline" == *"$DOCROOT"* ]]
}

running_pid() {
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    owns_pid "$pid" && echo "$pid"
}

stop() {
    local pid
    pid="$(running_pid || true)"   # "|| true": leeres Ergebnis beendet sonst unter set -e das Skript.
    if [[ -n "$pid" ]]; then
        kill "$pid"
        for _ in {1..20}; do
            kill -0 "$pid" 2>/dev/null || break
            sleep 0.2
        done
        kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null || true
        echo "webserver: gestoppt (PID $pid)"
    else
        echo "webserver: laeuft nicht"
    fi
    rm -f "$PID_FILE"
}

status() {
    local pid
    pid="$(running_pid || true)"
    if [[ -n "$pid" ]]; then
        echo "webserver: laeuft (PID $pid) auf ${HOST}:${PORT}"
        local kpids
        kpids="$(kiosk_pids)"
        if [[ -n "$kpids" ]]; then
            echo "kiosk: offen (PID ${kpids//$'\n'/ })"
        else
            echo "kiosk: geschlossen"
        fi
    else
        echo "webserver: laeuft nicht"
        return 1
    fi
}

# Wartet, bis der alte Prozess die Sperre freigibt - sonst meldet restart
# "laeuft bereits" und am Ende laeuft gar nichts mehr.
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
    # stop schliesst den Kiosk mit; restart laesst ihn offen - die geladene Seite
    # pollt weiter und faengt sich, sobald der Server wieder da ist.
    stop)       kiosk_stop
                stop ;;
    restart)    stop
                wait_for_lock || echo "webserver: Sperre noch belegt - Start wird trotzdem versucht" >&2
                start background 9>>"$LOCK_FILE" ;;
    status)     status ;;
    kiosk)      running_pid >/dev/null || { echo "webserver: laeuft nicht - erst starten" >&2; exit 1; }
                KIOSK_MODE=on
                kiosk_start ;;
    kiosk-stop) kiosk_stop ;;
    *)          echo "Aufruf: [KIOSK=auto|on|off] [POLLER=on|off] $0 [start|stop|restart|status|foreground|kiosk|kiosk-stop]" >&2; exit 2 ;;
esac
