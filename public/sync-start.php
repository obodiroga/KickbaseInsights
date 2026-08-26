<?php
/**
 * Startet einen Sync als Hintergrundprozess. Antwortet sofort, den
 * Fortschritt holt sich das Frontend von sync-status.php.
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

$token = isset($_POST['token']) ? $_POST['token'] : '';
$error = WebSync::guard($config, $db, $token);
if ($error !== null) {
    jsonOut(['ok' => false, 'error' => $error], 403);
}

$profile = isset($_POST['profile']) ? (string) $_POST['profile'] : '';
$result  = WebSync::start($db, $config, $profile);

jsonOut([
    'ok'     => $result['ok'],
    'error'  => $result['error'],
    'run_id' => $result['run_id'],
    'status' => WebSync::statusPayload($db, $config),
], $result['ok'] ? 200 : 409);
