<?php
/**
 * Fortschritt des letzten bzw. laufenden Syncs als JSON.
 * Wird vom Frontend im Sekundentakt abgefragt - haelt sich deshalb kurz.
 */

require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(WebSync::statusPayload($db, $config), JSON_UNESCAPED_UNICODE);
