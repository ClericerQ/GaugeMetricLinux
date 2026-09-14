<?php
class cLog {
    public $datetime;
    public string $logDir = '';
    private static ?cLog $instance = null;

    public function __construct(?string $logDir = null) {
        $this->datetime = date("d-m-Y H:i:s", time());

        $this->logDir = $this->resolveLogDir($logDir);

        $this->registerErrorHandler();
        $this->registerShutdownHandler();
    }

    // Erstes beschreibbares Verzeichnis aus: Parameter -> CONFIG -> log/ im Projekt -> /tmp.
    private function resolveLogDir(?string $preferred): string {
        $candidates = [];
        if ($preferred !== null && $preferred !== '') {
            $candidates[] = $preferred;
        }
        if (defined('CONFIG') && !empty(CONFIG['log']['log_dir'])) {
            $candidates[] = CONFIG['log']['log_dir'];
        }
        $candidates[] = dirname(__DIR__) . '/log/';

        foreach ($candidates as $dir) {
            $dir = rtrim($dir, '/') . '/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }

        return rtrim(sys_get_temp_dir(), '/') . '/';
    }

    private static function cfg(string $key, bool $default = false): bool {
        if (!defined('CONFIG')) {
            return $default;
        }
        return (bool) (CONFIG['log'][$key] ?? $default);
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function log(string $log_file, string $data, int $UserID = 0): void {
        $user = $UserID > 0 ? "[user $UserID] " : '';

        $logMessage = date("d-m-Y H:i:s") . ':> ' . $user . $data . "\n";
        $this->emit($log_file, $logMessage);
    }

    private function emit(string $log_file, string $message): void {
        if (self::cfg('print_log')) {
            if (self::stdoutIsSeparate()) {
                fwrite(self::stdout(), $message);
            } else {
                error_log(rtrim($message, "\n"));
            }
        }

        if (self::cfg('write_log')) {
            @file_put_contents($this->logDir . $log_file, $message, FILE_APPEND);
        }
    }

    // Nur cli/cli-server trennen stdout von der HTTP-Antwort; unter fpm landet es sonst im Body.
    private static function stdoutIsSeparate(): bool {
        return in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg', 'embed'], true);
    }

    private static function stdout() {
        static $handle = null;
        if ($handle === null) {
            $handle = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
        }
        return $handle;
    }

    private function registerErrorHandler(): void {
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
            if (!(error_reporting() & $errno)) {
                return false;
            }

            $level = match ($errno) {
                E_WARNING,      E_USER_WARNING  => 'WARNING',
                E_NOTICE,       E_USER_NOTICE   => 'NOTICE',
                E_DEPRECATED,   E_USER_DEPRECATED => 'DEPRECATED',
                E_STRICT                        => 'STRICT',
                default                         => 'ERROR',
            };

            $category = $this->classifyError($errno, $errstr);

            $msg = sprintf(
                "[%s] [%s]%s %s in %s:%d\n",
                date('Y-m-d H:i:s'),
                $level,
                $category ? " [$category]" : '',
                $errstr,
                $errfile,
                $errline
            );

            $logFile = $this->resolveLogFile($category, $level);
            $this->emit($logFile, $msg);

            return true;
        });
    }

    private function registerShutdownHandler(): void {
        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $msg = sprintf(
                    "[%s] [FATAL] %s in %s:%d\n",
                    date('Y-m-d H:i:s'),
                    $error['message'],
                    $error['file'],
                    $error['line']
                );
                $this->emit('error.log', $msg);
            }
        });
    }

    private function classifyError(int $errno, string $errstr): string {
        if (in_array($errno, [E_NOTICE, E_USER_NOTICE, E_WARNING, E_USER_WARNING])) {
            if (str_contains($errstr, 'Undefined variable'))  return 'UNDEFINED_VAR';
            if (str_contains($errstr, 'Undefined array key')) return 'UNDEFINED_KEY';
            if (str_contains($errstr, 'Undefined property'))  return 'UNDEFINED_PROP';
            if (str_contains($errstr, 'Trying to access'))    return 'BAD_ACCESS';
        }
        return '';
    }

    private function resolveLogFile(string $category, string $level): string {
        return match (true) {
            in_array($category, ['UNDEFINED_VAR', 'UNDEFINED_KEY', 'UNDEFINED_PROP', 'BAD_ACCESS'])
                                    => 'var_access.log',
            $level === 'WARNING'    => 'warning.log',
            $level === 'NOTICE'     => 'notice.log',
            $level === 'DEPRECATED' => 'deprecated.log',
            default                 => 'error.log',
        };
    }
}
