<?php
/**
 * Speichert die geplante Aufstellung. Rein lokal - es geht nichts an Kickbase.
 */

require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonOut(array $payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Nur per POST.'], 405);
}

// Gleiche Schranke wie beim Sync: lokale Adresse und bekanntes Token.
$error = WebSync::guard($config, $db, isset($_POST['token']) ? $_POST['token'] : '');
if ($error !== null) {
    jsonOut(['ok' => false, 'error' => $error], 403);
}

$leagueId = currentLeagueId($config, $db);
if (!$leagueId) {
    jsonOut(['ok' => false, 'error' => 'Keine Liga bekannt.'], 409);
}

$analyseReset = new Analyse($db);
if (!empty($_POST['reset'])) {
    jsonOut($analyseReset->resetLineup($leagueId));
}

$formation = isset($_POST['formation']) ? (string) $_POST['formation'] : '';
$slotsRaw  = isset($_POST['slots']) ? (string) $_POST['slots'] : '';
$slots     = json_decode($slotsRaw, true);

if (!is_array($slots)) {
    jsonOut(['ok' => false, 'error' => 'Aufstellung nicht lesbar.'], 400);
}

$analyse = new Analyse($db);
if (!isset($analyse->formations()[$formation])) {
    jsonOut(['ok' => false, 'error' => 'Unbekannte Formation.'], 400);
}

$result = $analyse->saveLineup($leagueId, $formation, $slots);
jsonOut($result, $result['ok'] ? 200 : 409);
