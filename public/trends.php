<?php
/**
 * Marktwert-Gewinner und -Verlierer sowie das Preis-Leistungs-Ranking.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse = new Analyse($db);
$status  = $analyse->status();

$days = isset($_GET['days']) ? (int) $_GET['days'] : 7;
if (!in_array($days, [1, 3, 7, 14, 30], true)) {
    $days = 7;
}
$pos = isset($_GET['pos']) ? $_GET['pos'] : '';

$risers  = $analyse->movers($days, 'up', 15);
$fallers = $analyse->movers($days, 'down', 15);
$value   = $analyse->valueRanking($pos !== '' ? (int) $pos : null, 25);
$rated   = $analyse->ratedCount($pos !== '' ? (int) $pos : null);

renderHeader('Trends', 'trends', $status);

if (!$status['mv_points']) {
    renderEmptyHint('Marktwert-Daten');
    renderFooter();
    exit;
}

/** Kompakte Tabelle fuer Gewinner/Verlierer. */
function moversTable(array $rows)
{
    if (!$rows) {
        echo '<p class="muted">Keine Daten für diesen Zeitraum.</p>';
        return;
    }
    ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Spieler</th>
                <th class="num">Marktwert</th>
                <th class="num">Veränderung</th>
                <th class="num">%</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
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
                            </span>
                        </div>
                    </td>
                    <td class="num"><?= money($row['market_value']) ?></td>
                    <td class="num <?= trendClass($row['mv_change']) ?>"><?= moneyDelta($row['mv_change']) ?></td>
                    <td class="num <?= trendClass($row['mv_change']) ?>"><?= pct($row['mv_change_pct']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>

<form class="inline" method="get">
    <select name="days">
        <?php foreach ([1 => '1 Tag', 3 => '3 Tage', 7 => '7 Tage', 14 => '14 Tage', 30 => '30 Tage'] as $k => $label): ?>
            <option value="<?= $k ?>"<?= $days === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Zeitraum ändern</button>
</form>

<div class="cols">
    <div>
        <h2>Größte Gewinner (<?= $days ?> Tage)</h2>
        <?php moversTable($risers); ?>
    </div>
    <div>
        <h2>Größte Verlierer (<?= $days ?> Tage)</h2>
        <?php moversTable($fallers); ?>
    </div>
</div>

<h2>Preis-Leistung: Punkte pro Million</h2>

<p class="muted">
    Gewertet werden Spieler mit mindestens <?= (int) $rated['min_matches'] ?>
    Einsätzen – das sind <?= (int) $rated['rated'] ?> von
    <?= (int) $rated['with_points'] ?> mit bekanntem Punkteschnitt.
    Punkte pro Million bevorzugt sonst billige Spieler, die einmal gut
    gepunktet haben.
</p>

<?php if ($rated['rated'] < 10): ?>
    <div class="card notice">
        <p class="warn">Die Wertung ist gerade dünn (<?= (int) $rated['rated'] ?> Spieler).</p>
        <p class="muted">
            <?php if ($status['matchday'] && (int) $status['matchday'] <= (int) $rated['min_matches']): ?>
                Das ist zu Saisonbeginn normal: gezählt werden die Einsätze der
                laufenden Saison, und nach Spieltag <?= (int) $status['matchday'] ?>
                hat noch niemand <?= (int) $rated['min_matches'] ?> davon. Die Liste
                füllt sich mit jedem Spieltag.
            <?php else: ?>
                Für viele Spieler fehlt noch die Einsatzzahl. Sie kommt mit den
                Saison-Aggregaten – oben einen Standardlauf starten oder
                <code>php bin/sync.php --agg-only</code> ausführen.
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<form class="inline" method="get">
    <input type="hidden" name="days" value="<?= $days ?>">
    <select name="pos">
        <option value="">Alle Positionen</option>
        <?php foreach ([1 => 'Torwart', 2 => 'Abwehr', 3 => 'Mittelfeld', 4 => 'Sturm'] as $k => $label): ?>
            <option value="<?= $k ?>"<?= (string) $pos === (string) $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filtern</button>
</form>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>Spieler</th>
            <th class="num">Marktwert</th>
            <th class="num">Ø Punkte</th>
            <th class="num">Spiele</th>
            <th class="num">Pkt/Mio</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($value as $i => $row): ?>
            <tr>
                <td class="muted"><?= $i + 1 ?></td>
                <td>
                    <div class="player-cell">
                        <?php if ($row['image']): ?>
                            <img src="<?= e(kbImage($row['image'])) ?>" alt="" loading="lazy">
                        <?php endif; ?>
                        <span>
                            <span class="pos"><?= e(Kickbase::positionName($row['position'])) ?></span>
                            <a href="player.php?id=<?= e($row['player_id']) ?>"><?= e(playerName($row)) ?></a>
                            <?= statusBadge($row['status']) ?>
                        </span>
                    </div>
                </td>
                <td class="num"><?= money($row['market_value']) ?></td>
                <td class="num"><?= e($row['avg_points']) ?></td>
                <td class="num"><?= $row['matches'] !== null ? e($row['matches']) : '–' ?></td>
                <td class="num"><?= number_format($row['points_per_million'], 1, ',', '.') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="muted">
    Das Ranking beruht auf Durchschnittspunkten. Spieler mit wenigen Einsätzen können dadurch
    zu weit oben stehen – die Spalte „Spiele“ hilft beim Einordnen.
</p>

<?php renderFooter(); ?>
