<?PHP

class cDatabase {
    public ?PDO $pdo = null;
    public $lastinsertid, $rowcount;

    private string $driver = 'mysql';

    public function __construct(
        ?string $Server = null,
        ?string $DB     = null,
        ?string $DBUser = null,
        ?string $DBPass = null,
        int|string|null $DBPort = null
    ) {
        $cfg = (defined('CONFIG') && isset(CONFIG['database'])) ? CONFIG['database'] : [];

        $Server ??= $cfg['DBIP']   ?? 'sqlite';
        $DB     ??= $cfg['DB']     ?? '';
        $DBUser ??= $cfg['DBUser'] ?? '';
        $DBPass ??= $cfg['DBPass'] ?? '';
        $DBPort ??= $cfg['DBPort'] ?? null;

        $this->driver = $this->detectDriver($Server, $DB);

        try {
            if ($this->driver === 'sqlite') {
                $this->pdo = new PDO('sqlite:' . $this->prepareSqlitePath($DB));

                $this->pdo->exec('PRAGMA foreign_keys = ON');
            } else {
                $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $Server, $DB);
                if ($DBPort !== null && (int) $DBPort > 0) {
                    $dsn = sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                        $Server, (int) $DBPort, $DB
                    );
                }
                $this->pdo = new PDO($dsn, $DBUser, $DBPass);
            }

            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            throw new RuntimeException(
                sprintf('cDatabase: Verbindung (%s) fehlgeschlagen: %s', $this->driver, $e->getMessage()),
                (int) $e->getCode()
            );
        }
    }

    public function driver(): string {
        return $this->driver;
    }

    public function singleDBValue($sql, $params = []) {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchColumn();
    }

    public function multipleDBValues($sql, $params = []) {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function writeToDB($sql, $params = []) {
        $stmt = $this->execute($sql, $params);

        $this->rowcount     = $stmt->rowCount();
        $this->lastinsertid = $this->isInsert($sql) ? $this->pdo->lastInsertId() : null;

        return $this->rowcount;
    }

    public function beginInsert(): void {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function endInsert(): void {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function cancelInsert(): void {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inInsert(): bool {
        return $this->pdo->inTransaction();
    }

    private function execute(string $sql, array $params): PDOStatement {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new RuntimeException('cDatabase: Query fehlgeschlagen: ' . $e->getMessage(), (int) $e->getCode());
        }
    }

    private function detectDriver(string $server, string $db): string {
        if (in_array(strtolower(trim($server)), ['sqlite', 'sqlite3'], true)) {
            return 'sqlite';
        }
        if (preg_match('/\.(db|sqlite|sqlite3)$/i', $db)) {
            return 'sqlite';
        }
        return 'mysql';
    }

    private function prepareSqlitePath(string $db): string {
        $db = trim($db);
        if ($db === '' || $db === ':memory:') {
            return ':memory:';
        }

        if ($db[0] !== '/') {
            $db = dirname(__DIR__) . '/' . ltrim($db, './');
        }

        $dir = dirname($db);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("cDatabase: Verzeichnis '$dir' kann nicht angelegt werden");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("cDatabase: Verzeichnis '$dir' ist nicht beschreibbar");
        }

        return $db;
    }

    private function isInsert(string $sql): bool {
        return (bool) preg_match('/^\s*(?:INSERT|REPLACE)\b/i', $sql);
    }
}
