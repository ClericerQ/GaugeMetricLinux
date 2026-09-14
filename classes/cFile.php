<?php
class cFile {
    private array $aImg       = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'avif'];
    private array $aDocuments = ['doc', 'docx', 'pdf', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'rtf', 'csv'];
    private array $aArchives  = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'zst'];
    private array $aVideos    = ['mp4', 'avi', 'mov', 'wmv', 'mkv', 'webm', 'flv', 'm4v'];
    private array $aAudio     = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus'];
    private array $aCode      = ['php', 'js', 'ts', 'py', 'sh', 'bash', 'html', 'css', 'json', 'xml', 'yml', 'yaml', 'sql', 'c', 'cpp', 'h', 'java', 'go', 'rs'];

    public ?string $lastError = null;

    public function getFileType(string|array $file): string|false {
        $filename = is_array($file) ? ($file['name'] ?? null) : $file;
        if (!$filename) return false;

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match(true) {
            in_array($ext, $this->aImg)       => 'image',
            in_array($ext, $this->aDocuments) => 'document',
            in_array($ext, $this->aArchives)  => 'archive',
            in_array($ext, $this->aVideos)    => 'video',
            in_array($ext, $this->aAudio)     => 'audio',
            in_array($ext, $this->aCode)      => 'code',
            default                           => 'other',
        };
    }

    public function checksum(string $file, string $algo = 'sha256'): string|false {
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        if (!in_array($algo, hash_algos(), true)) {
            return false;
        }
        return hash_file($algo, $file);
    }


    private const CHECKSUM_AUTO_LIMIT = 64 * 1024 * 1024;


    public function getFileInfo(string $file, ?bool $checksums = null): array|false {
        if (!file_exists($file)) {
            return false;
        }

        $isFile = is_file($file);
        $stat   = stat($file);
        $size   = $isFile ? filesize($file) : null;

        $withChecksums = $isFile && ($checksums ?? ($size !== false && $size <= self::CHECKSUM_AUTO_LIMIT));

        $info = [
            'path'       => realpath($file),
            'name'       => basename($file),
            'extension'  => strtolower(pathinfo($file, PATHINFO_EXTENSION)),
            'type'       => $isFile ? $this->getFileType($file) : 'directory',

            'size_bytes' => $size === false ? null : $size,
            'size_human' => is_int($size) ? $this->formatBytes($size) : null,

            'created'    => $stat['ctime'],
            'created_h'  => date('Y-m-d H:i:s', $stat['ctime']),
            'modified'   => $stat['mtime'],
            'modified_h' => date('Y-m-d H:i:s', $stat['mtime']),
            'accessed'   => $stat['atime'],
            'accessed_h' => date('Y-m-d H:i:s', $stat['atime']),

            'permissions'     => substr(sprintf('%o', fileperms($file)), -4),
            'permissions_str' => $this->permissionsString($file),
            'owner_uid'       => $stat['uid'],
            'owner_gid'       => $stat['gid'],
            'owner_name'      => function_exists('posix_getpwuid')
                                    ? (posix_getpwuid($stat['uid'])['name'] ?? null)
                                    : null,

            'mime_type'  => $isFile ? (mime_content_type($file) ?: null) : null,

            'checksums'  => $withChecksums ? [
                'md5'    => $this->checksum($file, 'md5'),
                'sha1'   => $this->checksum($file, 'sha1'),
                'sha256' => $this->checksum($file, 'sha256'),
            ] : null,

            'is_symlink'   => is_link($file),
            'symlink_target' => is_link($file) ? readlink($file) : null,
        ];

        return $info;
    }


    public function getDirContents(string $directory, bool $recursive = false): array {
        if (!is_dir($directory) || !is_readable($directory)) {
            return [];
        }

        $root = realpath($directory);
        if ($root === false) {
            return [];
        }

        $flags = FilesystemIterator::SKIP_DOTS
               | FilesystemIterator::CURRENT_AS_PATHNAME
               | FilesystemIterator::UNIX_PATHS;

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, $flags),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } else {
            $iterator = new FilesystemIterator($root, $flags);
        }

        $aEntries = [];
        foreach ($iterator as $path) {
            $aEntries[] = $path;
        }

        sort($aEntries, SORT_NATURAL);

        return $aEntries;
    }

    public function scandir(string $directory, bool $recursive = false): array {
	return $this->getDirContents($directory, $recursive);
    }

    // -----------------------------------------------------------------------
    // Pfadteile - reine Stringarbeit, die Datei muss nicht existieren
    // -----------------------------------------------------------------------

    public function filename(string $path): string {
        return pathinfo($this->clean($path), PATHINFO_FILENAME);
    }

    public function basename(string $path): string {
        return basename($this->clean($path));
    }

    public function ending(string $path): string {
        return strtolower(pathinfo($this->clean($path), PATHINFO_EXTENSION));
    }

    public function extension(string $path): string {
        return $this->ending($path);
    }

    public function changeEnding(string $path, string $ending): string {
        $path   = $this->clean($path);
        $dir    = $this->dir($path);
        $name   = pathinfo($path, PATHINFO_FILENAME);
        $ending = ltrim($ending, '.');
        $new    = $ending === '' ? $name : $name . '.' . $ending;

        return $dir === '' || $dir === '.' ? $new : rtrim($dir, '/') . '/' . $new;
    }

    public function dir(string $path): string {
        $dir = dirname($this->clean($path));
        return $dir === '.' ? '' : $dir;
    }

    public function up(string $path, int $levels = 1): string {
        if ($levels < 1) return $this->clean($path);
        return dirname($this->clean($path), $levels);
    }

    public function segments(string $path): array {
        $path = trim($this->clean($path), '/');
        return $path === '' ? [] : explode('/', $path);
    }

    public function depth(string $path): int {
        $dir = $this->dir($path);
        return $dir === '' ? 0 : count($this->segments($dir));
    }

    public function isAbsolute(string $path): bool {
        $path = $this->clean($path);
        return str_starts_with($path, '/') || (bool) preg_match('#^[a-zA-Z]:/#', $path);
    }

    public function root(string $path): string {
        $path = $this->clean($path);
        if (preg_match('#^([a-zA-Z]:)/#', $path, $m)) return $m[1] . '/';
        return str_starts_with($path, '/') ? '/' : '';
    }

    public function baseDir(): string {
        return dirname(__DIR__);
    }

    public function docRoot(): string {
        return rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    }

    public function cwd(): string {
        return getcwd() ?: '';
    }

    public function join(string ...$parts): string {
        $root  = $this->root($parts[0] ?? '');
        $first = true;
        $clean = [];

        foreach ($parts as $part) {
            $part = $this->clean($part);
            if ($first) {
                $part  = substr($part, strlen($root));
                $first = false;
            }
            $part = trim($part, '/');
            if ($part !== '') $clean[] = $part;
        }

        return $root . implode('/', $clean);
    }

    public function normalize(string $path): string {
        $path     = $this->clean($path);
        $root     = $this->root($path);
        $absolute = $root !== '';

        $out = [];
        foreach (explode('/', substr($path, strlen($root))) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') {
                if ($out !== [] && end($out) !== '..') { array_pop($out); continue; }
                if ($absolute) continue;
                $out[] = '..';
                continue;
            }
            $out[] = $segment;
        }

        if ($absolute) return $root . implode('/', $out);

        return $out === [] ? '.' : implode('/', $out);
    }

    public function absolute(string $path, ?string $base = null): string {
        if ($this->isAbsolute($path)) return $this->normalize($path);
        return $this->normalize(($base ?? $this->cwd()) . '/' . $path);
    }

    public function relative(string $path, string $base): string {
        $aPath = $this->segments($this->normalize($path));
        $aBase = $this->segments($this->normalize($base));

        while ($aPath !== [] && $aBase !== [] && $aPath[0] === $aBase[0]) {
            array_shift($aPath);
            array_shift($aBase);
        }

        $up = array_fill(0, count($aBase), '..');
        $rel = implode('/', array_merge($up, $aPath));

        return $rel === '' ? '.' : $rel;
    }

    public function safeName(string $name): string {
        $name = basename($this->clean($name));
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? '';
        $name = preg_replace('/_{2,}/', '_', $name) ?? '';
        $name = trim($name, ' .');

        return $name === '' ? 'datei' : $name;
    }

    // -----------------------------------------------------------------------
    // Kurze Auskuenfte zum Dateisystem
    // -----------------------------------------------------------------------

    public function exists(string $path): bool   { return file_exists($path); }
    public function isFile(string $path): bool   { return is_file($path); }
    public function isDir(string $path): bool    { return is_dir($path); }
    public function readable(string $path): bool { return is_readable($path); }
    public function writable(string $path): bool { return is_writable($path); }

    public function size(string $file): int|false {
        if (!is_file($file)) return false;
        return filesize($file);
    }

    public function sizeHuman(string $file, int $precision = 2): string|false {
        $size = $this->size($file);
        return $size === false ? false : $this->formatBytes($size, $precision);
    }

    public function modified(string $path, ?string $format = null): int|string|false {
        return $this->timestamp(filemtime(...), $path, $format);
    }

    public function created(string $path, ?string $format = null): int|string|false {
        return $this->timestamp(filectime(...), $path, $format);
    }

    public function accessed(string $path, ?string $format = null): int|string|false {
        return $this->timestamp(fileatime(...), $path, $format);
    }

    public function mime(string $file): string|false {
        if (!is_file($file)) return false;
        return mime_content_type($file);
    }

    // -----------------------------------------------------------------------
    // Lesen, schreiben, verschieben - Fehlergrund steht in $lastError
    // -----------------------------------------------------------------------

    public function read(string $file): string|false {
        if (!is_file($file) || !is_readable($file)) {
            return $this->fail("nicht lesbar: $file");
        }

        $content = file_get_contents($file);

        return $content === false ? $this->fail("Lesen fehlgeschlagen: $file") : $content;
    }

    public function lines(string $file, bool $skipEmpty = false): array|false {
        $content = $this->read($file);
        if ($content === false) return false;

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        if ($lines !== [] && end($lines) === '') array_pop($lines);

        if ($skipEmpty) {
            $lines = array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));
        }

        return $lines;
    }

    public function write(string $file, string $content, bool $append = false): bool {
        $dir = $this->dir($file);
        if ($dir !== '' && !is_dir($dir) && !$this->mkdir($dir)) {
            return false;
        }

        $bytes = file_put_contents($file, $content, $append ? FILE_APPEND | LOCK_EX : LOCK_EX);

        return $bytes === false ? $this->fail("Schreiben fehlgeschlagen: $file") : true;
    }

    public function append(string $file, string $content): bool {
        return $this->write($file, $content, true);
    }

    public function touch(string $file): bool {
        $dir = $this->dir($file);
        if ($dir !== '' && !is_dir($dir) && !$this->mkdir($dir)) {
            return false;
        }

        return touch($file) ? true : $this->fail("touch fehlgeschlagen: $file");
    }

    public function mkdir(string $dir, int $mode = 0775): bool {
        if (is_dir($dir)) return true;
        if (mkdir($dir, $mode, true) || is_dir($dir)) return true;

        return $this->fail("Verzeichnis nicht anlegbar: $dir");
    }

    public function copy(string $source, string $target, bool $overwrite = true): bool {
        if (!is_file($source)) return $this->fail("Quelle fehlt: $source");
        if (!$overwrite && file_exists($target)) return $this->fail("Ziel existiert: $target");

        $dir = $this->dir($target);
        if ($dir !== '' && !is_dir($dir) && !$this->mkdir($dir)) return false;

        return copy($source, $target) ? true : $this->fail("Kopieren fehlgeschlagen: $source -> $target");
    }

    public function move(string $source, string $target, bool $overwrite = true): bool {
        if (!file_exists($source)) return $this->fail("Quelle fehlt: $source");
        if (!$overwrite && file_exists($target)) return $this->fail("Ziel existiert: $target");

        $dir = $this->dir($target);
        if ($dir !== '' && !is_dir($dir) && !$this->mkdir($dir)) return false;

        return rename($source, $target) ? true : $this->fail("Verschieben fehlgeschlagen: $source -> $target");
    }

    public function delete(string $path, bool $recursive = false): bool {
        if (!file_exists($path) && !is_link($path)) return true;

        if (is_dir($path) && !is_link($path)) {
            if (!$recursive) {
                if ($this->getDirContents($path) !== []) {
                    return $this->fail("Verzeichnis nicht leer: $path");
                }
                return rmdir($path) ? true : $this->fail("Verzeichnis nicht loeschbar: $path");
            }
            foreach ($this->getDirContents($path) as $entry) {
                if (!$this->delete($entry, true)) return false;
            }
            return rmdir($path) ? true : $this->fail("Verzeichnis nicht loeschbar: $path");
        }

        return unlink($path) ? true : $this->fail("Datei nicht loeschbar: $path");
    }

    // -----------------------------------------------------------------------
    // Aufraeumen - leere Dateien und leere Verzeichnisse rekursiv entfernen
    // -----------------------------------------------------------------------

    /**
     * Laeuft rekursiv durch $directory und loescht alle Dateien mit 0 Byte
     * sowie alle Verzeichnisse, die danach leer sind. Verzeichnisse werden von
     * innen nach aussen geprueft, ein Ordner der nur leere Dateien enthaelt
     * verschwindet also komplett. $directory selbst bleibt immer stehen,
     * Symlinks werden nicht verfolgt und nicht geloescht.
     *
     * Rueckgabe: ['files' => [...], 'dirs' => [...], 'failed' => [...]]
     */
    public function clearEmpty(string $directory, bool $dryRun = false): array|false {
        if (is_link($directory) || !is_dir($directory)) {
            return $this->fail("Verzeichnis fehlt: $directory");
        }

        $root = realpath($directory);
        if ($root === false) {
            return $this->fail("Pfad nicht aufloesbar: $directory");
        }

        $aReport = ['files' => [], 'dirs' => [], 'failed' => []];
        $this->clearEmptyDir($root, $dryRun, $aReport);

        return $aReport;
    }

    private function clearEmptyDir(string $dir, bool $dryRun, array &$aReport): bool {
        $empty = true;

        foreach ($this->getDirContents($dir) as $entry) {
            if (is_link($entry)) {
                $empty = false;
                continue;
            }

            if (is_dir($entry)) {
                if ($this->clearEmptyDir($entry, $dryRun, $aReport)) {
                    if ($dryRun || rmdir($entry)) {
                        $aReport['dirs'][] = $entry;
                        continue;
                    }
                    $aReport['failed'][] = $entry;
                }
                $empty = false;
                continue;
            }

            if (is_file($entry) && filesize($entry) === 0) {
                if ($dryRun || unlink($entry)) {
                    $aReport['files'][] = $entry;
                    continue;
                }
                $aReport['failed'][] = $entry;
            }

            $empty = false;
        }

        return $empty;
    }

    private function clean(string $path): string {
        return str_replace('\\', '/', $path);
    }

    private function timestamp(callable $fn, string $path, ?string $format): int|string|false {
        if (!file_exists($path)) return false;

        $time = $fn($path);
        if ($time === false) return false;

        return $format === null ? $time : date($format, $time);
    }

    private function fail(string $message): false {
        $this->lastError = $message;
        return false;
    }

    private function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }


    private function permissionsString(string $file): string {
        $perms = fileperms($file);
        $type  = match(true) {
            ($perms & 0xC000) === 0xC000 => 's',
            ($perms & 0xA000) === 0xA000 => 'l',
            ($perms & 0x8000) === 0x8000 => '-',
            ($perms & 0x6000) === 0x6000 => 'b',
            ($perms & 0x4000) === 0x4000 => 'd',
            ($perms & 0x2000) === 0x2000 => 'c',
            ($perms & 0x1000) === 0x1000 => 'p',
            default                      => 'u',
        };

        return $type
            . (($perms & 0x0100) ? 'r' : '-')
            . (($perms & 0x0080) ? 'w' : '-')
            . (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x') : (($perms & 0x0800) ? 'S' : '-'))
            . (($perms & 0x0020) ? 'r' : '-')
            . (($perms & 0x0010) ? 'w' : '-')
            . (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x') : (($perms & 0x0400) ? 'S' : '-'))
            . (($perms & 0x0004) ? 'r' : '-')
            . (($perms & 0x0002) ? 'w' : '-')
            . (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x') : (($perms & 0x0200) ? 'T' : '-'));
    }
}
