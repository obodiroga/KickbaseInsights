<?php
/**
 * Kleine Hilfsfunktionen. Die Kickbase-API benutzt sehr kurze Feldnamen und
 * ist nicht ueberall konsistent - deshalb greifen wir Felder ueber eine
 * Kandidatenliste ab statt hart auf einen Namen zu setzen.
 */

/**
 * Erstes vorhandenes Feld aus $keys zurueckgeben.
 */
function pick(array $row, array $keys, $default = null)
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return $default;
}

function pickInt(array $row, array $keys, $default = null)
{
    $v = pick($row, $keys, null);
    return $v === null ? $default : (int) $v;
}

/** ISO-Zeitstempel der API in MySQL-DATETIME (lokale Zeit) umwandeln. */
function isoToMysql($iso)
{
    if (!$iso) {
        return null;
    }
    $ts = is_numeric($iso) ? (int) $iso : strtotime($iso);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** 12345678 -> "12,35 Mio" */
function money($value, $decimals = 2)
{
    if ($value === null || $value === '') {
        return '–';
    }
    $value = (float) $value;
    $sign  = $value < 0 ? '-' : '';
    $abs   = abs($value);

    if ($abs >= 1000000) {
        return $sign . number_format($abs / 1000000, $decimals, ',', '.') . ' Mio';
    }
    if ($abs >= 1000) {
        return $sign . number_format($abs / 1000, 0, ',', '.') . ' Tsd';
    }
    return $sign . number_format($abs, 0, ',', '.');
}

/** Vorzeichenbehaftete Ausgabe fuer Veraenderungen. */
function moneyDelta($value)
{
    if ($value === null) {
        return '–';
    }
    $prefix = $value > 0 ? '+' : '';
    return $prefix . money($value);
}

function pct($value, $decimals = 1)
{
    if ($value === null) {
        return '–';
    }
    $prefix = $value > 0 ? '+' : '';
    return $prefix . number_format($value, $decimals, ',', '.') . ' %';
}

function e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** CSS-Klasse fuer positive/negative Werte. */
function trendClass($value)
{
    if ($value === null || $value == 0) {
        return 'neutral';
    }
    return $value > 0 ? 'up' : 'down';
}

/** Bild-URL aus einem Kickbase-Pfad bauen. */
function kbImage($path)
{
    if (!$path) {
        return null;
    }
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    return 'https://kickbase.b-cdn.net/' . ltrim($path, '/');
}

/**
 * Einfacher Datei-Cache. Damit einzelne Live-Abfragen aus dem Frontend
 * (z. B. die kommenden Gegner eines Spielers) die API nicht bei jedem
 * Seitenaufruf treffen.
 *
 * @param callable $callback liefert die Daten, wenn der Cache kalt ist
 */
function cacheRemember($key, $ttl, $callback)
{
    $dir = dirname(__DIR__) . '/var/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $file = $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';

    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        $data = json_decode((string) file_get_contents($file), true);
        if ($data !== null) {
            return $data;
        }
    }

    $data = call_user_func($callback);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
    return $data;
}

/** Restlaufzeit in Sekunden lesbar machen. */
function untilText($datetime)
{
    if (!$datetime) {
        return '–';
    }
    $diff = strtotime($datetime) - time();
    if ($diff <= 0) {
        return 'abgelaufen';
    }
    $h = (int) floor($diff / 3600);
    $m = (int) floor(($diff % 3600) / 60);
    return $h > 0 ? "{$h} h {$m} min" : "{$m} min";
}
