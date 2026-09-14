<?php

class cThread {
    private int $concurrency;

    private int $timeout;

    private int $ownerPid;

    private const KILL_GRACE = 2;   // Sekunden Kulanz nach dem Timeout, dann SIGKILL.

    private const POLL_US = 1000;   // Wartezeit je Runde der Reap-Schleife.

    private array $running = [];

    private array $order = [];

    private array $results = [];
    private array $errors  = [];

    private $onResult = null;

    private bool $started = false;

    private array $stats = [
        'forked'  => 0,
        'inline'  => 0,
        'done'    => 0,
        'failed'  => 0,
        'timeout' => 0,
    ];

    public function __construct(int $concurrency = 32, int $timeout = 30) {
        $this->concurrency = max(1, $concurrency);
        $this->timeout     = max(0, $timeout);
        $this->ownerPid    = getmypid();
    }

    public static function available(): bool {
        return PHP_SAPI === 'cli'
            && function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_kill');
    }

    public function map(iterable $tasks, callable $fn, ?callable $onResult = null): array {
        $this->begin();
        foreach ($tasks as $key => $task) {
            $this->push($key, fn() => $fn($task, $key));
        }
        return $this->await($onResult);
    }

    public function begin(?int $concurrency = null, ?int $timeout = null): void {
        $this->assertOwner();

        if ($this->running) {
            $this->await();
        }
        if ($concurrency !== null) $this->concurrency = max(1, $concurrency);
        if ($timeout     !== null) $this->timeout     = max(0, $timeout);

        $this->running = $this->order = $this->results = $this->errors = [];
        $this->stats   = array_map(fn() => 0, $this->stats);
        $this->started = true;
    }

    public function push(string|int $key, callable $fn): void {
        $this->assertOwner();

        if (!$this->started) {
            $this->begin();
        }
        $this->order[] = $key;

        if (!self::available()) {
            $this->inline($key, $fn);
            return;
        }

        while (count($this->running) >= $this->concurrency) {
            $this->reap(true);
        }

        $file = @tempnam(sys_get_temp_dir(), 'cthr');
        if ($file === false) {
            $this->inline($key, $fn);
            return;
        }

        $pid = @pcntl_fork();

        if ($pid === -1) {
            @unlink($file);
            $this->inline($key, $fn);
            return;
        }
        if ($pid === 0) {
            $this->child($fn, $file);
        }

        $this->running[$pid] = ['key' => $key, 'file' => $file, 'start' => microtime(true)];
        $this->stats['forked']++;
    }

    public function await(?callable $onResult = null): array {
        $this->assertOwner();

        if ($onResult !== null && $this->results) {
            foreach ($this->results as $key => $value) {
                $onResult($key, $value, $this->errors[$key] ?? null);
            }
            $this->results = $this->errors = [];
        }

        $this->onResult = $onResult;

        while ($this->running) {
            $this->reap(true);
        }

        $this->onResult = null;
        $this->started  = false;

        if ($onResult !== null) {
            $this->order = [];
            return [];
        }

        $ordered = [];
        foreach ($this->order as $key) {
            if (array_key_exists($key, $this->results)) {
                $ordered[$key] = $this->results[$key];
            }
        }

        $this->results = [];
        $this->order   = [];
        return $ordered;
    }

    public function errors(): array {
        return $this->errors;
    }

    public function stats(): array {
        return $this->stats;
    }

    // Laeuft im Kindprozess: Ergebnis serialisiert in $file, danach SIGKILL statt exit,
    // damit Destruktoren und Shutdown-Handler des Elternprozesses hier nicht anlaufen.
    private function child(callable $fn, string $file): never {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if ($this->timeout > 0 && function_exists('pcntl_alarm')) {
            pcntl_alarm($this->timeout);
        }

        try {
            $data = serialize(['ok' => true, 'value' => $fn()]);
        } catch (Throwable $e) {
            $data = serialize(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]);
        }

        @file_put_contents($file, $data);

        posix_kill(posix_getpid(), SIGKILL);
        exit(1);
    }

    // Holt beendete Kinder ein; $block wartet, bis mindestens eines fertig ist.
    private function reap(bool $block): int {
        $collected = 0;

        while (true) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid > 0) {
                if (isset($this->running[$pid])) {
                    $this->collect($pid, $status);
                    $collected++;
                }
                continue;
            }

            if ($pid === -1) {
                foreach (array_keys($this->running) as $lost) {
                    $this->collect($lost, null);
                    $collected++;
                }
                break;
            }

            if (!$block || $collected > 0 || !$this->running) {
                break;
            }

            $this->enforceDeadline();
            usleep(self::POLL_US);
        }

        return $collected;
    }

    private function enforceDeadline(): void {
        if ($this->timeout <= 0) {
            return;
        }
        $limit = $this->timeout + self::KILL_GRACE;
        $now   = microtime(true);

        foreach ($this->running as $pid => $info) {
            if ($now - $info['start'] > $limit) {
                @posix_kill($pid, SIGKILL);
            }
        }
    }

    private function collect(int $pid, ?int $status): void {
        $info = $this->running[$pid] ?? null;
        if ($info === null) {
            return;
        }
        unset($this->running[$pid]);

        $raw = @file_get_contents($info['file']);
        @unlink($info['file']);

        $payload = ($raw === false || $raw === '') ? false : @unserialize($raw);

        if (is_array($payload) && ($payload['ok'] ?? false)) {
            $this->stats['done']++;
            $this->deliver($info['key'], $payload['value'], null);
            return;
        }

        if (is_array($payload)) {
            $this->stats['failed']++;
            $this->deliver($info['key'], null, (string) ($payload['error'] ?? 'unbekannter Fehler'));
            return;
        }

        $elapsed  = microtime(true) - $info['start'];
        $signal   = ($status !== null && pcntl_wifsignaled($status)) ? pcntl_wtermsig($status) : null;
        $timedOut = $this->timeout > 0 && $elapsed >= $this->timeout;

        if ($timedOut) {
            $this->stats['timeout']++;
            $this->deliver($info['key'], null, sprintf('Zeitueberschreitung nach %.1fs', $elapsed));
            return;
        }

        $this->stats['failed']++;
        $this->deliver($info['key'], null,
            'Prozess ohne Ergebnis beendet' . ($signal !== null ? " (Signal $signal)" : ''));
    }

    private function deliver(string|int $key, mixed $value, ?string $error): void {
        if ($this->onResult !== null) {
            ($this->onResult)($key, $value, $error);
            return;
        }
        $this->results[$key] = $value;
        if ($error !== null) {
            $this->errors[$key] = $error;
        }
    }

    // Fallback ohne pcntl: Task laeuft im aktuellen Prozess statt in einem Kind.
    private function inline(string|int $key, callable $fn): void {
        $this->stats['inline']++;
        try {
            $value = $fn();
            $this->stats['done']++;
            $this->deliver($key, $value, null);
        } catch (Throwable $e) {
            $this->stats['failed']++;
            $this->deliver($key, null, get_class($e) . ': ' . $e->getMessage());
        }
    }

    private function assertOwner(): void {
        if (getmypid() !== $this->ownerPid) {
            throw new RuntimeException(
                'cThread: Aufruf aus einem Kindprozess – verschachteltes Forken wird nicht unterstuetzt'
            );
        }
    }

    public function __destruct() {
        if (getmypid() !== $this->ownerPid || !$this->running) {
            return;
        }
        foreach ($this->running as $pid => $info) {
            @posix_kill($pid, SIGKILL);
            @unlink($info['file']);
        }
        foreach (array_keys($this->running) as $pid) {
            @pcntl_waitpid($pid, $status);
        }
        $this->running = [];
    }
}
