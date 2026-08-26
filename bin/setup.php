<?php
/**
 * Einmaliges Setup: Datenbank + Tabellen anlegen, Login pruefen, Liga ermitteln.
 *
 *   php bin/setup.php
 */

if (PHP_SAPI !== 'cli') {
    die('Nur ueber die Kommandozeile ausfuehren.');
}

$root   = dirname(__DIR__);
$config = require $root . '/config.php';

require_once $root . '/lib/helpers.php';
require_once $root . '/lib/Db.php';
require_once $root . '/lib/Kickbase.php';

echo "Kickbase-Setup\n==============\n\n";

// -------------------------------------------------------------- Umgebung

echo "0. Umgebung\n";
printf("   PHP %s (%s)\n", PHP_VERSION, PHP_SAPI);

$fehlt = [];
if (version_compare(PHP_VERSION, '7.0', '<')) {
    $fehlt[] = 'PHP 7.0 oder neuer';
}
foreach (['curl' => 'curl', 'pdo_mysql' => 'PDO-MySQL', 'json' => 'JSON', 'mbstring' => 'mbstring'] as $ext => $label) {
    if (!extension_loaded($ext)) {
        $fehlt[] = 'PHP-Erweiterung ' . $label;
    }
}
if (!is_dir($root . '/var') && !@mkdir($root . '/var', 0777, true)) {
    $fehlt[] = 'Schreibrechte fuer das Verzeichnis var/';
}
if (is_dir($root . '/var') && !is_writable($root . '/var')) {
    $fehlt[] = 'Schreibrechte fuer das Verzeichnis var/';
}

if ($fehlt) {
    echo "   FEHLER - es fehlt:\n";
    foreach ($fehlt as $f) {
        echo "     - {$f}\n";
    }
    exit(1);
}
echo "   curl, PDO-MySQL, JSON, mbstring vorhanden, var/ beschreibbar.\n\n";

// ---------------------------------------------------------------- Datenbank

echo "1. Datenbank\n";
try {
    $server = new Db($config['db'], false);
    $server->run(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        $config['db']['name']
    ));
    echo "   Datenbank `{$config['db']['name']}` ist vorhanden.\n";
} catch (Exception $ex) {
    echo "   FEHLER: " . $ex->getMessage() . "\n";
    echo "   Pruefe die DB-Zugangsdaten in config.php bzw. config.local.php.\n";
    exit(1);
}

$db = new Db($config['db']);

$sql = file_get_contents($root . '/schema.sql');
// Kommentarzeilen entfernen, dann an Semikolon trennen.
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

foreach ($statements as $statement) {
    $db->run($statement);
}
echo "   " . count($statements) . " Tabellen-Statements ausgefuehrt.\n";

// Den eigenen Pfad festhalten: hier laeuft PHP als CLI und kennt ihn exakt.
// Im Webserver ist er nicht ermittelbar, der Sync-Knopf braucht ihn aber.
if (is_file(PHP_BINARY)) {
    $db->setMeta('php_bin', PHP_BINARY);
    echo "   PHP-CLI fuer den Sync-Knopf: " . PHP_BINARY . "\n";
} else {
    echo "   WARNUNG: PHP-CLI-Pfad nicht ermittelbar. Fuer den Sync-Knopf im\n";
    echo "   Browser 'web_sync.php_bin' in config.local.php eintragen.\n";
}
echo "\n";

// ------------------------------------------------------------------- Login

echo "2. Kickbase-Login\n";
if ($config['kickbase']['email'] === '' || $config['kickbase']['email'] === 'DEINE@EMAIL.DE') {
    echo "   Es sind noch keine Zugangsdaten hinterlegt.\n";
    echo "   Trage E-Mail und Passwort in config.local.php ein und starte das Setup erneut.\n";
    exit(1);
}

$kb = new Kickbase($config['kickbase'], $root . '/var/token.json');

try {
    $res = $kb->login();
    $user = isset($res['u']) ? $res['u'] : [];
    $name = pick($user, ['name', 'n', 'email'], $config['kickbase']['email']);
    echo "   Login erfolgreich als: {$name}\n\n";
} catch (Exception $ex) {
    echo "   FEHLER: " . $ex->getMessage() . "\n";
    exit(1);
}

// -------------------------------------------------------------------- Liga

echo "3. Ligen\n";
try {
    $leagues = $kb->leagues();
} catch (Exception $ex) {
    echo "   FEHLER beim Laden der Ligen: " . $ex->getMessage() . "\n";
    exit(1);
}

if (!$leagues) {
    echo "   Keine Ligen gefunden.\n";
    exit(1);
}

foreach ($leagues as $league) {
    printf("   [%s] %s  (Spieler: %s, Budget: %s)\n",
        pick($league, ['i']),
        pick($league, ['n']),
        pick($league, ['un'], '?'),
        money(pick($league, ['b']))
    );
}

$leagueId = $config['kickbase']['league_id'];
if (!$leagueId) {
    $leagueId = (string) pick($leagues[0], ['i']);
    echo "\n   Verwende Liga {$leagueId}.\n";
    if (count($leagues) > 1) {
        echo "   Du bist in mehreren Ligen - trage die gewuenschte ID in config.local.php\n";
        echo "   unter 'league_id' ein, falls das die falsche ist.\n";
    }
}
$db->setMeta('league_id', $leagueId);

echo "\nFertig.\n\n";
echo "Naechste Schritte:\n";
echo "  php bin/sync.php --full     Erstbefuellung (dauert 20 Minuten und mehr)\n";

// Die URL haengt davon ab, wo das Projekt liegt - nicht raten, sondern
// aus dem Pfad ableiten, wenn es unter einem Document Root liegt.
$publicDir = $root . DIRECTORY_SEPARATOR . 'public';
echo "  Danach im Browser das Verzeichnis public/ aufrufen:\n";
echo "    {$publicDir}\n";
if (preg_match('~[/\\\\](htdocs|www|public_html)[/\\\\](.+)$~i', $root, $m)) {
    echo '    z.B. http://localhost/' . str_replace('\\', '/', $m[2]) . "/public/\n";
}
