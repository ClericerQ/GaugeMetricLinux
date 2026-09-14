<?php
class cRoute {
    public ?string $lastError = null;

    // Platzhalter der zuletzt getroffenen Route: ['id' => '42'].
    public array $params = [];

    private array $routes      = [];
    private array $configRoutes = [];
    private       $notFoundHandler = null;

    private string  $baseDir;
    private string  $basePath    = '';
    private bool    $stripEnding = true;
    private array   $indexFiles  = ['index.php', 'index.html', 'index.htm'];
    private ?string $logFile     = 'web.log';
    private ?string $routeFile   = null;

    private bool $loaded  = false;
    private int  $status  = 200;
    private ?array $matched = null;

    // Ohne cFile-Umweg, weil mime_content_type() bei css/js/svg text/plain liefert.
    private const MIME = [
        'html' => 'text/html; charset=utf-8',   'htm'  => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',    'js'   => 'text/javascript; charset=utf-8',
        'mjs'  => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'xml'  => 'application/xml; charset=utf-8',
        'txt'  => 'text/plain; charset=utf-8',  'md'   => 'text/markdown; charset=utf-8',
        'csv'  => 'text/csv; charset=utf-8',
        'svg'  => 'image/svg+xml',              'ico'  => 'image/x-icon',
        'png'  => 'image/png',                  'gif'  => 'image/gif',
        'jpg'  => 'image/jpeg',                 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',                 'avif' => 'image/avif',
        'woff' => 'font/woff',                  'woff2'=> 'font/woff2',
        'pdf'  => 'application/pdf',            'zip'  => 'application/zip',
        'mp4'  => 'video/mp4',                  'mp3'  => 'audio/mpeg',
    ];

    /**
     * $options ueberschreibt den Abschnitt "route" aus config.json:
     * base_path, strip_ending, index_files, log_file, file, routes.
     */
    public function __construct(?array $options = null) {
        $cfg = $options ?? (defined('CONFIG') ? (CONFIG['route'] ?? []) : []);

        $this->baseDir = dirname(__DIR__) . '/';

        $base = '/' . trim((string) ($cfg['base_path'] ?? ''), '/');
        $this->basePath = $base === '/' ? '' : $base;

        $this->stripEnding = (bool) ($cfg['strip_ending'] ?? true);

        if (!empty($cfg['index_files'])) {
            $this->indexFiles = array_values((array) $cfg['index_files']);
        }

        $log = $cfg['log_file'] ?? 'web.log';
        $this->logFile = ($log === false || $log === '' || $log === null) ? null : (string) $log;

        $file = $cfg['file'] ?? 'routing.php';
        $this->routeFile = $file === false ? null : $this->path2abs((string) $file);

        $this->configRoutes = (array) ($cfg['routes'] ?? []);
    }

    // -----------------------------------------------------------------------
    // Anfrage lesen
    // -----------------------------------------------------------------------

    public function method(): string {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function uri(): string {
        return (string) ($_SERVER['REQUEST_URI'] ?? '/');
    }

    /** Pfad der Anfrage ohne Query, ohne base_path, ohne Schluss-Slash. */
    public function path(): string {
        $path = parse_url($this->uri(), PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        $path = preg_replace('#/+#', '/', $path) ?? '/';

        if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) {
            $path = substr($path, strlen($this->basePath));
        }

        $path = '/' . trim($path, '/');
        return $path;
    }

    /** Dateiendung der URL: "/report.pdf" -> "pdf", "/status" -> "". */
    public function ending(): string {
        return strtolower(pathinfo($this->path(), PATHINFO_EXTENSION));
    }

    public function extension(): string {
        return $this->ending();
    }

    /** Pfad ohne Endung - danach wird gesucht, wenn strip_ending an ist. */
    public function route(): string {
        $ending = $this->ending();
        if ($ending === '') {
            return $this->path();
        }
        $path = substr($this->path(), 0, -(strlen($ending) + 1));
        return $path === '' ? '/' : $path;
    }

    public function segments(): array {
        $path = trim($this->path(), '/');
        return $path === '' ? [] : explode('/', $path);
    }

    public function segment(int $index, ?string $default = null): ?string {
        return $this->segments()[$index] ?? $default;
    }

    public function query(?string $key = null, mixed $default = null): mixed {
        if ($key === null) return $_GET;
        return $_GET[$key] ?? $default;
    }

    /** GET, POST und JSON-Body zusammen - POST schlaegt GET, JSON schlaegt POST. */
    public function input(?string $key = null, mixed $default = null): mixed {
        $data = array_merge($_GET, $_POST, $this->bodyJson() ?? []);
        if ($key === null) return $data;
        return $data[$key] ?? $default;
    }

    public function body(): string {
        return (string) file_get_contents('php://input');
    }

    public function bodyJson(): ?array {
        $raw = $this->body();
        if (trim($raw) === '') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function header(string $name): ?string {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? $_SERVER[strtoupper(str_replace('-', '_', $name))] ?? null;
    }

    public function ip(): string {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
    }

    public function secure(): bool {
        return ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off'
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower($this->header('X-Forwarded-Proto') ?? '') === 'https';
    }

    public function host(): string {
        return (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    }

    /** Basisadresse inklusive base_path, ohne Schluss-Slash. */
    public function base(): string {
        return ($this->secure() ? 'https://' : 'http://') . $this->host() . $this->basePath;
    }

    public function url(string $path = '/'): string {
        return $this->base() . '/' . ltrim($path, '/');
    }

    /** Will der Aufrufer JSON? Endung .json, XHR-Header oder Accept-Header. */
    public function wantsJson(): bool {
        return $this->ending() === 'json'
            || strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest'
            || str_contains(strtolower($this->header('Accept') ?? ''), 'application/json');
    }

    // -----------------------------------------------------------------------
    // Routen anmelden
    // -----------------------------------------------------------------------

    /**
     * $target: Closure, "Klasse@methode", PHP-Datei, Verzeichnis (endet auf /)
     * oder "redirect:/ziel". Relative Pfade gelten ab Projektverzeichnis.
     */
    public function add(string|array $methods, string $pattern, mixed $target, array $options = []): static {
        $methods = array_map('strtoupper', (array) $methods);
        if (in_array('ANY', $methods, true) || $methods === []) {
            $methods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        }
        if (in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) {
            $methods[] = 'HEAD';
        }

        $pattern = '/' . trim($pattern, '/');
        [$regex, $names] = $this->compile($pattern);

        $this->routes[] = [
            'methods' => $methods,
            'pattern' => $pattern,
            'regex'   => $regex,
            'names'   => $names,
            'target'  => $target,
            'options' => $options,
        ];

        return $this;
    }

    public function get(string $pattern, mixed $target, array $options = []): static    { return $this->add('GET', $pattern, $target, $options); }
    public function post(string $pattern, mixed $target, array $options = []): static   { return $this->add('POST', $pattern, $target, $options); }
    public function put(string $pattern, mixed $target, array $options = []): static    { return $this->add('PUT', $pattern, $target, $options); }
    public function patch(string $pattern, mixed $target, array $options = []): static  { return $this->add('PATCH', $pattern, $target, $options); }
    public function delete(string $pattern, mixed $target, array $options = []): static { return $this->add('DELETE', $pattern, $target, $options); }
    public function any(string $pattern, mixed $target, array $options = []): static    { return $this->add('ANY', $pattern, $target, $options); }

    /**
     * Verzeichnis unter einem Praefix ausliefern: mount('/docs', 'docs/').
     * Optionen: php (PHP-Dateien ausfuehren, Standard false), listing (Inhalt
     * auflisten, Standard false), index (eigene Indexdateien).
     */
    public function mount(string $prefix, string $directory, array $options = []): static {
        $prefix = '/' . trim($prefix, '/');
        $target = ['dir' => $this->path2abs($directory)] + $options;

        if ($prefix === '/') {
            return $this->add(['GET', 'HEAD'], '/*', $target);
        }
        return $this->add(['GET', 'HEAD'], $prefix, $target)
                    ->add(['GET', 'HEAD'], $prefix . '/*', $target);
    }

    /** Feste Weiterleitung als Route: alias('/alt', '/neu'). */
    public function alias(string $pattern, string $to, int $status = 302): static {
        return $this->any($pattern, 'redirect:' . $to, ['status' => $status]);
    }

    /** Eigener 404-Handler: Closure, Datei oder "redirect:/". */
    public function notFound(mixed $target): static {
        $this->notFoundHandler = $target;
        return $this;
    }

    /**
     * Routen aus einem Array uebernehmen. Schluessel "GET /pfad" oder "/pfad"
     * (dann alle Methoden), Wert wie bei add().
     */
    public function addRoutes(array $routes): static {
        foreach ($routes as $key => $target) {
            $key   = trim((string) $key);
            $parts = preg_split('/\s+/', $key, 2) ?: [$key];

            if (count($parts) === 2 && !str_starts_with($parts[0], '/')) {
                $methods = array_map('trim', explode('|', $parts[0]));
                $pattern = $parts[1];
            } else {
                $methods = ['ANY'];
                $pattern = $parts[0];
            }

            $options = [];
            if (is_array($target) && !is_callable($target) && !isset($target['dir'])) {
                $options = $target;
                $target  = $options['target'] ?? $options['to'] ?? null;
                unset($options['target'], $options['to']);
            }

            if ($target === null) continue;

            $this->add($methods, $pattern, $target, $options);
        }
        return $this;
    }

    /** routing.php (oder eine andere Datei) einlesen, die ein Array zurueckgibt. */
    public function load(string $file): bool {
        $file = $this->path2abs($file);
        if (!is_file($file)) {
            $this->lastError = "cRoute: Routendatei fehlt: $file";
            return false;
        }

        $cRoute = $this;
        $routes = require $file;

        if (!is_array($routes)) {
            $this->lastError = "cRoute: $file gibt kein Array zurueck";
            return false;
        }

        $this->addRoutes($routes);
        return true;
    }

    /** Alle angemeldeten Routen - zum Nachsehen und Debuggen. */
    public function routes(): array {
        $this->ensureLoaded();
        return array_map(static fn(array $r): array => [
            'methods' => implode('|', $r['methods']),
            'pattern' => $r['pattern'],
            'target'  => is_string($r['target'])
                ? $r['target']
                : (is_array($r['target']) ? ($r['target']['dir'] ?? 'array') : 'callable'),
        ], $this->routes);
    }

    // -----------------------------------------------------------------------
    // Zuordnen und ausfuehren
    // -----------------------------------------------------------------------

    /**
     * Passende Route suchen, ohne sie auszufuehren. Liefert den Routeneintrag
     * mit 'params', bei Methodenkonflikt ['allow' => [...]], sonst null.
     */
    public function match(?string $method = null, ?string $path = null): ?array {
        $this->ensureLoaded();

        $method = strtoupper($method ?? $this->method());
        $paths  = [$path ?? $this->path()];

        if ($path === null && $this->stripEnding && $this->ending() !== '' && $this->route() !== $paths[0]) {
            $paths[] = $this->route();
        }

        $allow = [];

        foreach ($paths as $candidate) {
            $best = null;
            $bestScore = PHP_INT_MAX;

            foreach ($this->routes as $route) {
                if (!preg_match($route['regex'], $candidate, $hit)) continue;

                if (!in_array($method, $route['methods'], true)) {
                    $allow = array_unique(array_merge($allow, $route['methods']));
                    continue;
                }

                // Weniger Platzhalter = genauere Route; bei Gleichstand zaehlt die Reihenfolge.
                $score = count($route['names']) * 10 + (in_array('*', $route['names'], true) ? 5 : 0);
                if ($score < $bestScore) {
                    array_shift($hit);
                    // Ein optionaler Rest ("/docs" bei "/docs/*") fehlt in $hit.
                    $hit = array_pad($hit, count($route['names']), '');
                    $route['params'] = $route['names'] === []
                        ? []
                        : array_combine($route['names'], $hit);
                    $best      = $route;
                    $bestScore = $score;
                }
            }

            if ($best !== null) return $best;
        }

        return $allow === [] ? null : ['allow' => array_values($allow)];
    }

    /**
     * Anfrage abarbeiten. Liefert true, wenn eine Route gegriffen hat.
     * Nichts gefunden -> 404 (oder notFound-Handler), falsche Methode -> 405.
     */
    public function run(?string $method = null, ?string $path = null): bool {
        $method = strtoupper($method ?? $this->method());
        $route  = $this->match($method, $path);

        $head = $method === 'HEAD';
        if ($head) ob_start();

        try {
            if ($route !== null && isset($route['target'])) {
                $this->matched = $route;
                $this->params  = $route['params'] ?? [];
                $ok = (bool) $this->execute($route['target'], $route['options'] ?? []);
            } elseif ($route !== null) {
                $allow = implode(', ', $route['allow']);
                if ($method === 'OPTIONS') {
                    $this->status(204)->send('Allow', $allow);
                    $ok = true;
                } else {
                    $this->send('Allow', $allow);
                    $this->abort(405, "405 - Methode $method nicht erlaubt (erlaubt: $allow)");
                    $ok = false;
                }
            } else {
                $ok = $this->handleNotFound();
            }
        } finally {
            if ($head) {
                $body = (string) ob_get_clean();
                $this->send('Content-Length', (string) strlen($body));
            }
            $this->log();
        }

        return $ok;
    }

    /**
     * Ziel ausfuehren: Closure, "Klasse@methode", ['dir' => ...],
     * "redirect:/ziel", Verzeichnis oder PHP-Datei.
     */
    public function execute(mixed $target, array $options = []): mixed {
        if (is_array($target) && isset($target['dir'])) {
            return $this->serveDir($target['dir'], (string) ($this->params['*'] ?? ''), $target);
        }

        if (!is_string($target) && is_callable($target)) {
            return $target(...array_values($this->params));
        }

        if (is_string($target)) {
            if (str_starts_with($target, 'redirect:')) {
                return $this->redirect(substr($target, 9), (int) ($options['status'] ?? 302));
            }

            if (str_contains($target, '@')) {
                [$class, $action] = explode('@', $target, 2);
                if (class_exists($class)) {
                    $object = $GLOBALS[$class] ?? new $class();
                    return $object->$action(...array_values($this->params));
                }
            }

            $file = $this->path2abs($target);

            if (is_dir($file) || str_ends_with($target, '/')) {
                return $this->serveDir($file, (string) ($this->params['*'] ?? ''), $options);
            }

            if (is_file($file)) {
                return str_ends_with(strtolower($file), '.php')
                    ? $this->view($file, $this->params)
                    : $this->sendFile($file);
            }

            $this->lastError = "cRoute: Ziel nicht gefunden: $target";
            return $this->abort(500, '500 - Route zeigt auf ein fehlendes Ziel');
        }

        if (is_callable($target)) {
            return $target(...array_values($this->params));
        }

        $this->lastError = 'cRoute: unbrauchbares Routenziel';
        return $this->abort(500, '500 - unbrauchbares Routenziel');
    }

    /** Datei aus einem Verzeichnis ausliefern - ohne Ausbruch nach oben. */
    public function serveDir(string $directory, string $relative = '', array $options = []): bool {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            $this->lastError = "cRoute: Verzeichnis fehlt: $directory";
            return $this->abort(500, '500 - Verzeichnis der Route fehlt');
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');

        // Punktdateien und .. bleiben aussen vor, bevor ueberhaupt geprueft wird.
        foreach (explode('/', $relative) as $part) {
            if ($part !== '' && str_starts_with($part, '.')) {
                return $this->abort(404, '404 - nicht gefunden');
            }
        }

        $file = realpath($root . '/' . $relative);
        if ($file === false || !str_starts_with($file . '/', rtrim($root, '/') . '/')) {
            return $this->abort(404, '404 - nicht gefunden');
        }

        $php = (bool) ($options['php'] ?? false);

        if (is_dir($file)) {
            foreach ((array) ($options['index'] ?? $this->indexFiles) as $index) {
                $candidate = $file . '/' . $index;
                if (!is_file($candidate)) continue;
                if (str_ends_with(strtolower($index), '.php')) {
                    if (!$php) continue;
                    return (bool) $this->view($candidate);
                }
                return $this->sendFile($candidate);
            }

            return ($options['listing'] ?? false)
                ? $this->listing($file)
                : $this->abort(403, '403 - kein Verzeichnisinhalt');
        }

        if (str_ends_with(strtolower($file), '.php')) {
            return $php ? (bool) $this->view($file) : $this->abort(403, '403 - Zugriff verweigert');
        }

        return $this->sendFile($file);
    }

    // -----------------------------------------------------------------------
    // Antworten
    // -----------------------------------------------------------------------

    public function status(int $code): static {
        $this->status = $code;
        if (!headers_sent()) http_response_code($code);
        return $this;
    }

    /** Antwort-Header setzen (header() liest, send() schreibt). */
    public function send(string $name, string $value, bool $replace = true): static {
        if (!headers_sent()) header("$name: $value", $replace);
        return $this;
    }

    public function json(mixed $data, int $status = 200): bool {
        $this->status($status)->send('Content-Type', 'application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return true;
    }

    public function text(string $body, int $status = 200): bool {
        $this->status($status)->send('Content-Type', 'text/plain; charset=utf-8');
        echo $body;
        return true;
    }

    public function html(string $body, int $status = 200): bool {
        $this->status($status)->send('Content-Type', 'text/html; charset=utf-8');
        echo $body;
        return true;
    }

    /**
     * PHP-Datei einbinden. $vars stehen darin als Variablen bereit, dazu
     * $cRoute und die Instanzen aus autoload.php ($cFile, $cNetwork ...).
     */
    public function view(string $file, array $vars = [], int $status = 200): bool {
        $file = $this->path2abs($file);
        if (!is_file($file)) {
            $this->lastError = "cRoute: View fehlt: $file";
            return $this->abort(500, '500 - View fehlt');
        }

        $this->status($status);
        if (!headers_sent() && !$this->headerSet('Content-Type')) {
            $this->send('Content-Type', 'text/html; charset=utf-8');
        }

        $this->scope($file, $vars);
        return true;
    }

    public function redirect(string $to, int $status = 302): bool {
        $target = preg_match('#^https?://#i', $to) ? $to : $this->url($to);
        $this->status($status)->send('Location', $target);
        return true;
    }

    public function abort(int $status = 404, ?string $message = null): bool {
        $message ??= "$status";
        $this->status($status);

        if ($this->wantsJson()) {
            $this->send('Content-Type', 'application/json; charset=utf-8');
            echo json_encode(['error' => $message, 'status' => $status, 'path' => $this->path()],
                             JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            $this->send('Content-Type', 'text/plain; charset=utf-8');
            echo $message, "\n";
        }
        return false;
    }

    /** Datei ausliefern; $download = true erzwingt den Speichern-Dialog. */
    public function sendFile(string $file, bool $download = false, ?string $name = null): bool {
        $file = $this->path2abs($file);
        if (!is_file($file) || !is_readable($file)) {
            $this->lastError = "cRoute: Datei nicht lesbar: $file";
            return $this->abort(404, '404 - nicht gefunden');
        }

        $this->send('Content-Type', $this->mime($file))
             ->send('Content-Length', (string) filesize($file));

        if ($download) {
            $name ??= basename($file);
            $this->send('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $name) . '"');
        }

        readfile($file);
        return true;
    }

    public function mime(string $file): string {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (isset(self::MIME[$ext])) return self::MIME[$ext];

        $cFile = $GLOBALS['cFile'] ?? null;
        $mime  = $cFile instanceof cFile ? $cFile->mime($file) : @mime_content_type($file);

        return $mime ?: 'application/octet-stream';
    }

    /**
     * Statische Datei beim eingebauten PHP-Server durchreichen. Gehoert an den
     * Anfang von index.php:  if ($cRoute->isStatic()) return false;
     */
    public function isStatic(?string $docRoot = null): bool {
        if (PHP_SAPI !== 'cli-server') return false;

        $root = $docRoot
            ?? (defined('CONFIG') ? (CONFIG['web']['docroot'] ?? null) : null)
            ?? ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $root = realpath(rtrim($root, '/'));
        if ($root === false) return false;

        $path = $this->path();
        if ($path === '/' || str_contains($path, '..')) return false;

        $file = realpath($root . $path);

        return $file !== false
            && is_file($file)
            && str_starts_with($file, $root . '/')
            && !str_ends_with(strtolower($file), '.php');
    }

    public function lastStatus(): int {
        return $this->status;
    }

    public function matched(): ?array {
        return $this->matched;
    }

    // -----------------------------------------------------------------------
    // Innenleben
    // -----------------------------------------------------------------------

    private function ensureLoaded(): void {
        if ($this->loaded) return;
        $this->loaded = true;

        if ($this->configRoutes !== []) {
            $this->addRoutes($this->configRoutes);
        }

        if ($this->routeFile !== null && is_file($this->routeFile)) {
            $this->load($this->routeFile);
        }
    }

    private function handleNotFound(): bool {
        if ($this->notFoundHandler !== null) {
            $this->params = [];
            $this->status(404);
            $this->execute($this->notFoundHandler);
            return false;
        }
        return $this->abort(404, '404 - ' . $this->path() . ' nicht gefunden');
    }

    /** Pattern in einen regulaeren Ausdruck uebersetzen; liefert [regex, namen]. */
    private function compile(string $pattern): array {
        $names = [];
        $tail  = '';

        // "/docs/*" soll auch "/docs" selbst treffen.
        if (str_ends_with($pattern, '/*')) {
            $pattern = substr($pattern, 0, -2);
            $names[] = '*';
            $tail    = '(?:/(.*))?';
        }

        $parts = preg_split(
            '#(\{[a-zA-Z_][a-zA-Z0-9_]*(?::[^}]+)?\}|\*)#',
            $pattern,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        ) ?: [];

        $regex = '';
        foreach ($parts as $part) {
            if ($part === '*') {
                $names[] = '*';
                $regex  .= '(.*)';
            } elseif (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}$#', $part, $m)) {
                $names[] = $m[1];
                $regex  .= '(' . ($m[2] ?? '[^/]+') . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        // Der Stern des Praefix-Falls steht am Ende, seine Gruppe aber auch.
        if ($tail !== '') {
            $names = array_values(array_diff($names, ['*']));
            $names[] = '*';
        }

        return ['#^' . $regex . $tail . '$#u', $names];
    }

    private function listing(string $dir): bool {
        $cFile   = $GLOBALS['cFile'] ?? null;
        $entries = $cFile instanceof cFile ? $cFile->getDirContents($dir) : (glob($dir . '/*') ?: []);

        $prefix = '/' . trim(rtrim($this->path(), '/'), '/');
        $rows   = '';

        foreach ($entries as $entry) {
            $name = basename($entry);
            if (str_starts_with($name, '.')) continue;
            $link = htmlspecialchars($prefix . '/' . rawurlencode($name), ENT_QUOTES);
            $show = htmlspecialchars($name . (is_dir($entry) ? '/' : ''), ENT_QUOTES);
            $rows .= "<li><a href=\"$link\">$show</a></li>\n";
        }

        $title = htmlspecialchars($prefix === '' ? '/' : $prefix, ENT_QUOTES);

        return $this->html(
            "<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\">"
            . "<title>Inhalt von $title</title></head><body>"
            . "<h1>Inhalt von $title</h1><ul>\n$rows</ul></body></html>"
        );
    }

    /** Eigener Gueltigkeitsbereich fuer Views: nichts aus run() leckt hinein. */
    private function scope(string $__file, array $__vars): void {
        $cRoute = $this;
        $params = $this->params;
        $dir    = $this->baseDir;

        extract($GLOBALS['cClasses'] ?? [], EXTR_SKIP);
        extract($__vars, EXTR_SKIP);

        include $__file;
    }

    private function headerSet(string $name): bool {
        foreach (headers_list() as $line) {
            if (stripos($line, $name . ':') === 0) return true;
        }
        return false;
    }

    private function path2abs(string $path): string {
        if ($path === '') return $this->baseDir;
        if ($path[0] === '/' || preg_match('#^[a-zA-Z]:[\\\\/]#', $path)) return $path;
        return $this->baseDir . ltrim($path, './');
    }

    private function log(): void {
        if ($this->logFile === null) return;

        $cLog = $GLOBALS['cLog'] ?? null;
        if (!$cLog instanceof cLog) return;

        $cLog->log($this->logFile, sprintf(
            '%s %s -> %d von %s',
            $this->method(),
            $this->path(),
            $this->status,
            $this->ip()
        ));
    }
}
