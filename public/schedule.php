<?php
/**
 * Spielplan mit Siegchancen. Die Quoten kommen aus dem Spielplan-Endpunkt
 * und stehen dort nur fuer die naechsten beiden Spieltage - gespeichert
 * bleiben sie trotzdem.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse = new Analyse($db);
$status  = $analyse->status();

// Ein leeres oder unsinniges day ergaebe (int) 0 und damit einen leeren
// Spieltag - dann lieber gar keine Vorgabe und der naechste Spieltag.
$day = isset($_GET['day']) && ctype_digit((string) $_GET['day']) && (int) $_GET['day'] > 0
    ? (int) $_GET['day'] : null;

$plan    = $analyse->matchdaySchedule($day, $config['kickbase']['competition_id']);
$factors = $analyse->outcomeFactors();
$mix     = $analyse->teamOutcomeMix();

$shortsAll = json_decode((string) $db->getMeta('team_shorts'), true);
$shortsAll = is_array($shortsAll) ? $shortsAll : [];

// Nach gewohntem Niveau sortieren, damit die Liste etwas aussagt.
uasort($mix, function ($a, $b) {
    return $a['factor'] < $b['factor'] ? 1 : ($a['factor'] > $b['factor'] ? -1 : 0);
});

renderHeader('Spielplan', 'schedule', $status);

if (!$plan['days']) {
    ?>
    <div class="card notice">
        <p>Es ist noch kein Spielplan gespeichert.</p>
        <p class="muted">
            Kommt beim naechsten Abgleich mit – zum Beispiel
            <code>php bin/sync.php --market</code>.
        </p>
    </div>
    <?php
    renderFooter();
    exit;
}

/** Balken fuer die drei Ausgaenge. */
function chanceBar(array $probs)
{
    $teile = [
        ['good', $probs['home'], 'Heimsieg'],
        ['mid',  $probs['draw'], 'Unentschieden'],
        ['low',  $probs['away'], 'Auswaertssieg'],
    ];
    $out = '<div class="scorebar chance">';
    foreach ($teile as $t) {
        $out .= sprintf('<div class="fill %s" style="width:%.1f%%" title="%s: %.0f %%"></div>',
            $t[0], $t[1] * 100, e($t[2]), $t[1] * 100);
    }
    return $out . '</div>';
}
?>

<form class="inline" method="get">
    <select name="day">
        <?php foreach ($plan['days'] as $d): ?>
            <option value="<?= $d ?>"<?= $d === $plan['day'] ? ' selected' : '' ?>>
                Spieltag <?= $d ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Anzeigen</button>
</form>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Anstoss</th>
            <th>Begegnung</th>
            <th>Chancen</th>
            <th class="num">Heim</th>
            <th class="num">X</th>
            <th class="num">Ausw</th>
            <th class="num">Faktor Heim</th>
            <th class="num">Faktor Ausw</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($plan['matches'] as $m): ?>
            <tr>
                <td class="muted">
                    <?= $m['kickoff'] ? e(date('d.m. H:i', strtotime($m['kickoff']))) : '–' ?>
                </td>
                <td>
                    <strong><?= e($m['home_name']) ?></strong>
                    <span class="muted"> – </span>
                    <strong><?= e($m['away_name']) ?></strong>
                    <?php if ($m['goals_home'] !== null && $m['goals_away'] !== null): ?>
                        <span class="badge"><?= (int) $m['goals_home'] ?>:<?= (int) $m['goals_away'] ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $m['probs'] ? chanceBar($m['probs']) : '<span class="muted">keine Quote</span>' ?></td>
                <td class="num"><?= $m['probs'] ? number_format($m['probs']['home'] * 100, 0) . ' %' : '–' ?></td>
                <td class="num"><?= $m['probs'] ? number_format($m['probs']['draw'] * 100, 0) . ' %' : '–' ?></td>
                <td class="num"><?= $m['probs'] ? number_format($m['probs']['away'] * 100, 0) . ' %' : '–' ?></td>
                <td class="num <?= $m['factor_home'] !== null ? trendClass($m['factor_home'] - 1) : '' ?>">
                    <?= $m['factor_home'] !== null ? '×' . number_format($m['factor_home'], 2, ',', '.') : '–' ?>
                </td>
                <td class="num <?= $m['factor_away'] !== null ? trendClass($m['factor_away'] - 1) : '' ?>">
                    <?= $m['factor_away'] !== null ? '×' . number_format($m['factor_away'], 2, ',', '.') : '–' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2 style="margin-top:0">Was der Faktor bedeutet</h2>
    <?php if ($factors): ?>
        <p class="muted">
            Kickbase-Punkte haengen stark am Spielausgang. Das ist keine Annahme, sondern an
            <strong><?= number_format($factors['n'], 0, ',', '.') ?> Einsaetzen</strong> aus den
            eigenen Spieltagsdaten gemessen:
        </p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Ausgang</th><th class="num">Einsaetze</th><th class="num">Ø Punkte</th><th class="num">Faktor</th></tr>
                </thead>
                <tbody>
                <?php foreach ([['win', 'Sieg'], ['draw', 'Unentschieden'], ['loss', 'Niederlage']] as $k): ?>
                    <tr>
                        <td><?= e($k[1]) ?></td>
                        <td class="num"><?= (int) $factors['counts'][$k[0]] ?></td>
                        <td class="num"><?= number_format($factors['means'][$k[0]], 1, ',', '.') ?></td>
                        <td class="num <?= trendClass($factors[$k[0]] - 1) ?>">
                            ×<?= number_format($factors[$k[0]], 2, ',', '.') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">
            Die Spalten <strong>Faktor</strong> zeigen den Erwartungswert daraus, gewichtet mit den
            Siegchancen der jeweiligen Mannschaft. <strong>×1,00</strong> ist ein
            durchschnittliches Spiel der Liga, darueber ein guenstiges.
        </p>
        <p class="muted">
            <strong>In die Prognose geht dieser Wert nicht direkt ein.</strong> Der Punkteschnitt
            eines Spielers enthaelt die Staerke seines Teams naemlich schon – wer bei einem
            Spitzenteam spielt, hat eine hohe Basis, weil sein Team oft gewinnt. Wuerde man den
            Spielfaktor obendrauf multiplizieren, zaehlte dieselbe Teamstaerke zweimal. Die
            Aufstellungsseite rechnet deshalb mit dem <strong>Verhaeltnis zum gewohnten
            Niveau</strong> des Teams: Bayern gegen einen Spitzengegner landet unter 1,00, obwohl
            die Siegchance absolut gut ist – fuer Bayern ist es eben ein schweres Spiel.
        </p>
        <?php if ($mix): ?>
            <p class="muted">
                Gewohntes Niveau je Team, aus den bereits gespielten Partien:
                <?php $teile = [];
                foreach ($mix as $tid => $m) {
                    $teile[] = e(isset($shortsAll[$tid]) ? $shortsAll[$tid] : $tid)
                        . ' ×' . number_format($m['factor'], 2, ',', '.');
                }
                echo implode(' &middot; ', $teile); ?>.
                Teams mit zu wenigen erfassten Spielen fehlen hier – fuer sie entfaellt der
                Faktor, statt ihn aus Rauschen zu bilden.
            </p>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted">
            Fuer den Faktor fehlen noch Spieltagsdaten. Er entsteht, sobald genug beendete
            Spiele mit Ergebnis gespeichert sind.
        </p>
    <?php endif; ?>
    <p class="muted">
        Die Chancen stammen aus den Wettquoten, die Kickbase im Spielplan mitliefert – bereinigt
        um die Marge des Buchmachers, sonst ergaeben sie zusammen ueber 100 Prozent. Quoten gibt
        es nur fuer die naechsten beiden Spieltage. Gespeicherte werden nie mit einer Leermeldung
        ueberschrieben – verschwindet die Quote bei Kickbase, bleibt der zuletzt gesehene Stand
        erhalten, damit sich hinterher pruefen laesst, was vor dem Spiel erwartet wurde.
    </p>
</div>

<?php renderFooter(); ?>
