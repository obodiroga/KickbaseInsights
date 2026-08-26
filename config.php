<?php
/**
 * Basis-Konfiguration. Echte Zugangsdaten gehoeren in config.local.php,
 * die von .gitignore ausgeschlossen ist.
 */

$config = [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'kickbase',
        'user'     => 'root',
        'pass'     => 'root',
        'charset'  => 'utf8mb4',
    ],

    'kickbase' => [
        'email'    => '',   // -> config.local.php
        'password' => '',   // -> config.local.php
        'base_url' => 'https://api.kickbase.com',
        // Wird beim ersten Sync automatisch ermittelt, falls leer.
        'league_id'      => '',
        'competition_id' => '1',   // 1 = Bundesliga
        'user_agent'     => 'kickbase/6.0.0 (iPhone; iOS 17.0)',
        // Sekunden zwischen zwei API-Requests im Sync. Nicht unter 0.3 setzen.
        'request_delay'  => 0.4,
    ],

    'app' => [
        'timezone' => 'Europe/Berlin',
        // Marktwert-Historie, die beim Erst-Sync pro Spieler geholt wird: 92 oder 365
        'mv_timeframe' => 365,
        'debug' => true,
    ],

    // Sync per Knopfdruck aus dem Browser (public/sync-start.php).
    'web_sync' => [
        'enabled' => true,
        // PHP-CLI, mit dem der Hintergrundprozess gestartet wird. Muss das
        // CLI-Binary sein, nicht das Apache-Modul.
        //
        // Leer lassen: bin/setup.php ermittelt den Pfad und legt ihn ab. Im
        // Webserver-Kontext ist er nicht ermittelbar - dort zeigt PHP_BINARY
        // auf httpd.exe und PHP_BINDIR auf ein Verzeichnis, das es nicht gibt.
        // Nur eintragen, wenn die Erkennung fehlschlaegt.
        'php_bin' => '',
        // Nur diese Absender-IPs duerfen einen Sync starten. Leer = jede.
        'allow_ips' => ['127.0.0.1', '::1'],
        // Laeuft ein Sync laenger, gilt er als abgestuerzt und blockiert
        // keinen neuen Lauf mehr (Minuten).
        'stale_after' => 45,
    ],
];

$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        foreach ($local as $section => $values) {
            if (is_array($values) && isset($config[$section]) && is_array($config[$section])) {
                $config[$section] = array_merge($config[$section], $values);
            } else {
                $config[$section] = $values;
            }
        }
    }
}

date_default_timezone_set($config['app']['timezone']);

return $config;
