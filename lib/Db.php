<?php
/**
 * Duenner PDO-Wrapper. Bewusst klein gehalten - kein ORM noetig.
 */
class Db
{
    /** @var PDO */
    private $pdo;

    public function __construct(array $cfg, $withDatabase = true)
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);
        if ($withDatabase) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
        }

        $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function pdo()
    {
        return $this->pdo;
    }

    public function run($sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function all($sql, array $params = [])
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one($sql, array $params = [])
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value($sql, array $params = [], $default = null)
    {
        $val = $this->run($sql, $params)->fetchColumn();
        return $val === false ? $default : $val;
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE fuer ein Assoc-Array.
     * @param array $updateCols Spalten, die beim Konflikt aktualisiert werden. Leer = alle.
     */
    public function upsert($table, array $data, array $updateCols = [])
    {
        $cols         = array_keys($data);
        $placeholders = [];
        $params       = [];
        foreach ($cols as $c) {
            $placeholders[] = ':' . $c;
            $params[':' . $c] = $data[$c];
        }

        $updateCols = $updateCols ?: $cols;
        $updates    = [];
        foreach ($updateCols as $c) {
            $updates[] = "`{$c}` = VALUES(`{$c}`)";
        }

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            implode('`, `', $cols),
            implode(', ', $placeholders),
            implode(', ', $updates)
        );

        return $this->run($sql, $params);
    }

    public function setMeta($key, $value)
    {
        $this->upsert('meta', ['k' => $key, 'v' => is_scalar($value) ? (string) $value : json_encode($value)], ['v']);
    }

    public function getMeta($key, $default = null)
    {
        $val = $this->value('SELECT v FROM meta WHERE k = ?', [$key]);
        return $val === null ? $default : $val;
    }
}
