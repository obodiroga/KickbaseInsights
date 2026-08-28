<?php
/**
 * Holt aktuelle Daten von Kickbase. Fuer den Cron gedacht.
 *
 *   php bin/sync.php                 Standardlauf (Kader, Markt, 60 Marktwert-Historien)
 *   php bin/sync.php --full          Alles inkl. Spielerstammdaten, 600 Historien
 *   php bin/sync.php --mv=200        Anzahl Marktwert-Historien pro Lauf
 *   php bin/sync.php --no-players    Spielerstammdaten ueberspringen
 *   php bin/sync.php --market        Nur den Transfermarkt (schnell, fuer haeufige Laeufe)
 *   php bin/sync.php --perf          Nur die Spieltags-Punkte des eigenen Kaders
 *   php bin/sync.php --agg-only      Nur die Saison-Aggregate (Punkte, Einsaetze)
 *   php bin/sync.php --agg=300       Anzahl Spieler fuer die Aggregate pro Lauf
 *   php bin/sync.php --quiet         Keine Ausgabe
 *   php bin/sync.php --run=12        Bestehende sync_runs-Zeile benutzen (Web-Button)
 */

if (PHP_SAPI !== 'cli') {
    die('Nur ueber die Kommandozeile ausfuehren.');
}

set_time_limit(0);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

// -------------------------------------------------------------- Argumente

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $args[$m[1]] = isset($m[2]) ? $m[2] : true;
    }
}

$quiet      = isset($args['quiet']);
$full       = isset($args['full']);
$marketOnly = isset($args['market']);
$mvLimit    = isset($args['mv']) ? (int) $args['mv'] : ($full ? 600 : 60);
$withPlayers = !isset($args['no-players']) && ($full || !$marketOnly);
$runId      = isset($args['run']) ? (int) $args['run'] : 0;
$aggLimit   = isset($args['agg']) ? (int) $args['agg'] : ($full ? 1000 : 60);

/**
 * Schliesst die vom Web-Button angelegte Lauf-Zeile. Ohne das bliebe sie auf
 * 'running' stehen und wuerde jeden weiteren Sync blockieren.
 */
function closeRun(Db $db, $runId, $status, $message)
{
    if ($runId <= 0) {
        return;
    }
    try {
        $db->run(
            "UPDATE sync_runs SET finished_at = NOW(), status = ?, message = ?
             WHERE id = ? AND status = 'running'",
            [$status, $message !== null ? substr($message, 0, 2000) : null, $runId]
        );
    } catch (Exception $ex) {
        // Wenn nicht mal das geht, hilft nur noch der stale-Timeout.
    }
}

// Netz fuer harte Abbrueche (Fatal Error, Timeout) - die fangen weder
// try/catch hier noch die in Sync.
if ($runId > 0) {
    register_shutdown_function(function () use ($db, $runId) {
        $err = error_get_last();
        $fatal = $err !== null
            && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
        closeRun($db, $runId, 'error', $fatal
            ? 'Abgebrochen: ' . $err['message']
            : 'Der Sync-Prozess hat sich unerwartet beendet.');
    });
}

$leagueId = currentLeagueId($config, $db);
if (!$leagueId) {
    $msg = "Keine Liga bekannt. Fuehre zuerst 'php bin/setup.php' aus.";
    closeRun($db, $runId, 'error', $msg);
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

$sync = new Sync($db, $kb, $config, !$quiet);
$start = microtime(true);

try {
    if (isset($args['agg-only'])) {
        $sync->syncPlayerAggregates($leagueId, $aggLimit);
        closeRun($db, $runId, 'ok', null);
    } elseif (isset($args['perf'])) {
        $sync->syncSchedule($config['kickbase']['competition_id']);
        $sync->syncPerformances($leagueId);
        $db->setMeta('last_sync', date('c'));
        closeRun($db, $runId, 'ok', null);
    } elseif ($marketOnly) {
        $sync->runMarket($leagueId, ['run_id' => $runId]);
    } else {
        $sync->runAll($leagueId, [
            'players'   => $withPlayers,
            'mv_limit'  => $mvLimit,
            'timeframe' => $config['app']['mv_timeframe'],
            'agg_limit' => $aggLimit,
            'run_id'    => $runId,
        ]);
    }
} catch (Exception $ex) {
    fwrite(STDERR, 'Sync fehlgeschlagen: ' . $ex->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    printf("\nDauer: %.1f Sekunden\n", microtime(true) - $start);
}
