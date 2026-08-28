<?php
/**
 * Liga-Aktivitaeten: was die Mitspieler kaufen und verkaufen, zu welchem
 * Preis gemessen am Marktwert, und was Kickbase neu auf den Markt stellt.
 *
 * Alles kommt aus der Tabelle activities, die der Sync ohnehin fuellt.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse  = new Analyse($db);
$status   = $analyse->status();
$leagueId = currentLeagueId($config, $db);

$zeitraeume = [7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 3650 => 'alles'];
$days = isset($_GET['days']) && isset($zeitraeume[(int) $_GET['days']]) ? (int) $_GET['days'] : 30;

$feed     = $leagueId ? $analyse->activityFeed($leagueId, $days) : [];
$managers = $analyse->managerStats($feed);
$flips    = $analyse->quickFlips($feed);

// Kopfzahlen. Kaeufe und Verkaeufe bleiben getrennt - ein Weiterverkauf
// taucht sonst in einer gemeinsamen Summe doppelt auf.
$sum = ['buys' => 0, 'buy_volume' => 0, 'sells' => 0, 'sell_volume' => 0, 'listed' => 0];
$aufschlaege = [];
$aeltest = null;

foreach ($feed as $row) {
    if ($aeltest === null || $row['happened_at'] < $aeltest) {
        $aeltest = $row['happened_at'];
    }
    if ($row['kind'] === 'buy') {
        $sum['buys']++;
        $sum['buy_volume'] += (int) $row['price'];
        if ($row['delta_pct'] !== null) {
            $aufschlaege[] = $row['delta_pct'];
        }
    } elseif ($row['kind'] === 'sell') {
        $sum['sells']++;
        $sum['sell_volume'] += (int) $row['price'];
    } elseif ($row['kind'] === 'listed') {
        $sum['listed']++;
    }
}
$avgAufschlag = $aufschlaege ? array_sum($aufschlaege) / count($aufschlaege) : null;

$labels = [
    'buy'    => 'Kauf',
    'sell'   => 'Verkauf',
    'listed' => 'neu am Markt',
    'bonus'  => 'Bonus',
    'other'  => 'Sonstiges',
];

renderHeader('Liga', 'activity', $status);

if (!$leagueId) {
    renderEmptyHint('Ligadaten');
    renderFooter();
    exit;
}
?>

<form class="inline" method="get">
    <select name="days">
        <?php foreach ($zeitraeume as $k => $label): ?>
            <option value="<?= $k ?>"<?= $days === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Zeitraum</button>
</form>

<?php if (!$feed): ?>
    <div class="card notice">
        <p>Fuer diesen Zeitraum sind keine Aktivitaeten gespeichert.</p>
        <p class="muted">
            Der Feed kommt aus dem Sync – aktualisieren mit
            <code>php bin/sync.php --market</code>.
        </p>
    </div>
    <?php renderFooter(); exit; ?>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <div class="label">Kaeufe</div>
        <div class="value"><?= $sum['buys'] ?></div>
        <div class="sub"><?= money($sum['buy_volume']) ?> bewegt</div>
    </div>
    <div class="stat">
        <div class="label">Verkaeufe</div>
        <div class="value"><?= $sum['sells'] ?></div>
        <div class="sub"><?= money($sum['sell_volume']) ?> bewegt</div>
    </div>
    <div class="stat">
        <div class="label">Ø Aufschlag beim Kauf</div>
        <div class="value <?= trendClass($avgAufschlag) ?>"><?= pct($avgAufschlag) ?></div>
        <div class="sub">gegenueber dem Marktwert</div>
    </div>
    <div class="stat">
        <div class="label">Neu am Markt</div>
        <div class="value"><?= $sum['listed'] ?></div>
        <div class="sub">
            <?= $aeltest ? 'seit ' . e(date('d.m. H:i', strtotime($aeltest))) : '' ?>
        </div>
    </div>
</div>

<?php if ($managers): ?>
    <h2>Die Mitspieler</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Manager</th>
                <th class="num">Kaeufe</th>
                <th class="num">Kaufvolumen</th>
                <th class="num">Ø Aufschlag</th>
                <th class="num">Verkaeufe</th>
                <th class="num">Verkaufsvolumen</th>
                <th class="num">Ø beim Verkauf</th>
                <th class="num">Saldo</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($managers as $m): ?>
                <tr>
                    <td><strong><?= e($m['manager']) ?></strong></td>
                    <td class="num"><?= $m['buys'] ?></td>
                    <td class="num"><?= money($m['buy_volume']) ?></td>
                    <td class="num <?= trendClass($m['buy_premium']) ?>"><?= pct($m['buy_premium']) ?></td>
                    <td class="num"><?= $m['sells'] ?></td>
                    <td class="num"><?= money($m['sell_volume']) ?></td>
                    <td class="num <?= trendClass($m['sell_premium']) ?>"><?= pct($m['sell_premium']) ?></td>
                    <td class="num <?= trendClass($m['net']) ?>"><?= moneyDelta($m['net']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">
        <strong>Aufschlag</strong> ist der gezahlte Preis gegenueber dem Marktwert am selben Tag.
        Positiv beim Kauf heisst: teurer als der Marktwert. Positiv beim Verkauf heisst: teurer
        losgeworden als er wert war. Der <strong>Saldo</strong> ist reine Kassenrechnung
        (Verkaeufe minus Kaeufe) – was ein noch nicht verkaufter Spieler wert ist, steht da nicht drin.
    </p>
<?php endif; ?>

<?php if ($flips): ?>
    <h2>Gekauft und wieder verkauft</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Spieler</th>
                <th>Manager</th>
                <th class="num">Kauf</th>
                <th class="num">Verkauf</th>
                <th class="num">Ergebnis</th>
                <th class="num">Gehalten</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($flips as $f): ?>
                <tr>
                    <td>
                        <div class="player-cell">
                            <?php if ($f['image']): ?>
                                <img src="<?= e(kbImage($f['image'])) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                            <span>
                                <?php if ($f['position']): ?>
                                    <span class="pos"><?= e(Kickbase::positionName($f['position'])) ?></span>
                                <?php endif; ?>
                                <a href="player.php?id=<?= e($f['player_id']) ?>"><?= e(playerName($f)) ?></a>
                            </span>
                        </div>
                    </td>
                    <td><?= e($f['manager']) ?></td>
                    <td class="num"><?= money($f['buy_price']) ?></td>
                    <td class="num"><?= money($f['sell_price']) ?></td>
                    <td class="num <?= trendClass($f['profit']) ?>"><?= moneyDelta($f['profit']) ?></td>
                    <td class="num"><?= $f['hours'] < 48
                        ? number_format($f['hours'], 0, ',', '.') . ' h'
                        : number_format($f['hours'] / 24, 1, ',', '.') . ' Tage' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">
        Nur Paare, bei denen Kauf <em>und</em> Verkauf im gewaehlten Zeitraum liegen. Wer einen
        Spieler schon vorher besass, taucht hier nicht auf – der Einstandspreis ist dann unbekannt.
    </p>
<?php endif; ?>

<h2>Verlauf</h2>
<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Wann</th>
            <th>Was</th>
            <th>Spieler</th>
            <th>Manager</th>
            <th class="num">Preis</th>
            <th class="num">Marktwert</th>
            <th class="num">Differenz</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($feed as $row): ?>
            <tr>
                <td class="muted"><?= e(date('d.m. H:i', strtotime($row['happened_at']))) ?></td>
                <td><span class="badge"><?= e($labels[$row['kind']]) ?></span></td>
                <td>
                    <?php if ($row['player_id']): ?>
                        <div class="player-cell">
                            <?php if ($row['image']): ?>
                                <img src="<?= e(kbImage($row['image'])) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                            <span>
                                <?php if ($row['position']): ?>
                                    <span class="pos"><?= e(Kickbase::positionName($row['position'])) ?></span>
                                <?php endif; ?>
                                <a href="player.php?id=<?= e($row['player_id']) ?>"><?= e(playerName($row)) ?></a>
                                <?= statusBadge($row['status']) ?>
                            </span>
                        </div>
                    <?php elseif ($row['kind'] === 'bonus'): ?>
                        <span class="muted">
                            Tagesbonus<?= $row['matchday'] ? ', Spieltag ' . (int) $row['matchday'] : '' ?>
                        </span>
                    <?php else: ?>
                        <span class="muted">–</span>
                    <?php endif; ?>
                </td>
                <td><?= $row['manager'] ? e($row['manager']) : '<span class="muted">–</span>' ?></td>
                <td class="num">
                    <?= $row['kind'] === 'bonus' ? money($row['bonus']) : money($row['price']) ?>
                </td>
                <td class="num"><?= $row['kind'] === 'bonus' ? '–' : money($row['mv_at']) ?></td>
                <td class="num <?= trendClass($row['delta_pct']) ?>">
                    <?= $row['delta_pct'] !== null ? pct($row['delta_pct']) : '–' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2 style="margin-top:0">Woher das kommt</h2>
    <p class="muted">
        Kickbase liefert einen Aktivitaeten-Feed ohne Beschriftung – die Eintraege tragen nur
        eine Typ-Nummer. Was sie bedeuten, ist aus den eigenen Daten abgeleitet:
        <strong>Transfers</strong> bringen Preis, Spieler und Managernamen mit und unterscheiden
        Kauf von Verkauf. <strong>Neu am Markt</strong> heisst, dass Kickbase einen Spieler
        angeboten hat – nachgeprueft daran, dass jede dieser Meldungen anschliessend als Angebot
        mit Kickbase als Verkaeufer auftauchte, waehrend die Angebote von Mitspielern nie eine
        solche Meldung davor hatten.
    </p>
    <p class="muted">
        Der Feed reicht nur so weit zurueck, wie synchronisiert wurde: Kickbase gibt die letzten
        Eintraege heraus, aeltere sind nur da, wenn damals schon ein Sync lief. Je haeufiger
        <code>--market</code> laeuft, desto lueckenloser wird diese Seite.
    </p>
</div>

<?php renderFooter(); ?>
