<?php
/**
 * Konkurrenzanalyse: Kader und Kennzahlen der Mitspieler, und die Frage,
 * die daraus wirklich folgt - welche guten Spieler gehoeren noch niemandem.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse  = new Analyse($db);
$status   = $analyse->status();
$leagueId = currentLeagueId($config, $db);

$managers = $leagueId ? $analyse->managers($leagueId) : [];
$frei     = $leagueId ? $analyse->freeAgents($leagueId, 25) : [];
$spread   = $leagueId ? $analyse->managerTeamSpread($leagueId) : [];

$shorts = json_decode((string) $db->getMeta('team_shorts'), true);
$shorts = is_array($shorts) ? $shorts : [];

// Kader eines Managers aufklappen.
$offen = isset($_GET['m']) ? (string) $_GET['m'] : null;
$kader = ($offen && $leagueId) ? $analyse->managerSquad($leagueId, $offen) : [];

renderHeader('Konkurrenz', 'managers', $status);

if (!$leagueId || !$managers) {
    ?>
    <div class="card notice">
        <p>Es sind noch keine Daten zu den Mitspielern gespeichert.</p>
        <p class="muted">
            Sie kommen beim Standardlauf mit: <code>php bin/sync.php</code> –
            oben rechts <strong>Standard</strong> auswaehlen und aktualisieren.
        </p>
    </div>
    <?php
    renderFooter();
    exit;
}

$gesamt = 0;
foreach ($managers as $m) {
    $gesamt += $m['team_value'];
}
?>

<div class="stats">
    <div class="stat">
        <div class="label">Mitspieler</div>
        <div class="value"><?= count($managers) ?></div>
        <div class="sub">in dieser Liga</div>
    </div>
    <div class="stat">
        <div class="label">Teamwert gesamt</div>
        <div class="value"><?= money($gesamt) ?></div>
        <div class="sub">Ø <?= money($gesamt / count($managers)) ?></div>
    </div>
    <div class="stat">
        <div class="label">Gebundene Spieler</div>
        <div class="value"><?= (int) $db->value(
            'SELECT COUNT(*) FROM manager_players WHERE league_id = ?', [$leagueId]) ?></div>
        <div class="sub">von <?= (int) $db->value('SELECT COUNT(*) FROM players') ?> erfassten</div>
    </div>
    <div class="stat">
        <div class="label">Aktuell angeboten</div>
        <div class="value"><?= (int) $db->value(
            'SELECT COUNT(*) FROM manager_players WHERE league_id = ? AND on_market = 1', [$leagueId]) ?></div>
        <div class="sub">aus fremden Kadern</div>
    </div>
</div>

<h2>Die Liga</h2>
<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th class="num">Platz</th>
            <th>Manager</th>
            <th class="num">Punkte</th>
            <th class="num">Teamwert</th>
            <th class="num">vs. Ø</th>
            <th class="num">Heute</th>
            <th class="num">Kader</th>
            <th class="num">am Markt</th>
            <th>Schwerpunkt</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($managers as $m): ?>
            <tr<?= $m['is_me'] ? ' class="own"' : '' ?>>
                <td class="num"><?= $m['place'] !== null ? (int) $m['place'] : '–' ?></td>
                <td>
                    <a href="?m=<?= e($m['manager_id']) ?>"><strong><?= e($m['name']) ?></strong></a>
                    <?php if ($m['is_me']): ?><span class="badge">du</span><?php endif; ?>
                </td>
                <td class="num"><?= $m['points'] !== null ? number_format($m['points'], 0, ',', '.') : '–' ?></td>
                <td class="num"><?= money($m['team_value']) ?></td>
                <td class="num <?= trendClass($m['vs_average']) ?>"><?= pct($m['vs_average'], 0) ?></td>
                <td class="num <?= trendClass($m['day_change']) ?>"><?= moneyDelta($m['day_change']) ?></td>
                <td class="num"><?= (int) $m['squad_size'] ?></td>
                <td class="num"><?= (int) $m['on_market'] ?: '–' ?></td>
                <td class="muted">
                    <?php
                    $top = isset($spread[$m['manager_id']]) ? array_slice($spread[$m['manager_id']], 0, 2) : [];
                    $teile = [];
                    foreach ($top as $t) {
                        $teile[] = e(isset($shorts[$t['team_id']]) ? $shorts[$t['team_id']] : $t['team_id'])
                            . ' ' . $t['n'] . '×';
                    }
                    echo $teile ? implode(', ', $teile) : '–';
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="muted">
    <strong>vs. Ø</strong> ist der Abstand zum durchschnittlichen Teamwert der Liga.
    Der <strong>Schwerpunkt</strong> nennt die beiden Vereine, aus denen der Manager den
    meisten Kaderwert hält – wer dort klumpt, hängt am Spielplan dieses Vereins.
    Was jemand an <strong>Budget</strong> auf dem Konto hat, steht hier nicht: die API gibt
    das nur für den eigenen Account heraus, alles andere wäre geschätzt.
</p>

<?php if ($kader): $wer = null; foreach ($managers as $m) { if ($m['manager_id'] === $offen) { $wer = $m; } } ?>
    <h2>Kader von <?= e($wer ? $wer['name'] : $offen) ?></h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Spieler</th>
                <th>Verein</th>
                <th class="num">Marktwert</th>
                <th class="num">Heute</th>
                <th class="num">Gewinn</th>
                <th class="num">Ø Pkt</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($kader as $row): ?>
                <tr>
                    <td>
                        <div class="player-cell">
                            <?php if ($row['image']): ?>
                                <img src="<?= e(kbImage($row['image'])) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                            <span>
                                <span class="pos"><?= e(Kickbase::positionName($row['position'])) ?></span>
                                <a href="player.php?id=<?= e($row['player_id']) ?>"><?= e(playerName($row)) ?></a>
                            </span>
                        </div>
                    </td>
                    <td class="muted">
                        <?= e(isset($shorts[$row['team_id']]) ? $shorts[$row['team_id']] : $row['team_id']) ?>
                    </td>
                    <td class="num"><?= money($row['market_value']) ?></td>
                    <td class="num <?= trendClass($row['day_change']) ?>"><?= moneyDelta($row['day_change']) ?></td>
                    <td class="num <?= trendClass($row['mv_gain']) ?>"><?= moneyDelta($row['mv_gain']) ?></td>
                    <td class="num"><?= $row['avg_points'] !== null ? (int) $row['avg_points'] : '–' ?></td>
                    <td>
                        <?= statusBadge($row['status']) ?>
                        <?php if ($row['on_market']): ?>
                            <span class="badge">am Markt</span>
                        <?php endif; ?>
                        <?php if ($row['lineup_slot'] !== null): ?>
                            <span class="muted">aufgestellt</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2>Noch frei</h2>
<?php if (!$frei): ?>
    <div class="card notice">
        <p class="muted">
            Keine freien Spieler mit bekannter Einsatzzahl gefunden. Die Saison-Aggregate
            kommen nach und nach über <code>php bin/sync.php --agg-only</code>.
        </p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Spieler</th>
                <th>Verein</th>
                <th class="num">Marktwert</th>
                <th class="num">Ø Pkt</th>
                <th class="num">Einsätze</th>
                <th class="num">Pkt/Mio</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($frei as $row): ?>
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
                    <td class="muted">
                        <?= e(isset($shorts[$row['team_id']]) ? $shorts[$row['team_id']] : $row['team_id']) ?>
                    </td>
                    <td class="num"><?= money($row['market_value']) ?></td>
                    <td class="num"><?= (int) $row['avg_points'] ?></td>
                    <td class="num"><?= (int) $row['matches'] ?></td>
                    <td class="num"><?= $row['ppm'] !== null ? number_format($row['ppm'], 1, ',', '.') : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">
        Ein Spieler gehört in einer Liga höchstens einem Manager. Was hier steht, ist also
        tatsächlich zu haben, sobald es auf dem Transfermarkt erscheint. Spieler ohne bekannte
        Einsatzzahl fallen heraus, sonst stehen Eintagsfliegen oben – dieselbe Regel wie in der
        Effizienz-Rangliste.
    </p>
<?php endif; ?>

<?php renderFooter(); ?>
