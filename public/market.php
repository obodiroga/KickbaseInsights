<?php
/**
 * Transfermarkt der eigenen Liga mit Bewertung der Angebote.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse  = new Analyse($db);
$status   = $analyse->status();
$leagueId = currentLeagueId($config, $db);

$listings = $leagueId ? $analyse->openListings($leagueId) : [];

// Filter
$posFilter = isset($_GET['pos']) ? $_GET['pos'] : '';
$onlyFit   = !empty($_GET['fit']);

if ($posFilter !== '') {
    $listings = array_values(array_filter($listings, function ($row) use ($posFilter) {
        return (int) $row['position'] === (int) $posFilter;
    }));
}
if ($onlyFit) {
    $listings = array_values(array_filter($listings, function ($row) {
        return (int) $row['status'] === 0;
    }));
}

renderHeader('Transfermarkt', 'market', $status);

if (!$leagueId) {
    renderEmptyHint('Ligadaten');
    renderFooter();
    exit;
}
?>

<form class="inline" method="get">
    <select name="pos">
        <option value="">Alle Positionen</option>
        <?php foreach ([1 => 'Torwart', 2 => 'Abwehr', 3 => 'Mittelfeld', 4 => 'Sturm'] as $k => $label): ?>
            <option value="<?= $k ?>"<?= (string) $posFilter === (string) $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="muted">
        <input type="checkbox" name="fit" value="1"<?= $onlyFit ? ' checked' : '' ?>> nur einsatzbereite
    </label>
    <button type="submit">Filtern</button>
</form>

<?php if (!$listings): ?>
    <div class="card notice">
        <p>Aktuell stehen keine Angebote auf dem Markt – oder es wurde noch nicht synchronisiert.</p>
        <p class="muted">Aktualisieren mit: <code>php bin/sync.php --market</code></p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Score</th>
                <th>Spieler</th>
                <th class="num">Preis</th>
                <th class="num">Marktwert</th>
                <th class="num">Abschlag</th>
                <th class="num">7 Tage</th>
                <th class="num">Ø Pkt</th>
                <th class="num">Pkt/Mio</th>
                <th>Laeuft ab</th>
                <th>Verkäufer</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($listings as $row): ?>
                <tr>
                    <td><?= scoreBar($row['score']) ?></td>
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
                    <td class="num"><?= money($row['price']) ?></td>
                    <td class="num"><?= money($row['mv']) ?></td>
                    <td class="num <?= trendClass($row['discount']) ?>">
                        <?= $row['discount'] !== null ? pct($row['discount']) : '–' ?>
                    </td>
                    <td class="num <?= trendClass(isset($row['trend7']['abs']) ? $row['trend7']['abs'] : null) ?>">
                        <?= $row['trend7'] ? pct($row['trend7']['pct']) : '–' ?>
                    </td>
                    <td class="num"><?= $row['avg_points'] !== null ? e($row['avg_points']) : '–' ?></td>
                    <td class="num"><?= $row['ppm'] !== null ? number_format($row['ppm'], 1, ',', '.') : '–' ?></td>
                    <td><?= e(untilText($row['expires_at'])) ?></td>
                    <td class="muted"><?= e($row['seller_name'] ?: 'Kickbase') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Wie der Score entsteht</h2>
        <p class="muted">
            Der Score ist der gewichtete Rang eines Angebots innerhalb der aktuell verfuegbaren Angebote:
            <strong>45 %</strong> Preisabschlag gegenueber dem Marktwert,
            <strong>35 %</strong> Punkte pro Million,
            <strong>20 %</strong> Marktwert-Trend der letzten sieben Tage.
            Verletzte oder gesperrte Spieler werden halbiert. 100 heisst also nicht „sicherer Kauf“,
            sondern „im Vergleich zu den anderen Angeboten am attraktivsten“.
        </p>
        <p class="muted">
            Der Score kennt keine Aufstellungssituation und keine Gegnerstaerke – die stehen auf der
            jeweiligen Spielerseite.
        </p>
    </div>
<?php endif; ?>

<?php renderFooter(); ?>
