<?php
/**
 * Spielervergleich: Kennzahlen nebeneinander plus gemeinsamer Marktwert-Verlauf.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse = new Analyse($db);
$status  = $analyse->status();

$ids = [];
if (!empty($_GET['ids'])) {
    $ids = array_values(array_filter(array_map('trim', explode(',', $_GET['ids']))));
}
$ids = array_slice(array_unique($ids), 0, 6);

// Suche
$term    = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = $term !== '' ? $analyse->search($term) : [];

$players = $ids ? $analyse->compare($ids) : [];

$colors = ['#4ea1ff', '#3ec98a', '#e8b046', '#ef6b6b', '#b07cf0', '#4ecfd0'];

renderHeader('Spielervergleich', 'compare', $status);
?>

<form class="inline" method="get">
    <input type="hidden" name="ids" value="<?= e(implode(',', $ids)) ?>">
    <input type="text" name="q" placeholder="Spieler suchen …" value="<?= e($term) ?>" autofocus>
    <button type="submit">Suchen</button>
    <?php if ($ids): ?>
        <a href="compare.php"><button type="button" class="ghost">Auswahl leeren</button></a>
    <?php endif; ?>
</form>

<?php if ($term !== ''): ?>
    <div class="card">
        <?php if (!$results): ?>
            <p class="muted">Keine Treffer für „<?= e($term) ?>“.</p>
        <?php else: ?>
            <table>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <?php $newIds = array_unique(array_merge($ids, [$row['player_id']])); ?>
                    <tr>
                        <td>
                            <div class="player-cell">
                                <?php if ($row['image']): ?>
                                    <img src="<?= e(kbImage($row['image'])) ?>" alt="" loading="lazy">
                                <?php endif; ?>
                                <span>
                                    <span class="pos"><?= e(Kickbase::positionName($row['position'])) ?></span>
                                    <?= e(playerName($row)) ?>
                                </span>
                            </div>
                        </td>
                        <td class="num"><?= money($row['market_value']) ?></td>
                        <td class="num"><?= $row['avg_points'] !== null ? e($row['avg_points']) . ' Ø' : '–' ?></td>
                        <td>
                            <a href="compare.php?ids=<?= e(implode(',', $newIds)) ?>">hinzufügen</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$players): ?>
    <div class="card notice">
        <p>Such dir oben Spieler und füge sie dem Vergleich hinzu – bis zu sechs gleichzeitig.</p>
    </div>
<?php else: ?>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Kennzahl</th>
                <?php foreach ($players as $i => $p): ?>
                    <th style="color:<?= $colors[$i % count($colors)] ?>">
                        <?= e(playerName($p)) ?>
                    </th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="muted">Position</td>
                <?php foreach ($players as $p): ?>
                    <td><?= e(Kickbase::positionName($p['position'])) ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="muted">Marktwert</td>
                <?php foreach ($players as $p): ?>
                    <td class="num"><?= money($p['market_value']) ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="muted">Ø Punkte</td>
                <?php foreach ($players as $p): ?>
                    <td class="num"><?= $p['avg_points'] !== null ? e($p['avg_points']) : '–' ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="muted">Punkte gesamt</td>
                <?php foreach ($players as $p): ?>
                    <td class="num"><?= number_format((int) $p['total_points'], 0, ',', '.') ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="muted">Spiele</td>
                <?php foreach ($players as $p): ?>
                    <td class="num"><?= $p['matches'] !== null ? e($p['matches']) : '–' ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="muted">Punkte pro Million</td>
                <?php foreach ($players as $p): ?>
                    <td class="num"><?= $p['ppm'] !== null ? number_format($p['ppm'], 1, ',', '.') : '–' ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="muted">Tore / Vorlagen</td>
                <?php foreach ($players as $p): ?>
                    <td class="num"><?= (int) $p['goals'] ?> / <?= (int) $p['assists'] ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="muted">Trend 7 Tage</td>
                <?php foreach ($players as $p): ?>
                    <td class="num <?= trendClass($p['trend7'] ? $p['trend7']['abs'] : null) ?>">
                        <?= $p['trend7'] ? pct($p['trend7']['pct']) : '–' ?>
                    </td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="muted">Trend 30 Tage</td>
                <?php foreach ($players as $p): ?>
                    <td class="num <?= trendClass($p['trend30'] ? $p['trend30']['abs'] : null) ?>">
                        <?= $p['trend30'] ? pct($p['trend30']['pct']) : '–' ?>
                    </td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="muted">Status</td>
                <?php foreach ($players as $p): ?>
                    <td><?= e(Kickbase::statusName($p['status'])) ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="muted"></td>
                <?php foreach ($players as $p): ?>
                    <?php $without = array_values(array_diff($ids, [$p['player_id']])); ?>
                    <td>
                        <a href="player.php?id=<?= e($p['player_id']) ?>">Details</a> ·
                        <a href="compare.php?ids=<?= e(implode(',', $without)) ?>">entfernen</a>
                    </td>
                <?php endforeach; ?>
            </tr>
            </tbody>
        </table>
    </div>

    <?php
    // Gemeinsame Datumsachse ueber alle Spieler bilden.
    $allDays = [];
    foreach ($players as $p) {
        foreach ($p['history'] as $point) {
            $allDays[$point['day']] = true;
        }
    }
    $allDays = array_keys($allDays);
    sort($allDays);

    $datasets = [];
    foreach ($players as $i => $p) {
        $byDay = [];
        foreach ($p['history'] as $point) {
            $byDay[$point['day']] = (int) $point['market_value'];
        }
        $series = [];
        foreach ($allDays as $day) {
            $series[] = isset($byDay[$day]) ? $byDay[$day] : null;
        }
        $datasets[] = [
            'label'       => playerName($p),
            'data'        => $series,
            'borderColor' => $colors[$i % count($colors)],
            'borderWidth' => 2,
            'pointRadius' => 0,
            'pointHitRadius' => 12,
            'tension'     => 0.25,
            'spanGaps'    => true,
        ];
    }
    ?>

    <?php if ($allDays): ?>
        <div class="chart-box">
            <canvas id="cmpChart"></canvas>
        </div>
        <script>
        (function () {
            var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            var grid = dark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.07)';
            var tick = dark ? '#8d97a5' : '#67717f';

            new Chart(document.getElementById('cmpChart'), {
                type: 'line',
                data: {
                    labels: <?= json_encode($allDays) ?>,
                    datasets: <?= json_encode($datasets) ?>
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { labels: { color: tick } },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    if (ctx.parsed.y === null) { return null; }
                                    return ctx.dataset.label + ': '
                                        + (ctx.parsed.y / 1000000).toFixed(2).replace('.', ',') + ' Mio';
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
    <?php endif; ?>

<?php endif; ?>

<?php renderFooter(); ?>
