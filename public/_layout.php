<?php
/**
 * Gemeinsames Seitengeruest.
 */

function renderHeader($title, $active = '', array $status = null)
{
    $nav = [
        'index'   => ['Dashboard', 'index.php'],
        'lineup'  => ['Aufstellung', 'lineup.php'],
        'market'  => ['Transfermarkt', 'market.php'],
        'trends'  => ['Trends', 'trends.php'],
        'radar'   => ['Radar', 'radar.php'],
        'compare' => ['Vergleich', 'compare.php'],
        'accuracy' => ['Prognose', 'accuracy.php'],
    ];
    ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> – Kickbase Insights</title>
<link rel="stylesheet" href="assets/app.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/app.js" defer></script>
</head>
<body>
<header class="topbar">
    <div class="wrap">
        <a class="brand" href="index.php">Kickbase<span>Insights</span></a>
        <nav>
            <?php foreach ($nav as $key => $entry): ?>
                <a href="<?= e($entry[1]) ?>"<?= $key === $active ? ' class="active"' : '' ?>><?= e($entry[0]) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($status): ?>
            <div class="sync-info" title="Letzter Datenabgleich">
                <?php if (!empty($status['last_sync'])): ?>
                    Stand: <?= e(date('d.m. H:i', strtotime($status['last_sync']))) ?>
                <?php else: ?>
                    <span class="warn">noch kein Sync</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php renderSyncControl(); ?>
    </div>
</header>
<main class="wrap">
    <h1><?= e($title) ?></h1>
    <?php
}

/**
 * Sync-Knopf mit Profilauswahl. Den Fortschritt holt assets/app.js von
 * sync-status.php, der Lauf selbst ist ein Hintergrundprozess.
 */
function renderSyncControl()
{
    global $config, $db;

    if (empty($config['web_sync']['enabled'])) {
        return;
    }

    $active = WebSync::activeRun($db, $config);
    ?>
    <div class="sync-box" data-token="<?= e(WebSync::token($db)) ?>"
         data-running="<?= $active ? '1' : '0' ?>">
        <select class="sync-profile" aria-label="Umfang des Abgleichs">
            <?php foreach (WebSync::profiles() as $key => $profile): ?>
                <option value="<?= e($key) ?>" title="<?= e($profile['hint']) ?>">
                    <?= e($profile['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn sync-btn">Aktualisieren</button>
        <span class="sync-state" role="status"></span>
    </div>
    <?php
}

function renderFooter()
{
    ?>
</main>
<footer class="wrap muted">
    Daten aus der inoffiziellen Kickbase-API. Lokale Auswertung, keine offizielle Anwendung.
</footer>
</body>
</html>
    <?php
}

/** Zeigt einen Hinweis, wenn noch keine Daten da sind. */
function renderEmptyHint($what = 'Daten')
{
    ?>
    <div class="card notice">
        <p>Es sind noch keine <?= e($what) ?> vorhanden.</p>
        <p class="muted">
            Oben rechts <strong>Alles</strong> auswaehlen und auf
            <strong>Aktualisieren</strong> klicken - das dauert beim ersten Mal
            15 bis 30 Minuten.
        </p>
        <p class="muted">Kommt hier nichts an, fehlt noch die Einrichtung:</p>
        <pre>cd C:\xampp\htdocs\kickbase
php bin/setup.php</pre>
    </div>
    <?php
}

/** Spielername kompakt. */
function playerName(array $row)
{
    $name = pick($row, ['known_name', 'last_name'], null);
    if ($name) {
        return $name;
    }
    return trim(pick($row, ['first_name'], '') . ' ' . pick($row, ['last_name'], '')) ?: 'Unbekannt';
}

/** Kleiner farbiger Badge fuer den Spielerstatus. */
function statusBadge($status)
{
    $status = (int) $status;
    if ($status === 0) {
        return '';
    }
    return '<span class="badge warn">' . e(Kickbase::statusName($status)) . '</span>';
}

/** Score als farbiger Balken. */
function scoreBar($score)
{
    if ($score === null) {
        return '<span class="muted">–</span>';
    }
    $cls = $score >= 70 ? 'good' : ($score >= 45 ? 'mid' : 'low');
    return sprintf(
        '<div class="scorebar"><div class="fill %s" style="width:%d%%"></div><span>%s</span></div>',
        $cls, (int) round($score), number_format($score, 0, ',', '.')
    );
}
