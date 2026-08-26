<?php
/**
 * Marktwert-Radar: wohin sich die Marktwerte voraussichtlich bewegen.
 *
 * Grundlage ist ein in den eigenen Daten gemessener Zusammenhang - gute
 * Punkte ziehen den Marktwert hoch, ein verpasstes Spiel drueckt ihn. Die
 * Punkte-Prognose wird darueber in eine Marktwert-Erwartung uebersetzt.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse  = new Analyse($db);
$status   = $analyse->status();
$leagueId = currentLeagueId($config, $db);

$days = isset($_GET['days']) ? (int) $_GET['days'] : 3;
if (!in_array($days, [1, 3, 7], true)) {
    $days = 3;
}

renderHeader('Marktwert-Radar', 'radar', $status);

if (!$leagueId) {
    renderEmptyHint('Kaderdaten');
    renderFooter();
    exit;
}

$response = $analyse->marketValueResponse($days);
$squad    = $analyse->squadOutlook($leagueId, $days);
$market   = $analyse->marketOutlook($leagueId, $days);

// Reicht die Datenbasis ueberhaupt?
$totalCases = 0;
foreach ($response as $class) {
    $totalCases += (int) $class['n'];
}

if ($totalCases < 50) {
    ?>
    <div class="card notice">
        <p class="warn">Die Datenbasis reicht noch nicht (<?= (int) $totalCases ?> Spiele).</p>
        <p class="muted">
            Der Zusammenhang zwischen Punkten und Marktwert wird aus den eigenen
            Daten gelernt. Dafür braucht es Spieltage mit Punkten <em>und</em>
            Marktwerten davor und danach – beides wächst mit jedem Sync.
        </p>
    </div>
    <?php
    renderFooter();
    exit;
}

// Summe der erwarteten Veraenderung im eigenen Kader.
$sumDelta = 0;
$unknown  = 0;
foreach ($squad as $row) {
    if ($row['mv_delta'] === null) {
        $unknown++;
        continue;
    }
    $sumDelta += $row['mv_delta'];
}

/** Eine Zeile der Aussichts-Tabelle. */
function outlookRow(array $row, $showPrice = false)
{
    $o = $row['outlook'];
    $f = $row['forecast'];
    ?>
    <tr>
        <td>
            <div class="player-cell">
                <span>
                    <span class="pos"><?= e(Kickbase::positionName($row['position'])) ?></span>
                    <a href="player.php?id=<?= e($row['player_id']) ?>"><?= e(playerName($row)) ?></a>
                    <?= statusBadge($row['status']) ?>
                    <?php if (!$o['confident'] && $o['pct'] !== null): ?>
                        <span class="badge" title="Wenige Vergleichsfälle oder unbekannte Einsatzquote">unsicher</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($row['team_name']): ?>
                <span class="muted score-detail"><?= e($row['team_name']) ?></span>
            <?php endif; ?>
        </td>
        <td class="num"><?= money($row['mv']) ?></td>
        <?php if ($showPrice): ?>
            <td class="num"><?= $row['price'] !== null ? money($row['price']) : '–' ?></td>
        <?php endif; ?>
        <td class="num"><?= $f['base'] !== null ? number_format($f['base'], 0, ',', '.') : '–' ?></td>
        <td class="num">
            <?= $o['play_chance'] !== null ? round($o['play_chance'] * 100) . '%' : '–' ?>
        </td>
        <td class="num <?= $o['pct'] === null ? '' : trendClass($o['pct']) ?>">
            <?= $o['pct'] === null ? '–' : sprintf('%+.1f %%', $o['pct']) ?>
        </td>
        <td class="num <?= $row['mv_delta'] === null ? '' : trendClass($row['mv_delta']) ?>">
            <?= $row['mv_delta'] === null ? '–' : moneyDelta($row['mv_delta']) ?>
        </td>
    </tr>
    <?php
}
?>

<form class="inline" method="get">
    <label class="muted" for="days">Zeitraum</label>
    <select name="days" id="days" onchange="this.form.submit()">
        <?php foreach ([1 => '1 Tag', 3 => '3 Tage', 7 => '7 Tage'] as $k => $label): ?>
            <option value="<?= $k ?>"<?= $days === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <noscript><button type="submit">Anzeigen</button></noscript>
</form>

<div class="stats">
    <div class="stat">
        <div class="label">Erwartete Kaderentwicklung</div>
        <div class="value <?= trendClass($sumDelta) ?>"><?= moneyDelta($sumDelta) ?></div>
        <div class="sub">
            in <?= $days ?> Tagen<?= $unknown ? ', ' . $unknown . ' ohne Prognose' : '' ?>
        </div>
    </div>
    <div class="stat">
        <div class="label">Grösster Verlust</div>
        <?php $worst = null; foreach ($squad as $r) { if ($r['mv_delta'] !== null && ($worst === null || $r['mv_delta'] < $worst['mv_delta'])) { $worst = $r; } } ?>
        <div class="value down"><?= $worst ? moneyDelta($worst['mv_delta']) : '–' ?></div>
        <div class="sub"><?= $worst ? e(playerName($worst)) : '' ?></div>
    </div>
    <div class="stat">
        <div class="label">Grösster Gewinn</div>
        <?php $best = null; foreach ($squad as $r) { if ($r['mv_delta'] !== null && ($best === null || $r['mv_delta'] > $best['mv_delta'])) { $best = $r; } } ?>
        <div class="value up"><?= $best ? moneyDelta($best['mv_delta']) : '–' ?></div>
        <div class="sub"><?= $best ? e(playerName($best)) : '' ?></div>
    </div>
    <div class="stat">
        <div class="label">Gelernt aus</div>
        <div class="value"><?= (int) $totalCases ?></div>
        <div class="sub">Spielen mit Marktwert davor und danach</div>
    </div>
</div>

<h2>Dein Kader</h2>
<p class="muted">
    Sortiert nach erwarteter Veränderung – oben, was voraussichtlich Geld
    kostet. Ein Spieler mit guter Basis, der aber absehbar nicht spielt, steht
    trotzdem oben: nicht zu spielen ist die teuerste Variante.
</p>
<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Spieler</th>
            <th class="num">Marktwert</th>
            <th class="num">Basis</th>
            <th class="num">Einsatzchance</th>
            <th class="num">erwartet</th>
            <th class="num">in Euro</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($squad as $row) { outlookRow($row); } ?>
        </tbody>
    </table>
</div>

<h2>Transfermarkt</h2>
<?php
$rising = [];
foreach ($market as $row) {
    if ($row['outlook']['pct'] !== null && $row['outlook']['pct'] > 0) {
        $rising[] = $row;
    }
}
$rising = array_reverse($rising);
?>
<p class="muted">
    Angebote, bei denen ein Anstieg wahrscheinlich ist – wer vor dem Spieltag
    kauft, kauft vor der Bewegung. <strong>Ohne</strong> Preisbewertung: was
    das Angebot kostet, steht auf der <a href="market.php">Transfermarkt-Seite</a>.
</p>
<?php if (!$rising): ?>
    <p class="muted">Derzeit kein Angebot mit erwartetem Anstieg.</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Spieler</th>
                <th class="num">Marktwert</th>
                <th class="num">Preis</th>
                <th class="num">Basis</th>
                <th class="num">Einsatzchance</th>
                <th class="num">erwartet</th>
                <th class="num">in Euro</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rising as $row) { outlookRow($row, true); } ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="card notice">
    <h2>Woher die Zahlen kommen</h2>
    <p class="muted">
        Gemessen an den eigenen Daten: für jedes gespielte Spiel der Marktwert
        am Spieltag und <?= $days ?> Tage später. Daraus ergibt sich, wie der
        Markt auf Punkte reagiert.
    </p>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Punkte im Spiel</th>
                <th class="num">Fälle</th>
                <th class="num">Marktwert danach</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($response as $class): ?>
                <tr>
                    <td>
                        <?= e($class['label']) ?>
                        <span class="muted score-detail">
                            <?= $class['to'] == 0 ? '0' :
                                ($class['to'] > 9000 ? $class['from'] . '+' : $class['from'] . '–' . $class['to']) ?>
                        </span>
                    </td>
                    <td class="num <?= $class['n'] < 20 ? 'warn' : '' ?>"><?= (int) $class['n'] ?></td>
                    <td class="num <?= $class['pct'] === null ? '' : trendClass($class['pct']) ?>">
                        <?= $class['pct'] === null ? '–' : sprintf('%+.2f %%', $class['pct']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">
        Die erwartete Veränderung mischt zwei Fälle, statt die Prognose stumpf
        in eine Klasse zu stecken: den Anteil <em>ohne</em> Einsatz mit der
        obersten Zeile, den Anteil <em>mit</em> Einsatz mit der Klasse, in die
        die Basis fällt. Ein Erwartungswert von 40 Punkten entsteht meist aus
        halber Einsatzchance mal 80 Punkten – die Wirklichkeit ist dann 0 oder
        80, nie 40.
    </p>
    <p class="muted">
        <strong>Grenzen:</strong> Der Zusammenhang ist ein Signal, kein Automat –
        einzelne Spieler weichen deutlich ab, und die lineare Korrelation ist
        mit etwa 0,17 schwach. Gelernt wird aus den Spielern, für die
        Spieltagsdaten vorliegen: eigener Kader und aktuelle Marktangebote.
        Klassen mit weniger als 20 Fällen sind gelb markiert, und wo die
        Einsatzquote fehlt, steht „unsicher“. Marktwert-Änderungen durch
        Nachfrage in deiner Liga sind hier nicht abgebildet.
    </p>
</div>

<?php renderFooter(); ?>
