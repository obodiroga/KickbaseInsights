<?php
/**
 * Spielerdetail: Marktwert-Verlauf, Kennzahlen, kommende Gegner.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse  = new Analyse($db);
$status   = $analyse->status();
$leagueId = currentLeagueId($config, $db);

$playerId = isset($_GET['id']) ? trim($_GET['id']) : '';
$player   = $playerId !== '' ? $analyse->player($playerId) : null;

if (!$player) {
    renderHeader('Spieler', '', $status);
    echo '<div class="card notice"><p>Spieler nicht gefunden.</p>'
       . '<p class="muted"><a href="index.php">Zurueck zum Dashboard</a></p></div>';
    renderFooter();
    exit;
}

$name    = playerName($player);
$history = $analyse->marketValueHistory($playerId, 365);
$trend7  = $analyse->trend($playerId, 7);
$trend30 = $analyse->trend($playerId, 30);
$trend90 = $analyse->trend($playerId, 90);
$mv      = (int) $player['market_value'];
$ppm     = ($mv > 0 && $player['avg_points'] !== null) ? $player['avg_points'] / ($mv / 1000000) : null;

// Kommende Spiele und Detailinfos kommen live von der API, aber gecacht.
$detail = null;
if ($leagueId) {
    try {
        $detail = cacheRemember('player_' . $playerId, 1800, function () use ($kb, $leagueId, $playerId) {
            return $kb->player($leagueId, $playerId);
        });
    } catch (Exception $ex) {
        $detail = null;
    }
}

// Ist der Spieler gerade auf dem Markt?
$listing = $leagueId ? $db->one(
    'SELECT * FROM market_listings WHERE league_id = ? AND player_id = ? AND gone_at IS NULL',
    [$leagueId, $playerId]
) : null;

renderHeader($name, '', $status);
?>

<div class="stats">
    <div class="stat">
        <div class="label">Marktwert</div>
        <div class="value"><?= money($mv) ?></div>
        <div class="sub <?= trendClass($trend7 ? $trend7['abs'] : null) ?>">
            <?= $trend7 ? moneyDelta($trend7['abs']) . ' (7 Tage)' : 'keine Historie' ?>
        </div>
    </div>
    <div class="stat">
        <div class="label">Punkte</div>
        <div class="value"><?= $player['avg_points'] !== null ? e($player['avg_points']) : '–' ?> Ø</div>
        <div class="sub">Gesamt <?= number_format((int) $player['total_points'], 0, ',', '.') ?>
            <?php if ($player['matches']): ?> · <?= (int) $player['matches'] ?> Spiele<?php endif; ?>
        </div>
    </div>
    <div class="stat">
        <div class="label">Punkte pro Million</div>
        <div class="value"><?= $ppm !== null ? number_format($ppm, 1, ',', '.') : '–' ?></div>
        <div class="sub"><?= e(Kickbase::positionName($player['position'])) ?>
            <?php if ((int) $player['status'] !== 0): ?>
                · <span class="warn"><?= e(Kickbase::statusName($player['status'])) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="stat">
        <div class="label">Trend</div>
        <div class="value <?= trendClass($trend30 ? $trend30['abs'] : null) ?>">
            <?= $trend30 ? pct($trend30['pct']) : '–' ?>
        </div>
        <div class="sub">30 Tage<?= $trend90 ? ' · 90 Tage ' . pct($trend90['pct']) : '' ?></div>
    </div>
</div>

<?php if ($listing): ?>
    <div class="card">
        <strong>Steht aktuell auf dem Transfermarkt</strong> für <?= money($listing['price']) ?>
        <?php if ($mv > 0 && $listing['price'] !== null): ?>
            <span class="<?= trendClass($mv - (int) $listing['price']) ?>">
                (<?= pct(($mv - (int) $listing['price']) / $mv * 100) ?> gegenüber Marktwert)
            </span>
        <?php endif; ?>
        · läuft ab in <?= e(untilText($listing['expires_at'])) ?>
        <?php if ($listing['seller_name']): ?> · Verkäufer: <?= e($listing['seller_name']) ?><?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($history): ?>
    <div class="chart-box">
        <canvas id="mvChart"></canvas>
    </div>
    <script>
    (function () {
        var labels = <?= json_encode(array_column($history, 'day')) ?>;
        var values = <?= json_encode(array_map('intval', array_column($history, 'market_value'))) ?>;
        var dark   = window.matchMedia('(prefers-color-scheme: dark)').matches;
        var grid   = dark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.07)';
        var tick   = dark ? '#8d97a5' : '#67717f';

        new Chart(document.getElementById('mvChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Marktwert',
                    data: values,
                    borderColor: '#4ea1ff',
                    backgroundColor: 'rgba(78,161,255,0.12)',
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHitRadius: 12,
                    tension: 0.25,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return (ctx.parsed.y / 1000000).toFixed(2).replace('.', ',') + ' Mio';
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { color: grid }, ticks: { color: tick, maxTicksLimit: 10 } },
                    y: {
                        grid: { color: grid },
                        ticks: {
                            color: tick,
                            callback: function (v) { return (v / 1000000).toFixed(1).replace('.', ',') + ' Mio'; }
                        }
                    }
                }
            }
        });
    })();
    </script>
<?php else: ?>
    <div class="card notice">
        <p class="muted">Für diesen Spieler ist noch keine Marktwert-Historie gespeichert.
            Sie wird beim nächsten Sync geholt.</p>
    </div>
<?php endif; ?>

<?php
// Kommende Spiele aus den Live-Details.
$fixtures = ($detail && isset($detail['mdsum']) && is_array($detail['mdsum'])) ? $detail['mdsum'] : [];
$upcoming = array_values(array_filter($fixtures, function ($f) {
    return empty($f['cur']) && isset($f['md']) && strtotime($f['md']) > time();
}));
$upcoming = array_slice($upcoming, 0, 5);
?>

<div class="cols">
    <?php if ($upcoming): ?>
        <div class="card">
            <h2 style="margin-top:0">Kommende Spiele</h2>
            <table>
                <tbody>
                <?php foreach ($upcoming as $f): ?>
                    <tr>
                        <td class="muted"><?= e(date('d.m. H:i', strtotime($f['md']))) ?></td>
                        <td>Spieltag <?= e(pick($f, ['day'], '?')) ?></td>
                        <td class="muted">Team <?= e(pick($f, ['t1'], '?')) ?> – <?= e(pick($f, ['t2'], '?')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($detail): ?>
        <div class="card">
            <h2 style="margin-top:0">Weitere Angaben</h2>
            <table>
                <tbody>
                <tr><td class="muted">Verein</td><td><?= e(pick($detail, ['tn'], '–')) ?></td></tr>
                <tr><td class="muted">Rückennummer</td><td><?= e(pick($detail, ['shn'], '–')) ?></td></tr>
                <tr><td class="muted">Tore / Vorlagen</td>
                    <td><?= e(pick($detail, ['g'], 0)) ?> / <?= e(pick($detail, ['a'], 0)) ?></td></tr>
                <tr><td class="muted">Karten</td>
                    <td><?= e(pick($detail, ['y'], 0)) ?> gelb, <?= e(pick($detail, ['r'], 0)) ?> rot</td></tr>
                <tr><td class="muted">Einsatzminuten</td><td><?= e(pick($detail, ['sec'], 0) / 60 | 0) ?> min</td></tr>
                <tr><td class="muted">Kaufwert (Kickbase)</td><td><?= money(pick($detail, ['cv'])) ?></td></tr>
                <?php if (pick($detail, ['plpt'])): ?>
                    <tr><td class="muted">Startelf-Prognose</td>
                        <td><?= !empty($detail['sl']) ? 'wahrscheinlich' : 'unsicher' ?>
                            <span class="muted">(<?= e($detail['plpt']) ?>)</span></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<p class="muted">
    <a href="compare.php?ids=<?= e($playerId) ?>">Diesen Spieler vergleichen</a>
</p>

<?php renderFooter(); ?>
