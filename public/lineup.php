<?php
/**
 * Aufstellung planen und die erwarteten Punkte sehen.
 *
 * Die Planung ist rein lokal - sie wird nicht an Kickbase geschickt. Zum
 * Vergleich steht daneben, wie deine Elf dort gerade aufgestellt ist.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_layout.php';

$analyse  = new Analyse($db);
$status   = $analyse->status();
$leagueId = currentLeagueId($config, $db);

renderHeader('Aufstellung', 'lineup', $status);

if (!$leagueId) {
    renderEmptyHint('Kaderdaten');
    renderFooter();
    exit;
}

$data    = $analyse->lineup($leagueId);
$players = $data['players'];

if (!$players) {
    renderEmptyHint('Kaderdaten');
    renderFooter();
    exit;
}

// Formation: Wunsch aus der URL, sonst die gespeicherte bzw. abgeleitete.
$formations = $analyse->formations();
$formation  = isset($_GET['formation']) && isset($formations[$_GET['formation']])
    ? $_GET['formation']
    : $analyse->lineupFormation($players);

$slotPos = $analyse->formationSlots($formation);
$next    = $analyse->nextMatches();
$shorts  = json_decode((string) $db->getMeta('team_shorts'), true);
$shorts  = is_array($shorts) ? $shorts : [];

// Spieler auf Slots verteilen. Wer nicht passt - falsche Position, Slot gibt
// es in dieser Formation nicht, Slot schon belegt - landet auf der Bank.
$bySlot = [];
$bench  = [];
foreach ($players as $player) {
    $slot = $player['slot'];
    $fits = $slot !== null
        && isset($slotPos[$slot])
        && !isset($bySlot[$slot])
        && $slotPos[$slot] === (int) $player['position'];

    if ($fits) {
        $bySlot[$slot] = $player;
    } else {
        $bench[] = $player;
    }
}

// Reihen von vorne nach hinten aufbauen.
$rows = [Kickbase::POS_ST => [], Kickbase::POS_MF => [], Kickbase::POS_ABW => [], Kickbase::POS_TW => []];
foreach ($slotPos as $slot => $pos) {
    $rows[$pos][] = $slot;
}

$expected = 0.0;
$unknown  = 0;
foreach ($bySlot as $player) {
    if ($player['forecast']['points'] === null) {
        $unknown++;
        continue;
    }
    $expected += $player['forecast']['points'];
}

/** Eine Spielerkarte. Ausserhalb des Feldes (Bank) ohne Slot. */
function lineupCard(array $player, array $next, array $shorts)
{
    $fc = $player['forecast'];
    ?>
    <div class="lu-card" draggable="true"
         data-player="<?= e($player['player_id']) ?>"
         data-position="<?= (int) $player['position'] ?>"
         data-points="<?= $fc['points'] !== null ? e(round($fc['points'], 1)) : '' ?>">
        <div class="lu-head">
            <span class="pos"><?= e(Kickbase::positionName($player['position'])) ?></span>
            <span class="lu-name"><?= e(playerName($player)) ?></span>
            <?= statusBadge($player['status']) ?>
        </div>
        <div class="lu-points">
            <?php if ($fc['points'] !== null): ?>
                <strong><?= e(number_format($fc['points'], 0, ',', '.')) ?></strong>
                <span class="muted">Pkt erwartet</span>
            <?php else: ?>
                <span class="muted">keine Prognose</span>
            <?php endif; ?>
        </div>
        <div class="lu-detail muted">
            <?php if ($fc['base'] !== null): ?>
                Basis <?= e(number_format($fc['base'], 0, ',', '.')) ?>
                <?php if ($fc['start_rate'] !== null): ?>
                    &middot; Einsatz <?= e(round($fc['start_rate'] * 100)) ?>%
                <?php endif; ?>
                <?php if ($fc['appearances']): ?>
                    &middot; <?= (int) $fc['appearances'] ?> Sp.
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($fc['note']): ?>
                <span class="warn"><?= e($fc['note']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($fc['competition'] !== null && $fc['competition'] !== 'Bundesliga'): ?>
            <div class="lu-detail warn" title="Punkte aus einem anderen Wettbewerb sind nicht direkt vergleichbar">
                Werte aus <?= e($fc['competition']) ?>
            </div>
        <?php endif; ?>
        <?php if (isset($next[$player['player_id']])): $m = $next[$player['player_id']]; ?>
            <div class="lu-next muted">
                <?= $m['home'] ? 'H' : 'A' ?> gegen
                <?= e(isset($shorts[$m['opponent']]) ? $shorts[$m['opponent']] : $m['opponent']) ?>
                &middot; <?= e(date('d.m.', strtotime($m['date']))) ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
?>

<div class="lu-bar card" data-token="<?= e(WebSync::token($db)) ?>"
     data-formation="<?= e($formation) ?>">
    <div>
        <label class="muted" for="lu-formation">Formation</label>
        <select id="lu-formation">
            <?php foreach (array_keys($formations) as $key): ?>
                <option value="<?= e($key) ?>"<?= $key === $formation ? ' selected' : '' ?>>
                    <?= e($key) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="lu-sum">
        <div class="label muted">Erwartete Punkte der Elf</div>
        <div class="value"><?= e(number_format($expected, 0, ',', '.')) ?></div>
        <?php // Immer rendern, auch leer - app.js schreibt hier beim Umstellen rein. ?>
        <div class="sub<?= ($unknown || count($bySlot) < 11) ? ' warn' : '' ?>">
            <?php if ($unknown): ?>
                <?= (int) $unknown ?> ohne Prognose
            <?php elseif (count($bySlot) < 11): ?>
                nur <?= count($bySlot) ?> von 11 besetzt
            <?php endif; ?>
        </div>
    </div>

    <div class="lu-actions">
        <button type="button" class="btn" id="lu-save">Planung speichern</button>
        <button type="button" class="btn ghost" id="lu-reset">Kickbase-Aufstellung</button>
        <span class="lu-state muted" role="status"></span>
    </div>
</div>

<p class="muted">
    Klick auf einen Spieler, dann auf einen freien Platz oder einen anderen
    Spieler - das tauscht die beiden. Es gehen nur Plaetze, die zur Position
    passen. Die Planung bleibt lokal und wird <strong>nicht</strong> an
    Kickbase geschickt.
</p>

<div class="lu-pitch">
    <?php foreach ($rows as $pos => $slots): ?>
        <?php if (!$slots) { continue; } ?>
        <div class="lu-row" data-position="<?= (int) $pos ?>">
            <?php foreach ($slots as $slot): ?>
                <div class="lu-slot<?= isset($bySlot[$slot]) ? '' : ' empty' ?>"
                     data-slot="<?= (int) $slot ?>" data-position="<?= (int) $pos ?>">
                    <?php if (isset($bySlot[$slot])): ?>
                        <?php lineupCard($bySlot[$slot], $next, $shorts); ?>
                    <?php else: ?>
                        <span class="lu-empty muted"><?= e(Kickbase::positionName($pos)) ?> frei</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>

<h2>Bank</h2>
<div class="lu-bench" data-position="0">
    <?php if (!$bench): ?>
        <p class="muted">Niemand auf der Bank.</p>
    <?php endif; ?>
    <?php foreach ($bench as $player): ?>
        <?php lineupCard($player, $next, $shorts); ?>
    <?php endforeach; ?>
</div>

<div class="card notice">
    <h2>Wie die Prognose entsteht</h2>
    <p class="muted">
        Erwartete Punkte = <strong>Basis</strong> &times; <strong>Einsatzquote</strong>
        &times; <strong>Verfuegbarkeit</strong>.
        Die Basis sind die Punkte je Einsatz, zu 60 % aus den letzten fuenf
        Einsaetzen und zu 40 % aus allen gespeicherten. Die Einsatzquote kommt
        aus den letzten zehn Spieltagen: ein Spiel ueber 60 Minuten zaehlt voll,
        ein Kurzeinsatz mit 0,4 - ein Joker punktet weniger als ein
        Stammspieler, aber nicht nichts.
        Angeschlagene Spieler gehen mit 50 % ein, Aufbautraining mit 25 %,
        verletzte und gesperrte mit 0 %.
    </p>
    <p class="muted">
        Nicht enthalten: Gegnerstaerke, Heimvorteil, Wechselgeruechte, und die
        voraussichtliche Aufstellung des Vereins. Fuer Spieler ohne Historie
        gibt es keine Prognose - da steht bewusst nichts statt einer erfundenen
        Zahl. Und wo die Werte aus der 2. Bundesliga stammen, steht das auf der
        Karte: die Punkte sind dann nicht direkt vergleichbar.
    </p>
    <?php
    $perfSync = $db->getMeta('performances_synced_at');
    $seasons  = [];
    foreach ($players as $player) {
        $fc = $player['forecast'];
        if ($fc['season_name'] !== null) {
            $seasons[$fc['competition'] . ' ' . $fc['season_name']] = true;
        }
    }
    ?>
    <p class="muted">
        <?php if ($perfSync): ?>
            Leistungsdaten zuletzt geholt:
            <?= e(date('d.m.Y H:i', strtotime($perfSync))) ?>.
        <?php else: ?>
            <span class="warn">Es sind noch keine Spieltagsdaten geholt worden.</span>
            Fuehre <code>php bin/sync.php --perf</code> aus oder starte oben
            einen Standardlauf.
        <?php endif; ?>
        <?php if ($seasons): ?>
            Grundlage: <?= e(implode(', ', array_keys($seasons))) ?>.
        <?php endif; ?>
    </p>
</div>

<?php renderFooter(); ?>
