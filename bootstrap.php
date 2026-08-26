<?php
/**
 * Gemeinsamer Einstiegspunkt fuer CLI-Scripte und Web-Seiten.
 * Stellt $config, $db und $kb bereit.
 */

mb_internal_encoding('UTF-8');

$config = require __DIR__ . '/config.php';

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Kickbase.php';
require_once __DIR__ . '/lib/Sync.php';
require_once __DIR__ . '/lib/Analyse.php';
require_once __DIR__ . '/lib/WebSync.php';

if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
}

/** @var Db $db */
$db = new Db($config['db']);

/** @var Kickbase $kb */
$kb = new Kickbase($config['kickbase'], __DIR__ . '/var/token.json');

/** Liga-ID aus Config oder DB. */
function currentLeagueId(array $config, Db $db)
{
    if (!empty($config['kickbase']['league_id'])) {
        return $config['kickbase']['league_id'];
    }
    return $db->getMeta('league_id');
}
