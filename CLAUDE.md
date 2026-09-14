# GaugeMetricLinux

Kiosk-Dashboard fuer Linux-Systemmetriken (CPU, RAM, Netzwerk, Datentraeger,
Laufwerke). Gebaut auf BaseFrameworkVC - die Regeln unten gelten weiter.

## Aufbau

- `sysinfo` - Bash-Skript, liefert die Messwerte (`sysinfo json [abschnitte]`).
  Normalerweise `/usr/bin/sysinfo`, hier im Projekt; Suchreihenfolge in
  `config.json` unter `gauge.sysinfo`. Erweiterungen (Abschnittswahl, Netzwerk,
  alle Dateisysteme, Bytes als Zahl, `SYSINFO_STATE_DIR`) sind abwaertskompatibel:
  bestehende JSON-Schluessel behalten ihr Format. Abschnitt `wan` (oeffentliche
  IP, Latenz per HTTP an `gauge.wan.url`) nur auf ausdruecklichen Wunsch; der
  Poller startet ihn als eigenen Prozess, damit ein haengender Server die
  lokalen Takte nicht aufhaelt. Ebenso `gpu` (nvidia-smi, eigener Takt): ohne
  Persistence Mode dauert ein Aufruf bis zu Sekunden. Die Grafikkarten-Karte
  erscheint nur, wenn eine Karte gemeldet wird.
- `app/cSysinfo.php` - ruft sysinfo auf und macht aus den Strings Zahlen.
- `app/cGaugePoller.php` - Backpoller: misst in Takten (`gauge.tiers`), schreibt
  `db/metrics.json` (tmp + rename). Start/Stopp ueber `poller.sh`.
- `views/api_metrics.php` - liest nur den Snapshot, misst nie selbst.
- `views/dashboard.php` + `public/gauge.js` / `gauge.css` - Kiosk-Oberflaeche.
- Webserver: `webserver.sh` (Port 8090 - 8080 belegt PackageLoggerPHP).
- Kiosk: ebenfalls `webserver.sh` (Abschnitt `kiosk` in `config.json`). Beim
  Start sucht es eine grafische Sitzung am Geraet (Wayland-/X-Socket aus der
  Umgebung eines Sitzungsprozesses) und oeffnet Chromium im `--kiosk`-Modus als
  deren Benutzer. Ohne Sitzung (SSH, Container, Server) bleibt es beim Listener.
  `KIOSK=off|on` uebersteuert `kiosk.mode`; `kiosk` / `kiosk-stop` einzeln.

`poller.sh` liegt als Gegenstueck zu `webserver.sh` im Projekt-Root.
`webserver.sh start` ruft `poller.sh start` mit auf (`web.start_poller`,
`POLLER=off` uebersteuert); `webserver.sh stop` laesst den Poller laufen.

# BaseFrameworkVC

PHP-Framework-Skelett ohne Composer, ohne Namespaces. Alle Klassen sind global,
werden von `autoload.php` gefunden und dort auch gleich instanziiert.

## Verzeichnisse

| Pfad          | Rolle                                                              |
|---------------|--------------------------------------------------------------------|
| `classes/`    | **Framework-Kern. Eingefroren.** Basisklassen `cFile`, `cRoute` ... |
| `app/`        | **Projektcode. Hierhin gehoert alles Neue.**                       |
| `views/`      | PHP-Templates, ausserhalb des Doc-Roots, nur per Router eingebunden |
| `public/`     | Doc-Root: `index.php` und statische Assets - sonst nichts          |
| `routing.php` | Routentabelle                                                      |
| `config.json` | Konfiguration, im Code als `CONFIG` bzw. ueber `cConfig`           |
| `examples/`   | Demo-Skripte, kein Produktivcode                                   |
| `db/`, `log/` | Laufzeitdaten, nicht im Repo                                       |

## Wohin neuer Code gehoert

**Neue Klassen immer nach `app/`.** Auch dann, wenn sie sich wie eine
Framework-Funktion anfuehlen. `classes/` waechst nicht mit dem Projekt mit -
das ist der ganze Zweck der Trennung.

**`classes/` nur nach Ruecksprache aendern.** Wenn eine Aenderung am Kern noetig
scheint, erst fragen und begruenden, warum es ein Framework-Thema und kein
Projekt-Thema ist. Das gilt fuer neue Dateien in `classes/` genauso wie fuer
Aenderungen an bestehenden. Kein stilles Nachbessern, kein "nur schnell eine
Methode ergaenzt".

**Keine losen Skripte im Projekt-Root und keine neuen Verzeichnisse.** Die
Struktur oben ist vollstaendig. Was nach `app/`, `views/` oder `examples/`
passt, kommt dorthin; alles andere vorher klaeren. Einmal-Skripte und
Zwischenstaende gehoeren ins Scratchpad-Verzeichnis, nicht ins Projekt.

Faustregel: Klasse -> `app/`. Seite -> `views/` plus Eintrag in `routing.php`.
Einstellung -> `config.json`. Vorfuehrung -> `examples/`.

## Autoload

`autoload.php` durchsucht `classes/` und `app/` in dieser Reihenfolge; bei
gleichem Klassennamen gewinnt der Kern. Jede Klasse, die instanziierbar ist und
keine Pflichtparameter im Konstruktor hat, wird automatisch angelegt und steht
als `$cName` global sowie in `$cClasses` bereit - eine neue `app/cShop.php` ist
also ohne weiteres Zutun als `$cShop` da, auch in Views.

Soll eine Klasse *nicht* automatisch instanziiert werden, gehoert sie in
`$autoloadSkip` (wie `cDatabase`, das sonst sofort eine PDO-Verbindung aufbaut).

## Konventionen

- Klassenname mit Praefix `c`, eine Klasse pro Datei, Dateiname = Klassenname
- Keine Namespaces, kein `declare(strict_types)`, keine externen Pakete
- 4 Leerzeichen Einrueckung, Dateien in `classes/` und `app/` mit CRLF
- Kommentare deutsch und in ASCII: `ue`, `oe`, `ae`, `ss` statt Umlauten
- Kommentare erklaeren das *Warum*, nicht das Was - siehe bestehende Klassen
- Typisierte Eigenschaften und Rueckgabetypen, `?string` statt ungetypt

## Git

- Nie direkt auf `master` committen
- Vor der Arbeit Tag auf `master`: `stable/JJJJ-MM-TT-vor-<thema>`
- Branch `feature/<thema>` bzw. `fix/`, `refactor/`, `docs/`
- Zurueck per `git merge --no-ff` mit Merge-Kommentar
- Branches werden nie geloescht
- Commit-Nachrichten deutsch, ASCII, Form: `cImg: convert() wandelt Bildformate um`
