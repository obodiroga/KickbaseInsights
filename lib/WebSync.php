<?php
/**
 * Sync per Knopfdruck aus dem Browser.
 *
 * Der Lauf selbst bleibt ein CLI-Prozess - ein Standardlauf dauert eine
 * Minute, ein voller bis zu einer halben Stunde, das ueberlebt kein
 * Web-Request. Diese Klasse startet den Prozess also nur, legt vorher die
 * Zeile in sync_runs an und liefert dem Frontend den Fortschritt.
 */
class WebSync
{
    /**
     * Erlaubte Laeufe. Der Browser schickt nur den Schluessel, die Argumente
     * stehen ausschliesslich hier - sonst waere das eine Einladung zur
     * Command Injection.
     */
    public static function profiles()
    {
        return [
            'market' => [
                'label' => 'Transfermarkt',
                'hint'  => 'Markt und eigener Kader, wenige Sekunden',
                'args'  => ['--market'],
                'task'  => 'market',
            ],
            'standard' => [
                'label' => 'Standard',
                'hint'  => 'alle Spieler, dazu 60 Marktwert-Historien und 60 Aggregate, etwa 1,5 Minuten',
                'args'  => [],
                'task'  => 'full',
            ],
            'full' => [
                'label' => 'Alles',
                'hint'  => 'komplette Erstbefuellung, 15 bis 30 Minuten',
                'args'  => ['--full'],
                'task'  => 'full',
            ],
        ];
    }

    /**
     * Pfad zum PHP-CLI, in dieser Reihenfolge:
     *
     *   1. config.php (haendisch gesetzt gewinnt immer)
     *   2. was bin/setup.php gespeichert hat - dort laeuft PHP als CLI und
     *      kennt seinen eigenen Pfad exakt
     *   3. geraten anhand ueblicher Installationen
     *
     * Im Webserver taugt PHP_BINARY nicht: es zeigt auf httpd.exe.
     *
     * @return string|null
     */
    public static function phpBinary(Db $db, array $config)
    {
        $configured = isset($config['web_sync']['php_bin']) ? trim($config['web_sync']['php_bin']) : '';
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $stored = $db->getMeta('php_bin');
        if ($stored && is_file($stored)) {
            return $stored;
        }

        foreach (self::phpCandidates() as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /** Uebliche Orte fuer das PHP-CLI. */
    private static function phpCandidates()
    {
        $sep  = DIRECTORY_SEPARATOR;
        $exe  = $sep === '\\' ? 'php.exe' : 'php';
        $out  = [];

        // Im CLI stimmt PHP_BINARY. Im Webserver zeigt es auf den Webserver
        // selbst (etwa C:\xampp\apache\bin\httpd.exe) - von dort aus liegt das
        // PHP zwei oder drei Ebenen hoeher in einem Nachbarverzeichnis.
        if (PHP_SAPI === 'cli') {
            $out[] = PHP_BINARY;
        } else {
            $dir = dirname(PHP_BINARY);
            for ($i = 0; $i < 3; $i++) {
                $dir    = dirname($dir);
                $out[]  = $dir . $sep . 'php' . $sep . $exe;
            }
        }

        $out[] = PHP_BINDIR . $sep . $exe;

        if ($sep === '\\') {
            foreach (['C:\\xampp\\php', 'C:\\laragon\\bin\\php', 'C:\\php'] as $dir) {
                $out[] = $dir . $sep . $exe;
            }
            foreach ((array) glob('C:\\wamp64\\bin\\php\\php*\\php.exe') as $hit) {
                $out[] = $hit;
            }
        } else {
            foreach (['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin'] as $dir) {
                $out[] = $dir . '/php';
            }
        }

        return $out;
    }

    /**
     * Gemeinsames Geheimnis gegen Aufrufe von fremden Seiten. Die koennen es
     * wegen der Same-Origin-Policy nicht auslesen, blind mitschicken also
     * auch nicht.
     */
    public static function token(Db $db)
    {
        $token = $db->getMeta('web_token');
        if (!$token) {
            $token = bin2hex(random_bytes(16));
            $db->setMeta('web_token', $token);
        }
        return $token;
    }

    /**
     * Laufender Sync oder null. Raeumt dabei Laeufe ab, deren Prozess
     * offensichtlich nicht mehr lebt.
     */
    public static function activeRun(Db $db, array $config)
    {
        $stale = isset($config['web_sync']['stale_after']) ? (int) $config['web_sync']['stale_after'] : 45;

        $db->run(
            "UPDATE sync_runs SET finished_at = NOW(), status = 'error',
                    message = CONCAT('Kein Lebenszeichen seit ', ?, ' Minuten - Prozess vermutlich beendet.')
             WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$stale, $stale]
        );

        return $db->one("SELECT * FROM sync_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1");
    }

    /**
     * Startet den Hintergrundprozess.
     *
     * @return array ['ok' => bool, 'error' => string|null, 'run_id' => int|null]
     */
    public static function start(Db $db, array $config, $profileKey)
    {
        $profiles = self::profiles();
        if (!isset($profiles[$profileKey])) {
            return self::fail('Unbekannter Lauf.');
        }
        $profile = $profiles[$profileKey];

        $phpBin = self::phpBinary($db, $config);
        if ($phpBin === null) {
            return self::fail('PHP-CLI nicht gefunden. Fuehre "php bin/setup.php" aus '
                . 'oder trage den Pfad in config.php unter web_sync.php_bin ein.');
        }

        $running = self::activeRun($db, $config);
        if ($running) {
            return self::fail('Es laeuft schon ein Sync (seit '
                . date('H:i', strtotime($running['started_at'])) . ').');
        }

        // Zeile vor dem Start anlegen: sonst klafft zwischen Klick und dem
        // Moment, in dem der Prozess sie selbst schreibt, eine Luecke, in der
        // ein zweiter Klick durchkaeme.
        $db->run(
            'INSERT INTO sync_runs (task, started_at, status) VALUES (?, NOW(), ?)',
            [$profile['task'], 'running']
        );
        $runId = (int) $db->pdo()->lastInsertId();

        $root   = dirname(__DIR__);
        $script = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'sync.php';
        $log    = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'sync-web.log';

        $args = $profile['args'];
        $args[] = '--run=' . $runId;

        $cmd = self::backgroundCommand($phpBin, $script, $args, $log);

        $handle = popen($cmd, 'r');
        if ($handle === false) {
            $db->run(
                "UPDATE sync_runs SET finished_at = NOW(), status = 'error', message = ? WHERE id = ?",
                ['Prozess konnte nicht gestartet werden.', $runId]
            );
            return self::fail('Prozess konnte nicht gestartet werden.');
        }
        pclose($handle);

        return ['ok' => true, 'error' => null, 'run_id' => $runId];
    }

    /**
     * Kommandozeile fuer einen abgekoppelten Prozess. Unter Windows macht das
     * "start /B", sonst nohup - beide Male so, dass der Web-Request nicht auf
     * das Ende wartet.
     */
    private static function backgroundCommand($phpBin, $script, array $args, $log)
    {
        $parts = [escapeshellarg($phpBin), escapeshellarg($script)];
        foreach ($args as $arg) {
            $parts[] = $arg;   // stammen aus profiles(), nicht vom Client
        }
        $call = implode(' ', $parts);

        if (DIRECTORY_SEPARATOR === '\\') {
            // Der leere Titel ist Pflicht: start deutet ein erstes
            // Anfuehrungszeichen-Argument sonst als Fenstertitel.
            return 'start "" /B ' . $call . ' > ' . escapeshellarg($log) . ' 2>&1';
        }
        return 'nohup ' . $call . ' > ' . escapeshellarg($log) . ' 2>&1 &';
    }

    /**
     * Fortschritt fuer das Frontend.
     */
    public static function statusPayload(Db $db, array $config)
    {
        $running = self::activeRun($db, $config);
        $last    = $db->one('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1');

        $payload = [
            'running'    => $running !== null,
            'last_sync'  => $db->getMeta('last_sync'),
            'run'        => null,
            'elapsed'    => null,
        ];

        $row = $running !== null ? $running : $last;
        if ($row) {
            $payload['run'] = [
                'id'          => (int) $row['id'],
                'task'        => $row['task'],
                'status'      => $row['status'],
                'started_at'  => $row['started_at'],
                'finished_at' => $row['finished_at'],
                'message'     => $row['message'],
            ];
            $end = $row['finished_at'] ? strtotime($row['finished_at']) : time();
            $payload['elapsed'] = max(0, $end - strtotime($row['started_at']));
        }

        return $payload;
    }

    /**
     * Darf dieser Aufrufer einen Sync starten? Die App ist fuer localhost
     * gedacht; ein Endpoint, der Prozesse startet, soll nicht mehr koennen.
     */
    public static function guard(array $config, Db $db, $token)
    {
        if (empty($config['web_sync']['enabled'])) {
            return 'Der Sync per Browser ist in config.php abgeschaltet.';
        }

        $allowed = isset($config['web_sync']['allow_ips']) ? $config['web_sync']['allow_ips'] : [];
        $remote  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if ($allowed && !in_array($remote, $allowed, true)) {
            return 'Von dieser Adresse nicht erlaubt: ' . $remote;
        }

        if (!hash_equals(self::token($db), (string) $token)) {
            return 'Ungueltiges Token. Seite neu laden.';
        }

        return null;
    }

    private static function fail($message)
    {
        return ['ok' => false, 'error' => $message, 'run_id' => null];
    }
}
