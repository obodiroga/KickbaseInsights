<?php
/**
 * Taugt die Prognose etwas?
 *
 * Verglichen wird immer gegen die stumpfe Rechnung "nimm den Saisonschnitt".
 * Ist die Formel dort nicht besser, ist sie ihren Aufwand nicht wert - das
 * soll diese Seite zeigen und nicht verstecken.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse = new Analyse($db);
$status  = $analyse->status();
$acc     = $analyse->forecastAccuracy();

renderHeader('Prognose', 'accuracy', $status);

$n = (int) $acc['overall']['n'];

/** Fehler in Punkten, kompakt. */
function mae($value)
{
    return $value === null ? '–' : number_format((float) $value, 1, ',', '.');
}

/** Wieviel besser ist die Formel als der Saisonschnitt? */
function verdict($mae, $baseline)
{
    if ($mae === null || $baseline === null || (float) $baseline == 0.0) {
        return ['–', ''];
    }
    $diff = ((float) $baseline - (float) $mae) / (float) $baseline * 100;
    $cls  = $diff > 2 ? 'up' : ($diff < -2 ? 'down' : 'muted');
    return [sprintf('%+.0f %%', $diff), $cls];
}
?>

<?php if ($n === 0): ?>
    <div class="card notice">
        <p>Noch keine ausgewerteten Prognosen.</p>
        <p class="muted">
            Eine Prognose wird vor dem Anpfiff eingefroren und nach dem Spiel
            mit den tatsächlichen Punkten verglichen. Es sind derzeit
            <strong><?= (int) $acc['open'] ?></strong> Prognosen offen.
            <?php if ($acc['open'] > 0): ?>
                Die erste Auswertung gibt es nach dem nächsten Spieltag.
            <?php else: ?>
                Sobald ein Spieltag angesetzt ist, legt der nächste Sync
                Prognosen an.
            <?php endif; ?>
        </p>
        <p class="muted">
            Bis dahin ist über die Güte der Formel <em>nichts</em> bekannt –
            die Zahlen auf der Aufstellungsseite sind eine plausible Rechnung,
            aber keine geprüfte Vorhersage.
        </p>
    </div>
<?php else: ?>

    <?php list($diffText, $diffCls) = verdict($acc['overall']['mae'], $acc['overall']['mae_baseline']); ?>

    <div class="stats">
        <div class="stat">
            <div class="label">Mittlerer Fehler</div>
            <div class="value"><?= mae($acc['overall']['mae']) ?></div>
            <div class="sub">Punkte je Spieler und Spiel</div>
        </div>
        <div class="stat">
            <div class="label">Saisonschnitt als Vergleich</div>
            <div class="value"><?= mae($acc['overall']['mae_baseline']) ?></div>
            <div class="sub">dieselbe Kennzahl, stumpf gerechnet</div>
        </div>
        <div class="stat">
            <div class="label">Vorsprung der Formel</div>
            <div class="value <?= e($diffCls) ?>"><?= e($diffText) ?></div>
            <div class="sub">
                <?php if ($diffCls === 'up'): ?>
                    besser als der Saisonschnitt
                <?php elseif ($diffCls === 'down'): ?>
                    schlechter als der Saisonschnitt
                <?php else: ?>
                    kein nennenswerter Unterschied
                <?php endif; ?>
            </div>
        </div>
        <div class="stat">
            <div class="label">Datenbasis</div>
            <div class="value"><?= $n ?></div>
            <div class="sub"><?= (int) $acc['open'] ?> noch offen</div>
        </div>
    </div>

    <?php if ($n < 30): ?>
        <div class="card notice">
            <p class="muted">
                <?= $n ?> ausgewertete Prognosen sind noch zu wenig für ein
                Urteil. Ein einzelner Spieltag kann jede Richtung zeigen –
                belastbar wird das ab etwa drei Spieltagen.
            </p>
        </div>
    <?php endif; ?>

    <?php
    $bias = $acc['overall']['bias'] !== null ? (float) $acc['overall']['bias'] : null;
    if ($bias !== null && abs($bias) >= 3):
    ?>
        <div class="card notice">
            <p class="<?= $bias > 0 ? 'warn' : 'warn' ?>">
                Die Prognose liegt systematisch
                <?= $bias > 0 ? 'zu niedrig' : 'zu hoch' ?>:
                im Schnitt um <?= mae(abs($bias)) ?> Punkte.
            </p>
            <p class="muted">
                Tatsächlich <?= mae($acc['overall']['avg_actual']) ?> Punkte,
                vorhergesagt <?= mae($acc['overall']['avg_forecast']) ?>.
                Ein einseitiger Fehler lässt sich leichter beheben als ein
                zufälliger – meist steckt er in der Einsatzquote.
            </p>
        </div>
    <?php endif; ?>

    <h2>Nach Spieltag</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Spieltag</th>
                <th class="num">Prognosen</th>
                <th class="num">Fehler</th>
                <th class="num">Saisonschnitt</th>
                <th class="num">Vorsprung</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($acc['by_day'] as $row): ?>
                <?php list($t, $c) = verdict($row['mae'], $row['mae_baseline']); ?>
                <tr>
                    <td><?= e($row['day']) ?><span class="muted"> · Saison <?= e($row['season_id']) ?></span></td>
                    <td class="num"><?= (int) $row['n'] ?></td>
                    <td class="num"><?= mae($row['mae']) ?></td>
                    <td class="num"><?= mae($row['mae_baseline']) ?></td>
                    <td class="num <?= e($c) ?>"><?= e($t) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2>Nach Position</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Position</th>
                <th class="num">Prognosen</th>
                <th class="num">Fehler</th>
                <th class="num">Saisonschnitt</th>
                <th class="num">Vorsprung</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($acc['by_position'] as $row): ?>
                <?php list($t, $c) = verdict($row['mae'], $row['mae_baseline']); ?>
                <tr>
                    <td><?= e(Kickbase::positionName($row['position'])) ?></td>
                    <td class="num"><?= (int) $row['n'] ?></td>
                    <td class="num"><?= mae($row['mae']) ?></td>
                    <td class="num"><?= mae($row['mae_baseline']) ?></td>
                    <td class="num <?= e($c) ?>"><?= e($t) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2>Die größten Fehlschüsse</h2>
    <p class="muted">
        Hier steht, wo die Formel danebenlag – meist verrät die Einsatzquote,
        warum.
    </p>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Spieler</th>
                <th class="num">Spieltag</th>
                <th class="num">erwartet</th>
                <th class="num">tatsächlich</th>
                <th class="num">Minuten</th>
                <th class="num">Basis</th>
                <th class="num">Einsatzquote</th>
                <th class="num">Gegner</th>
                <th class="num">Fehler</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($acc['worst'] as $row): ?>
                <tr>
                    <td>
                        <span class="pos"><?= e(Kickbase::positionName($row['position'])) ?></span>
                        <a href="player.php?id=<?= e($row['player_id']) ?>"><?= e(playerName($row)) ?></a>
                    </td>
                    <td class="num"><?= e($row['day']) ?></td>
                    <td class="num"><?= mae($row['forecast_points']) ?></td>
                    <td class="num"><?= $row['actual_points'] !== null ? (int) $row['actual_points'] : '–' ?></td>
                    <td class="num"><?= $row['actual_minutes'] !== null ? (int) $row['actual_minutes'] : '–' ?></td>
                    <td class="num"><?= mae($row['base']) ?></td>
                    <td class="num"><?= $row['start_rate'] !== null ? round($row['start_rate'] * 100) . '%' : '–' ?></td>
                    <td class="num"><?= isset($row['opponent']) && $row['opponent'] !== null
                        ? '×' . number_format($row['opponent'], 2, ',', '.') : '–' ?></td>
                    <td class="num warn"><?= mae($row['fehler']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="card notice">
    <h2>Wie gemessen wird</h2>
    <p class="muted">
        Vor dem Anpfiff wird die Prognose eingefroren, nach dem Spiel kommen
        die tatsächlichen Punkte dazu. Kennzahl ist der <strong>mittlere
        absolute Fehler</strong>: um wie viele Punkte lag die Vorhersage im
        Schnitt daneben, egal in welche Richtung.
    </p>
    <p class="muted">
        Daneben steht immer dieselbe Kennzahl für die stumpfe Rechnung „nimm
        den Saisonschnitt". Das ist der Maßstab: eine Formel, die nicht besser
        ist als der Saisonschnitt, ist ihren Aufwand nicht wert. Kein Einsatz
        zählt als 0 Punkte – auch das ist ein Fehler, wenn Punkte erwartet
        wurden.
    </p>
    <p class="muted">
        Gemessen wird der eigene Kader, also rund ein Dutzend Prognosen je
        Spieltag. Für andere Spieler liegen keine Spieltagsdaten vor.
    </p>
</div>

<?php renderFooter(); ?>
