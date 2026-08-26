<?php
/**
 * Dashboard: eigener Kader, Budget, Tagesveraenderung.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse  = new Analyse($db);
$status   = $analyse->status();
$leagueId = currentLeagueId($config, $db);

$squad  = $leagueId ? $analyse->squad($leagueId) : [];
$totals = $analyse->squadTotals($squad);
$budget = $status['budget'] !== null ? (int) $status['budget'] : null;

renderHeader('Dashboard', 'index', $status);

if (!$leagueId || !$squad) {
    renderEmptyHint('Kaderdaten');
    renderFooter();
    exit;
}
?>

<div class="stats">
    <div class="stat">
        <div class="label">Teamwert</div>
        <div class="value"><?= money($totals['value']) ?></div>
        <div class="sub <?= trendClass($totals['day_change']) ?>">
            <?= moneyDelta($totals['day_change']) ?> heute
        </div>
    </div>
    <div class="stat">
        <div class="label">Budget</div>
        <div class="value"><?= money($budget) ?></div>
        <div class="sub">Gesamt: <?= money($totals['value'] + (int) $budget) ?></div>
    </div>
    <div class="stat">
        <div class="label">Gewinn seit Kauf</div>
        <div class="value <?= trendClass($totals['gain']) ?>"><?= moneyDelta($totals['gain']) ?></div>
        <div class="sub"><?= $totals['count'] ?> Spieler im Kader</div>
    </div>
    <div class="stat">
        <div class="label">Punkte gesamt</div>
        <div class="value"><?= number_format($totals['points'], 0, ',', '.') ?></div>
        <div class="sub">
            <?php if ($status['matchday']): ?>Spieltag <?= e($status['matchday']) ?><?php endif; ?>
        </div>
    </div>
</div>

<h2>Dein Kader</h2>
<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Spieler</th>
            <th class="num">Marktwert</th>
            <th class="num">Heute</th>
            <th class="num">7 Tage</th>
            <th class="num">30 Tage</th>
            <th class="num">Ø Pkt</th>
            <th class="num">Pkt/Mio</th>
            <th class="num">Gewinn</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($squad as $row): ?>
            <tr>
                <td>
                    <div class="player-cell">
                        <?php if ($row['image']): ?>
                            <img src="<?= e(kbImage($row['image'])) ?>" alt="" loading="lazy">
                        <?php endif; ?>
                        <span>
                            <span class="pos"><?= e(Kickbase::positionName($row['position'])) ?></span>
                            <a href="player.php?id=<?= e($row['player_id']) ?>"><?= e(playerName($row)) ?></a>
                            <?= statusBadge($row['status']) ?>
                            <?php if ($row['on_market']): ?>
                                <span class="badge">auf dem Markt</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </td>
                <td class="num"><?= money($row['mv']) ?></td>
                <td class="num <?= trendClass($row['day_change']) ?>"><?= moneyDelta($row['day_change']) ?></td>
                <td class="num <?= trendClass(isset($row['trend7']['abs']) ? $row['trend7']['abs'] : null) ?>">
                    <?= isset($row['trend7']) && $row['trend7'] ? pct($row['trend7']['pct']) : '–' ?>
                </td>
                <td class="num <?= trendClass(isset($row['trend30']['abs']) ? $row['trend30']['abs'] : null) ?>">
                    <?= isset($row['trend30']) && $row['trend30'] ? pct($row['trend30']['pct']) : '–' ?>
                </td>
                <td class="num"><?= $row['avg_points'] !== null ? e($row['avg_points']) : '–' ?></td>
                <td class="num"><?= $row['ppm'] !== null ? number_format($row['ppm'], 1, ',', '.') : '–' ?></td>
                <td class="num <?= trendClass($row['mv_gain']) ?>"><?= moneyDelta($row['mv_gain']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="muted">
    Pkt/Mio = Durchschnittspunkte je Million Marktwert. Je hoeher, desto effizienter ist der Kaderplatz belegt.
</p>

<?php if ($status['mv_pending'] > 0): ?>
    <div class="card notice">
        <p class="muted">
            Fuer <?= (int) $status['mv_pending'] ?> Spieler fehlt noch die Marktwert-Historie.
            Sie wird bei den naechsten Sync-Laeufen nachgeholt
            (<code>php bin/sync.php --mv=300</code>).
        </p>
    </div>
<?php endif; ?>

<?php if ($status['last_error']): ?>
    <div class="card notice">
        <p class="warn">Letzter Sync-Fehler
            (<?= e(date('d.m. H:i', strtotime($status['last_error']['started_at']))) ?>):</p>
        <pre><?= e($status['last_error']['message']) ?></pre>
    </div>
<?php endif; ?>

<?php renderFooter(); ?>
