<?php
/**
 * Prueft die API-Verbindung und schreibt echte Roh-Antworten nach var/dumps/.
 * Nuetzlich, um die abgekuerzten Feldnamen gegen die echten Daten abzugleichen -
 * die oeffentliche Dokumentation ist an einigen Stellen unvollstaendig.
 *
 *   php bin/test-api.php
 */

if (PHP_SAPI !== 'cli') {
    die('Nur ueber die Kommandozeile ausfuehren.');
}

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

$dumpDir = $root . '/var/dumps';
if (!is_dir($dumpDir)) {
    mkdir($dumpDir, 0777, true);
}

function dumpIt($name, $data)
{
    global $dumpDir;
    file_put_contents(
        $dumpDir . '/' . $name . '.json',
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    echo "   -> var/dumps/{$name}.json\n";
}

/** Zeigt die Feldnamen des ersten Listeneintrags. */
function showKeys($label, array $items)
{
    if (!$items) {
        echo "   {$label}: leer\n";
        return;
    }
    $first = reset($items);
    if (!is_array($first)) {
        echo "   {$label}: kein Objekt\n";
        return;
    }
    echo "   {$label} (" . count($items) . " Eintraege), Felder des ersten:\n";
    foreach ($first as $k => $v) {
        $preview = is_array($v) ? '[' . implode(', ', array_slice(array_keys($v), 0, 5)) . ']' : $v;
        if (is_string($preview) && strlen($preview) > 45) {
            $preview = substr($preview, 0, 45) . '...';
        }
        printf("      %-8s = %s\n", $k, is_bool($preview) ? var_export($preview, true) : $preview);
    }
}

echo "API-Test\n========\n\n";

echo "1. Login\n";
$res = $kb->login();
echo "   OK\n";
dumpIt('login', $res);

echo "\n2. Ligen\n";
$leagues = $kb->leagues();
showKeys('Ligen', $leagues);
dumpIt('leagues', $leagues);

$leagueId = currentLeagueId($config, $db);
if (!$leagueId && $leagues) {
    $leagueId = (string) pick($leagues[0], ['i']);
}
if (!$leagueId) {
    echo "\nKeine Liga gefunden - Abbruch.\n";
    exit(1);
}
echo "\n   Verwendete Liga: {$leagueId}\n";

echo "\n3. Budget\n";
$budget = $kb->budget($leagueId);
echo '   ' . json_encode($budget) . "\n";
dumpIt('budget', $budget);

echo "\n4. Kader\n";
$squad = $kb->squad($leagueId);
showKeys('Kader', $squad);
dumpIt('squad', $squad);

echo "\n5. Transfermarkt\n";
$market = $kb->market($leagueId);
$items  = isset($market['it']) ? $market['it'] : [];
echo '   Meta: ' . json_encode(array_diff_key($market, ['it' => 1])) . "\n";
showKeys('Angebote', $items);
dumpIt('market', $market);

// Referenzspieler fuer die Detail-Endpoints bestimmen.
$refPlayer = null;
if ($items) {
    $refPlayer = pick(reset($items), ['i', 'pi']);
} elseif ($squad) {
    $refPlayer = pick(reset($squad), ['i', 'pi']);
}

if ($refPlayer) {
    echo "\n6. Spielerdetails (Spieler {$refPlayer})\n";
    $player = $kb->player($leagueId, $refPlayer);
    printf("   %s %s, Team %s, MV %s, Ø %s Punkte\n",
        pick($player, ['fn'], ''), pick($player, ['ln'], ''),
        pick($player, ['tn'], '?'), money(pick($player, ['mv'])), pick($player, ['ap'], '?'));
    dumpIt('player', $player);

    echo "\n7. Marktwert-Historie\n";
    $history = $kb->marketValueHistory($leagueId, $refPlayer, 92);
    echo '   ' . count($history) . " Datenpunkte\n";
    if ($history) {
        $first = reset($history);
        $last  = end($history);
        echo "   {$first['date']}: " . money($first['value']) . "  ->  {$last['date']}: " . money($last['value']) . "\n";
    }
    dumpIt('marketvalue', $history);
}

echo "\n8. Wettbewerbs-Spieler (Position Mittelfeld)\n";
$compPlayers = $kb->competitionPlayers($config['kickbase']['competition_id'], '3', '');
showKeys('Spieler', $compPlayers);
dumpIt('competition_players', $compPlayers);

echo "\n9. Aktivitaeten\n";
try {
    $act = $kb->activities($leagueId, 0, 10);
    $actItems = pick($act, ['af', 'it'], []);
    showKeys('Aktivitaeten', (array) $actItems);
    dumpIt('activities', $act);
} catch (Exception $ex) {
    echo '   Fehler: ' . $ex->getMessage() . "\n";
}

echo "\nFertig. Die Dumps liegen in var/dumps/.\n";
