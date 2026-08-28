<?php
/**
 * Auswertungen auf den lokal gespeicherten Daten.
 *
 * Bewusst nachvollziehbar gehalten: jede Empfehlung laesst sich auf die
 * drei Bausteine Preisabschlag, Punkte-pro-Million und Marktwert-Trend
 * zurueckfuehren. Keine Blackbox.
 */
class Analyse
{
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------- Marktwerte

    /**
     * Marktwert-Verlauf eines Spielers.
     * @return array Liste aus ['day' => 'Y-m-d', 'market_value' => int]
     */
    public function marketValueHistory($playerId, $days = 365)
    {
        return $this->db->all(
            'SELECT day, market_value FROM player_market_values
             WHERE player_id = ? AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             ORDER BY day ASC',
            [$playerId, (int) $days]
        );
    }

    /**
     * Marktwert-Veraenderung ueber einen Zeitraum.
     * @return array|null ['from', 'to', 'abs', 'pct']
     */
    public function trend($playerId, $days = 7)
    {
        $row = $this->db->one(
            'SELECT
                 (SELECT market_value FROM player_market_values
                  WHERE player_id = ? AND day <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  ORDER BY day DESC LIMIT 1) AS mv_from,
                 (SELECT market_value FROM player_market_values
                  WHERE player_id = ? ORDER BY day DESC LIMIT 1) AS mv_to',
            [$playerId, (int) $days, $playerId]
        );

        if (!$row || $row['mv_from'] === null || $row['mv_to'] === null || (int) $row['mv_from'] === 0) {
            return null;
        }

        $from = (int) $row['mv_from'];
        $to   = (int) $row['mv_to'];
        return [
            'from' => $from,
            'to'   => $to,
            'abs'  => $to - $from,
            'pct'  => ($to - $from) / $from * 100,
        ];
    }

    /**
     * Groesste Gewinner bzw. Verlierer der letzten Tage.
     * @param string $direction 'up' oder 'down'
     */
    public function movers($days = 7, $direction = 'up', $limit = 15, $minValue = 500000)
    {
        $order = $direction === 'up' ? 'DESC' : 'ASC';

        return $this->db->all(
            "SELECT p.player_id, p.known_name, p.last_name, p.first_name, p.team_id, p.position,
                    p.status, p.market_value, p.avg_points, p.total_points, p.image,
                    old.market_value AS mv_old,
                    (p.market_value - old.market_value) AS mv_change,
                    ((p.market_value - old.market_value) / old.market_value * 100) AS mv_change_pct
             FROM players p
             JOIN (
                 SELECT pmv.player_id, pmv.market_value
                 FROM player_market_values pmv
                 JOIN (
                     SELECT player_id, MAX(day) AS day
                     FROM player_market_values
                     WHERE day <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                     GROUP BY player_id
                 ) latest ON latest.player_id = pmv.player_id AND latest.day = pmv.day
             ) old ON old.player_id = p.player_id
             WHERE p.market_value IS NOT NULL
               AND old.market_value > ?
             ORDER BY mv_change {$order}
             LIMIT " . (int) $limit,
            [(int) $days, (int) $minValue]
        );
    }

    // ------------------------------------------------------- Preis-Leistung

    /**
     * Punkte pro Million Marktwert - der klassische Effizienz-Indikator.
     *
     * Der Mindest-Einsatz-Filter ist streng: Spieler ohne bekannte Einsatzzahl
     * fallen heraus. Frueher liess ein "matches IS NULL OR ..." praktisch jeden
     * durch, und weil in der Spalte Minuten statt Einsaetzen standen, konnten
     * Eintagsfliegen die Rangliste anfuehren. Lieber eine kurze, verlaessliche
     * Liste - fuellen laesst sie sich mit `sync.php --agg-only`.
     *
     * Zur Schwelle 8: Punkte pro Million belohnt billige Spieler mit wenigen
     * Einsaetzen doppelt - hoher Schnitt aus einem guten Spiel, niedriger
     * Marktwert. Bei 3 stand ein Spieler mit drei Einsaetzen auf Platz 4, bei
     * 8 bleiben Spieler mit sieben Einsaetzen drin, deren Schnitt schon etwas
     * bedeutet. Werte dazwischen sind Geschmackssache, 3 war schlicht zu wenig.
     *
     * Achtung Saisonwechsel: matches zaehlt die laufende Saison. Nach dem
     * ersten Spieltag hat niemand acht Einsaetze, die Liste ist dann
     * voruebergehend leer - ratedCount() liefert die Zahl fuer den Hinweis.
     */
    public function valueRanking($position = null, $limit = 25, $minMatches = 8)
    {
        $where  = ['p.market_value > 0', 'p.avg_points IS NOT NULL'];
        $params = [];

        if ($position !== null && $position !== '') {
            $where[]  = 'p.position = ?';
            $params[] = (int) $position;
        }
        if ($minMatches > 0) {
            $where[]  = 'p.matches >= ?';
            $params[] = (int) $minMatches;
        }

        return $this->db->all(
            'SELECT p.*, (p.avg_points / (p.market_value / 1000000)) AS points_per_million
             FROM players p
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY points_per_million DESC
             LIMIT ' . (int) $limit,
            $params
        );
    }

    /**
     * Wie viele Spieler erfuellen die Einsatz-Schwelle - und wie viele haetten
     * ueberhaupt einen Punkteschnitt? Damit kann die Seite erklaeren, warum die
     * Rangliste kurz ist, statt sie stumm kurz zu halten.
     *
     * @return array ['rated' => int, 'with_points' => int, 'min_matches' => int]
     */
    public function ratedCount($position = null, $minMatches = 8)
    {
        $params = [];
        $posSql = '';
        if ($position !== null && $position !== '') {
            $posSql   = ' AND position = ?';
            $params[] = (int) $position;
        }

        $withPoints = (int) $this->db->value(
            'SELECT COUNT(*) FROM players
             WHERE market_value > 0 AND avg_points IS NOT NULL' . $posSql,
            $params
        );

        $rated = (int) $this->db->value(
            'SELECT COUNT(*) FROM players
             WHERE market_value > 0 AND avg_points IS NOT NULL
               AND matches >= ?' . $posSql,
            array_merge([(int) $minMatches], $params)
        );

        return ['rated' => $rated, 'with_points' => $withPoints, 'min_matches' => (int) $minMatches];
    }

    // ------------------------------------------------------------- Transfermarkt

    /**
     * Offene Angebote inklusive Bewertung.
     */
    public function openListings($leagueId)
    {
        $rows = $this->db->all(
            'SELECT l.*, p.known_name, p.first_name, p.last_name, p.team_id, p.position,
                    p.status, p.avg_points, p.total_points, p.matches, p.image,
                    p.market_value AS current_mv
             FROM market_listings l
             LEFT JOIN players p ON p.player_id = l.player_id
             WHERE l.league_id = ? AND l.gone_at IS NULL
             ORDER BY l.expires_at ASC',
            [$leagueId]
        );

        foreach ($rows as &$row) {
            $mv    = $row['current_mv'] !== null ? (int) $row['current_mv'] : (int) $row['market_value'];
            $price = $row['price'] !== null ? (int) $row['price'] : null;

            $row['mv']       = $mv;
            $row['discount'] = ($price !== null && $mv > 0) ? ($mv - $price) / $mv * 100 : null;
            $row['ppm']      = ($mv > 0 && $row['avg_points'] !== null)
                ? $row['avg_points'] / ($mv / 1000000) : null;
            $row['trend7']   = $this->trend($row['player_id'], 7);
            $row['trend30']  = $this->trend($row['player_id'], 30);
        }
        unset($row);

        return $this->score($rows);
    }

    /**
     * Bewertet eine Liste von Angeboten auf einer Skala von 0 bis 100.
     *
     * Drei Bausteine, jeweils als Rang innerhalb der Kandidaten normalisiert:
     *   Preisabschlag gegenueber Marktwert   45 %
     *   Punkte pro Million                   35 %
     *   Marktwert-Trend der letzten 7 Tage   20 %
     * Nicht einsatzbereite Spieler werden abgewertet.
     */
    private function score(array $rows)
    {
        if (!$rows) {
            return $rows;
        }

        $components = [
            'discount' => 0.45,
            'ppm'      => 0.35,
            'trend'    => 0.20,
        ];

        $values = ['discount' => [], 'ppm' => [], 'trend' => []];
        foreach ($rows as $i => $row) {
            $values['discount'][$i] = $row['discount'];
            $values['ppm'][$i]      = $row['ppm'];
            $values['trend'][$i]    = isset($row['trend7']['pct']) ? $row['trend7']['pct'] : null;
        }

        $ranks = [];
        foreach ($values as $key => $list) {
            $ranks[$key] = $this->percentileRank($list);
        }

        foreach ($rows as $i => &$row) {
            $score  = 0.0;
            $weight = 0.0;
            foreach ($components as $key => $w) {
                if ($ranks[$key][$i] !== null) {
                    $score  += $ranks[$key][$i] * $w;
                    $weight += $w;
                }
            }
            $row['score'] = $weight > 0 ? round($score / $weight, 1) : null;

            // Verletzt, gesperrt oder abwesend: deutlich abwerten.
            if ($row['score'] !== null && (int) $row['status'] !== 0) {
                $row['score'] = round($row['score'] * 0.5, 1);
            }

            $row['score_parts'] = [
                'discount' => $ranks['discount'][$i],
                'ppm'      => $ranks['ppm'][$i],
                'trend'    => $ranks['trend'][$i],
            ];
        }
        unset($row);

        usort($rows, function ($a, $b) {
            return ($b['score'] === null ? -1 : $b['score']) <=> ($a['score'] === null ? -1 : $a['score']);
        });

        return $rows;
    }

    /**
     * Wandelt Werte in Perzentilraenge 0..100 um. null bleibt null.
     */
    private function percentileRank(array $values)
    {
        $valid = [];
        foreach ($values as $i => $v) {
            if ($v !== null) {
                $valid[$i] = (float) $v;
            }
        }
        if (count($valid) < 2) {
            $out = [];
            foreach ($values as $i => $v) {
                $out[$i] = $v === null ? null : 50.0;
            }
            return $out;
        }

        $sorted = $valid;
        asort($sorted);
        $positions = [];
        $rank      = 0;
        foreach ($sorted as $i => $v) {
            $positions[$i] = $rank++;
        }
        $max = count($sorted) - 1;

        $out = [];
        foreach ($values as $i => $v) {
            $out[$i] = $v === null ? null : round($positions[$i] / $max * 100, 1);
        }
        return $out;
    }

    // ---------------------------------------------------------------- Kader

    public function squad($leagueId)
    {
        $rows = $this->db->all(
            'SELECT s.*, p.known_name, p.first_name, p.last_name, p.team_id, p.position,
                    p.status, p.avg_points, p.total_points, p.matches, p.image,
                    p.market_value AS current_mv
             FROM squad_players s
             LEFT JOIN players p ON p.player_id = s.player_id
             WHERE s.league_id = ?
             ORDER BY p.position ASC, p.market_value DESC',
            [$leagueId]
        );

        foreach ($rows as &$row) {
            $mv = $row['current_mv'] !== null ? (int) $row['current_mv'] : (int) $row['market_value'];
            $row['mv']      = $mv;
            $row['ppm']     = ($mv > 0 && $row['avg_points'] !== null) ? $row['avg_points'] / ($mv / 1000000) : null;
            $row['trend7']  = $this->trend($row['player_id'], 7);
            $row['trend30'] = $this->trend($row['player_id'], 30);
        }
        unset($row);

        return $rows;
    }

    public function squadTotals(array $squad)
    {
        $totals = ['value' => 0, 'gain' => 0, 'day_change' => 0, 'points' => 0, 'count' => count($squad)];
        foreach ($squad as $row) {
            $totals['value']      += (int) $row['mv'];
            $totals['gain']       += (int) $row['mv_gain'];
            $totals['day_change'] += (int) $row['day_change'];
            $totals['points']     += (int) $row['total_points'];
        }
        return $totals;
    }

    // ------------------------------------------------------------ Aufstellung

    /**
     * Waehlbare Formationen. Der Torwart steht immer auf Slot 0, danach
     * fuellen Abwehr, Mittelfeld und Sturm die Slots 1 bis 10 auf.
     */
    public function formations()
    {
        return [
            '3-4-3' => [3, 4, 3],
            '3-5-2' => [3, 5, 2],
            '4-4-2' => [4, 4, 2],
            '4-3-3' => [4, 3, 3],
            '4-5-1' => [4, 5, 1],
            '5-3-2' => [5, 3, 2],
            '5-4-1' => [5, 4, 1],
        ];
    }

    /**
     * Slot-Nummer -> erlaubte Position fuer eine Formation.
     * @return array [slot => position]
     */
    public function formationSlots($formation)
    {
        $all = $this->formations();
        if (!isset($all[$formation])) {
            $formation = '4-4-2';
        }
        list($def, $mid, $att) = $all[$formation];

        $slots = [0 => Kickbase::POS_TW];
        $slot  = 1;
        foreach ([[Kickbase::POS_ABW, $def], [Kickbase::POS_MF, $mid], [Kickbase::POS_ST, $att]] as $group) {
            for ($i = 0; $i < $group[1]; $i++) {
                $slots[$slot++] = $group[0];
            }
        }
        return $slots;
    }

    /**
     * Erwartete Punkte je Spieler, aus den Spieltagsdaten.
     *
     * Drei Bausteine, absichtlich einfach und ablesbar:
     *   Basis       Punkte je Einsatz - 60 % die letzten fuenf Einsaetze
     *               (Form), 40 % der Schnitt aller gespeicherten Einsaetze
     *   Einsatzquote Letzte zehn Spieltage: ein Spiel ueber 60 Minuten zaehlt
     *               voll, ein Kurzeinsatz 0,4 - das Rotationsrisiko
     *   Verfuegbar  0 bei verletzt, gesperrt oder abwesend
     *
     * Was hier bewusst NICHT einfliesst: Gegnerstaerke, Heimvorteil,
     * Wechselgeruechte. Dafuer fehlen die Daten. Und die Punkte koennen aus
     * einem anderen Wettbewerb stammen (die API liefert auch Zweitliga-
     * Saisons) - deshalb wird der Wettbewerb mitgegeben, statt ihn zu
     * verschweigen.
     *
     * @param array $playerIds
     * @return array [player_id => array]
     */
    public function forecasts(array $playerIds)
    {
        $out = [];
        if (!$playerIds) {
            return $out;
        }

        $ph = implode(',', array_fill(0, count($playerIds), '?'));

        // Nur beendete Spiele, neueste zuerst. Das laeuft ueber Saisongrenzen
        // hinweg - zu Saisonbeginn stammt die Form damit aus der Vorsaison.
        $rows = $this->db->all(
            "SELECT player_id, season_id, season_name, competition, day,
                    points, minutes, match_date
             FROM player_performances
             WHERE player_id IN ({$ph}) AND match_state = 2
             ORDER BY match_date DESC, season_id DESC, day DESC",
            $playerIds
        );

        $byPlayer = [];
        foreach ($rows as $row) {
            $byPlayer[$row['player_id']][] = $row;
        }

        // Saison-Schnitt und Status aus der Spielertabelle als Rueckfallebene.
        $meta = [];
        foreach ($this->db->all(
            "SELECT player_id, avg_points, status FROM players WHERE player_id IN ({$ph})",
            $playerIds
        ) as $row) {
            $meta[$row['player_id']] = $row;
        }

        foreach ($playerIds as $pid) {
            $games  = isset($byPlayer[$pid]) ? $byPlayer[$pid] : [];
            $status = isset($meta[$pid]) ? (int) $meta[$pid]['status'] : 0;
            $out[$pid] = $this->buildForecast($games, $status,
                isset($meta[$pid]) ? $meta[$pid]['avg_points'] : null);
        }

        return $out;
    }

    /** Rechnet eine einzelne Prognose. Siehe forecasts() fuer die Bausteine. */
    private function buildForecast(array $games, $status, $avgPoints)
    {
        $result = [
            'points'       => null,
            'base'         => null,
            'start_rate'   => null,
            'availability' => $this->availability($status),
            'source'       => 'keine',
            'appearances'  => 0,
            'starts'       => 0,
            'recent'       => 0,
            'minutes'      => null,
            'season_id'    => null,
            'season_name'  => null,
            'competition'  => null,
            'note'         => '',
        ];

        // Einsaetze = Spiele mit Punkten. Ohne Punkte war der Spieler nicht dabei.
        $appearances = [];
        foreach ($games as $game) {
            if ($game['points'] !== null) {
                $appearances[] = $game;
            }
        }
        $result['appearances'] = count($appearances);

        if ($appearances) {
            $result['season_id']   = $appearances[0]['season_id'];
            $result['season_name'] = $appearances[0]['season_name'];
            $result['competition'] = $appearances[0]['competition'];

            $last5 = array_slice($appearances, 0, 5);
            $form  = $this->average(array_column($last5, 'points'));
            $all   = $this->average(array_column($appearances, 'points'));

            // Mit weniger als drei Einsaetzen ist "Form" noch kein Signal.
            $result['base']   = count($appearances) >= 3 ? 0.6 * $form + 0.4 * $all : $all;
            $result['source'] = count($appearances) >= 3 ? 'form' : 'wenige Spiele';

            $mins = array_filter(array_column($appearances, 'minutes'), 'strlen');
            $result['minutes'] = $mins ? (int) round($this->average($mins)) : null;
        } elseif ($avgPoints !== null && $avgPoints !== '') {
            // Keine Spieltagsdaten, aber ein Saisonschnitt aus der Spielerliste.
            $result['base']   = (float) $avgPoints;
            $result['source'] = 'Saisonschnitt';
        } else {
            $result['note'] = 'keine Daten';
            return $result;
        }

        // Einsatzquote aus den letzten zehn Spieltagen. Ein Kurzeinsatz zaehlt
        // anteilig: wer regelmaessig eingewechselt wird, punktet weniger als
        // ein Stammspieler, aber nicht null.
        $recent = array_slice($games, 0, 10);
        if ($recent) {
            $weighted = 0.0;
            $starts   = 0;
            foreach ($recent as $game) {
                if ($game['points'] === null) {
                    continue;   // nicht im Kader
                }
                if ((int) $game['minutes'] >= 60) {
                    $weighted += 1.0;
                    $starts++;
                } else {
                    $weighted += 0.4;
                }
            }
            $result['start_rate'] = $weighted / count($recent);
            $result['starts']     = $starts;
            $result['recent']     = count($recent);
        }

        $rate = $result['start_rate'] !== null ? $result['start_rate'] : 1.0;
        $result['points'] = $result['base'] * $rate * $result['availability'];

        if ($result['start_rate'] === null) {
            $result['note'] = 'Einsatzquote unbekannt';
        } elseif ($result['availability'] <= 0) {
            $result['note'] = 'faellt aus';
        } elseif ($result['availability'] < 1) {
            $result['note'] = 'Einsatz fraglich';
        }

        return $result;
    }

    /**
     * Wie wahrscheinlich ist ein Einsatz laut Spielerstatus?
     * Angeschlagen und Aufbautraining sind Graubereiche, keine klaren Ausfaelle.
     */
    private function availability($status)
    {
        switch ((int) $status) {
            case 0:  return 1.0;    // fit
            case 2:  return 0.5;    // angeschlagen
            case 4:  return 0.25;   // Aufbautraining
            default: return 0.0;    // verletzt, gesperrt, abwesend
        }
    }

    private function average(array $values)
    {
        $values = array_map('floatval', $values);
        return $values ? array_sum($values) / count($values) : 0.0;
    }

    /**
     * Kader mit Prognose und geplanter Aufstellung.
     *
     * Ohne eigene Planung wird die echte Kickbase-Aufstellung als Ausgangslage
     * genommen, damit die Seite nicht leer startet.
     */
    public function lineup($leagueId)
    {
        $rows = $this->db->all(
            'SELECT s.player_id, s.lineup_slot AS kickbase_slot, s.market_value, s.on_market,
                    p.known_name, p.first_name, p.last_name, p.team_id, p.position, p.status,
                    p.avg_points, p.total_points, p.image, p.market_value AS current_mv,
                    l.slot AS planned_slot
             FROM squad_players s
             LEFT JOIN players p ON p.player_id = s.player_id
             LEFT JOIN lineup_plan l ON l.league_id = s.league_id AND l.player_id = s.player_id
             WHERE s.league_id = ?
             ORDER BY p.position ASC, p.market_value DESC',
            [(string) $leagueId]
        );

        $hasPlan   = false;
        $playerIds = [];
        foreach ($rows as $row) {
            $playerIds[] = $row['player_id'];
            if ($row['planned_slot'] !== null) {
                $hasPlan = true;
            }
        }

        $forecasts = $this->forecasts($playerIds);

        foreach ($rows as &$row) {
            $row['mv']       = $row['current_mv'] !== null ? (int) $row['current_mv'] : (int) $row['market_value'];
            $row['forecast'] = $forecasts[$row['player_id']];
            // Ohne eigene Planung gilt die Aufstellung aus Kickbase.
            $row['slot'] = $hasPlan
                ? ($row['planned_slot'] !== null ? (int) $row['planned_slot'] : null)
                : ($row['kickbase_slot'] !== null ? (int) $row['kickbase_slot'] : null);
        }
        unset($row);

        return ['players' => $rows, 'has_plan' => $hasPlan];
    }

    /** Formation, die zur aktuellen Belegung passt - sonst die gespeicherte. */
    public function lineupFormation(array $players)
    {
        $saved = $this->db->getMeta('lineup_formation');
        if ($saved && isset($this->formations()[$saved])) {
            return $saved;
        }

        $counts = [Kickbase::POS_ABW => 0, Kickbase::POS_MF => 0, Kickbase::POS_ST => 0];
        foreach ($players as $row) {
            if ($row['slot'] === null || (int) $row['position'] === Kickbase::POS_TW) {
                continue;
            }
            $pos = (int) $row['position'];
            if (isset($counts[$pos])) {
                $counts[$pos]++;
            }
        }

        $key = $counts[Kickbase::POS_ABW] . '-' . $counts[Kickbase::POS_MF] . '-' . $counts[Kickbase::POS_ST];
        return isset($this->formations()[$key]) ? $key : '4-4-2';
    }

    /**
     * Speichert eine Planung. Prueft, dass jeder Spieler auf einem Slot steht,
     * der zu seiner Position passt, und dass kein Slot doppelt belegt ist.
     *
     * @param array $slots [player_id => slot|null]
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public function saveLineup($leagueId, $formation, array $slots)
    {
        $allowed = $this->formationSlots($formation);

        $squad = [];
        foreach ($this->db->all(
            'SELECT s.player_id, p.position FROM squad_players s
             LEFT JOIN players p ON p.player_id = s.player_id
             WHERE s.league_id = ?',
            [(string) $leagueId]
        ) as $row) {
            $squad[$row['player_id']] = (int) $row['position'];
        }

        $seen = [];
        foreach ($slots as $playerId => $slot) {
            if (!isset($squad[$playerId])) {
                return ['ok' => false, 'error' => 'Spieler nicht im Kader: ' . $playerId];
            }
            if ($slot === null || $slot === '') {
                continue;
            }
            $slot = (int) $slot;
            if (!isset($allowed[$slot])) {
                return ['ok' => false, 'error' => 'Slot ' . $slot . ' gibt es in ' . $formation . ' nicht.'];
            }
            if (isset($seen[$slot])) {
                return ['ok' => false, 'error' => 'Slot ' . $slot . ' ist doppelt belegt.'];
            }
            if ($squad[$playerId] !== $allowed[$slot]) {
                return ['ok' => false, 'error' => sprintf(
                    'Spieler %s (%s) passt nicht auf einen %s-Platz.',
                    $playerId, Kickbase::positionName($squad[$playerId]),
                    Kickbase::positionName($allowed[$slot])
                )];
            }
            $seen[$slot] = true;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->run('DELETE FROM lineup_plan WHERE league_id = ?', [(string) $leagueId]);
        foreach ($slots as $playerId => $slot) {
            $this->db->upsert('lineup_plan', [
                'league_id'  => (string) $leagueId,
                'player_id'  => (string) $playerId,
                'slot'       => ($slot === null || $slot === '') ? null : (int) $slot,
                'updated_at' => $now,
            ], ['slot', 'updated_at']);
        }
        $this->db->setMeta('lineup_formation', $formation);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Verwirft die eigene Planung. Danach zeigt die Seite wieder die
     * Aufstellung, die in Kickbase steht.
     */
    public function resetLineup($leagueId)
    {
        $this->db->run('DELETE FROM lineup_plan WHERE league_id = ?', [(string) $leagueId]);
        $this->db->run('DELETE FROM meta WHERE k = ?', ['lineup_formation']);
        return ['ok' => true, 'error' => null];
    }

    /**
     * Naechstes Spiel je Spieler.
     *
     * Das eigene Team kommt aus der Spielertabelle, nicht aus der Spielzeile:
     * bei noch nicht gespielten Partien liefert die API kein own_team.
     *
     * @return array [player_id => ['day' => int, 'date' => string, 'opponent' => string, 'home' => bool]]
     */
    public function nextMatches()
    {
        $rows = $this->db->all(
            "SELECT pf.player_id, pf.day, pf.match_date, pf.team_home, pf.team_away, p.team_id
             FROM player_performances pf
             JOIN players p ON p.player_id = pf.player_id
             WHERE pf.match_state = 0 AND pf.match_date IS NOT NULL
               AND pf.match_date >= NOW() AND p.team_id IS NOT NULL
             ORDER BY pf.match_date ASC"
        );

        $out = [];
        foreach ($rows as $row) {
            $pid = $row['player_id'];
            if (isset($out[$pid])) {
                continue;   // das erste ist das naechste
            }
            $home = $row['team_home'] === $row['team_id'];
            $out[$pid] = [
                'day'      => (int) $row['day'],
                'date'     => $row['match_date'],
                'opponent' => $home ? $row['team_away'] : $row['team_home'],
                'home'     => $home,
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------- Marktwert-Radar

    /**
     * Punkteklassen fuer die Marktwert-Reaktion. Bewusst grob: bei ein paar
     * hundert Datenpunkten sind fuenf Klassen robuster als eine Regression.
     */
    public function pointClasses()
    {
        return [
            ['label' => 'kein Einsatz', 'from' => -9999, 'to' => 0],
            ['label' => 'schwach',      'from' => 1,     'to' => 40],
            ['label' => 'ok',           'from' => 41,    'to' => 80],
            ['label' => 'gut',          'from' => 81,    'to' => 140],
            ['label' => 'stark',        'from' => 141,   'to' => 99999],
        ];
    }

    /**
     * Wie stark reagiert der Marktwert auf die Punkte eines Spieltags?
     *
     * Wird aus den eigenen Daten gelernt, nicht festgeschrieben: mit jedem
     * Spieltag wird die Schaetzung besser.
     *
     * Zwei Dinge sind dabei entscheidend, sonst misst man Unsinn:
     *
     * 1. Die Marktwerte steigen generell - rund 1,8 Prozent je drei Tage.
     *    Gemessen wird deshalb der Abstand zur allgemeinen Marktbewegung
     *    desselben Zeitraums, nicht die rohe Veraenderung. Ohne das faellt
     *    die Inflation ins Ergebnis und jeder Spieler sieht nach Gewinn aus.
     * 2. Einzelne Sprünge gehen bis mehrere tausend Prozent (Platzhalter-
     *    Marktwerte bei Neuzugaengen). Solche Ausreisser werden verworfen,
     *    sie sind keine Marktbewegung.
     *
     * @return array Liste aus ['label', 'from', 'to', 'n', 'pct']
     */
    public function marketValueResponse($days = 3)
    {
        $days = (int) $days;
        $cap  = 50;   // Prozentpunkte; darueber ist es ein Datenfehler

        $rows = cacheRemember('mv_response_' . $days, 1800, function () use ($days, $cap) {
            return $this->db->all(
                'SELECT pp.points,
                        ((mv1.market_value - mv0.market_value) / mv0.market_value * 100)
                            - drift.pct AS pct
                 FROM player_performances pp
                 JOIN player_market_values mv0
                   ON mv0.player_id = pp.player_id AND mv0.day = DATE(pp.match_date)
                 JOIN player_market_values mv1
                   ON mv1.player_id = pp.player_id
                  AND mv1.day = DATE_ADD(DATE(pp.match_date), INTERVAL ? DAY)
                 JOIN (
                     SELECT a.day,
                            AVG((b.market_value - a.market_value) / a.market_value * 100) AS pct
                     FROM player_market_values a
                     JOIN player_market_values b
                       ON b.player_id = a.player_id AND b.day = DATE_ADD(a.day, INTERVAL ? DAY)
                     WHERE a.market_value > 0
                       AND ABS((b.market_value - a.market_value) / a.market_value * 100) <= ?
                     GROUP BY a.day
                 ) drift ON drift.day = DATE(pp.match_date)
                 WHERE pp.match_state = 2 AND pp.points IS NOT NULL
                   AND mv0.market_value > 0
                   AND ABS((mv1.market_value - mv0.market_value) / mv0.market_value * 100) <= ?',
                [$days, $days, $cap, $cap]
            );
        });

        $out = [];
        foreach ($this->pointClasses() as $class) {
            $values = [];
            foreach ($rows as $row) {
                $p = (float) $row['points'];
                if ($p >= $class['from'] && $p <= $class['to']) {
                    $values[] = (float) $row['pct'];
                }
            }
            $class['n']   = count($values);
            $class['pct'] = $values ? array_sum($values) / count($values) : null;
            $out[] = $class;
        }
        return $out;
    }

    /**
     * Erwartete Marktwert-Entwicklung je Spieler.
     *
     * Nicht einfach "Prognose in eine Klasse stecken": ein Erwartungswert von
     * 40 Punkten entsteht oft aus halber Einsatzchance mal 80 Punkten - die
     * Wirklichkeit ist dann 0 oder 80, nie 40. Deshalb wird gemischt:
     *
     *   Anteil ohne Einsatz  -> Reaktion der Klasse "kein Einsatz"
     *   Anteil mit Einsatz   -> Reaktion der Klasse, in die die Basis faellt
     *
     * @param array $forecasts Ergebnis von forecasts()
     * @return array [player_id => ['pct', 'play_chance', 'class', 'confident']]
     */
    public function marketValueOutlook(array $forecasts, $days = 3)
    {
        $response = $this->marketValueResponse($days);

        $noPlay = null;
        foreach ($response as $class) {
            if ($class['to'] == 0) {
                $noPlay = $class;
                break;
            }
        }

        $out = [];
        foreach ($forecasts as $pid => $fc) {
            $result = ['pct' => null, 'play_chance' => null, 'class' => null, 'confident' => false];

            if ($fc['base'] === null) {
                $out[$pid] = $result;
                continue;
            }

            $rate   = $fc['start_rate'] !== null ? (float) $fc['start_rate'] : 1.0;
            $chance = max(0.0, min(1.0, $rate * (float) $fc['availability']));
            $result['play_chance'] = $chance;

            // Klasse fuer den Fall, dass er spielt.
            $playing = null;
            foreach ($response as $class) {
                if ($fc['base'] >= $class['from'] && $fc['base'] <= $class['to'] && $class['to'] > 0) {
                    $playing = $class;
                    break;
                }
            }
            if ($playing === null || $playing['pct'] === null || $noPlay === null || $noPlay['pct'] === null) {
                $out[$pid] = $result;
                continue;
            }

            $result['pct']   = (1 - $chance) * $noPlay['pct'] + $chance * $playing['pct'];
            $result['class'] = $playing['label'];
            // Nur belastbar, wenn beide Klassen genug Faelle haben.
            $result['confident'] = $playing['n'] >= 20 && $noPlay['n'] >= 20
                && $fc['start_rate'] !== null;

            $out[$pid] = $result;
        }
        return $out;
    }

    /**
     * Kader mit erwarteter Marktwert-Entwicklung, groesster Verlust zuerst.
     */
    public function squadOutlook($leagueId, $days = 3)
    {
        $rows = $this->db->all(
            'SELECT s.player_id, s.market_value, s.mv_gain, s.day_change, s.on_market,
                    p.known_name, p.first_name, p.last_name, p.team_name, p.position,
                    p.status, p.avg_points, p.market_value AS current_mv
             FROM squad_players s
             LEFT JOIN players p ON p.player_id = s.player_id
             WHERE s.league_id = ?',
            [(string) $leagueId]
        );
        return $this->attachOutlook($rows, $days);
    }

    /**
     * Offene Marktangebote mit erwarteter Marktwert-Entwicklung.
     */
    public function marketOutlook($leagueId, $days = 3)
    {
        $rows = $this->db->all(
            'SELECT l.player_id, l.price, l.expires_at, l.market_value,
                    p.known_name, p.first_name, p.last_name, p.team_name, p.position,
                    p.status, p.avg_points, p.market_value AS current_mv
             FROM market_listings l
             LEFT JOIN players p ON p.player_id = l.player_id
             WHERE l.league_id = ? AND l.gone_at IS NULL',
            [(string) $leagueId]
        );
        return $this->attachOutlook($rows, $days);
    }

    /** Haengt Prognose und Marktwert-Aussicht an eine Spielerliste. */
    private function attachOutlook(array $rows, $days)
    {
        if (!$rows) {
            return [];
        }

        $ids       = array_column($rows, 'player_id');
        $forecasts = $this->forecasts($ids);
        $outlook   = $this->marketValueOutlook($forecasts, $days);

        foreach ($rows as &$row) {
            $mv = $row['current_mv'] !== null ? (int) $row['current_mv'] : (int) $row['market_value'];
            $row['mv']       = $mv;
            $row['forecast'] = $forecasts[$row['player_id']];
            $row['outlook']  = $outlook[$row['player_id']];
            $row['mv_delta'] = $row['outlook']['pct'] !== null
                ? (int) round($mv * $row['outlook']['pct'] / 100)
                : null;
        }
        unset($row);

        // Groesster erwarteter Verlust zuerst - das ist die Handlungsliste.
        usort($rows, function ($a, $b) {
            $x = $a['outlook']['pct'];
            $y = $b['outlook']['pct'];
            if ($x === null && $y === null) {
                return 0;
            }
            if ($x === null) {
                return 1;
            }
            if ($y === null) {
                return -1;
            }
            return $x < $y ? -1 : ($x > $y ? 1 : 0);
        });

        return $rows;
    }

    // -------------------------------------------------- Prognose-Genauigkeit

    /**
     * Friert die aktuelle Prognose fuer das jeweils naechste Spiel ein.
     *
     * Nur solange nicht angepfiffen ist - danach waere es keine Prognose, und
     * bestehende Eintraege bleiben unangetastet. Bis zum Anpfiff wird der
     * Eintrag aktualisiert, es gilt also der letzte Stand vor dem Spiel.
     *
     * @return int Zahl der geschriebenen Eintraege
     */
    public function snapshotForecasts($leagueId)
    {
        $rows = $this->db->all(
            'SELECT s.player_id, p.avg_points
             FROM squad_players s
             LEFT JOIN players p ON p.player_id = s.player_id
             WHERE s.league_id = ?',
            [(string) $leagueId]
        );
        if (!$rows) {
            return 0;
        }

        $playerIds = array_column($rows, 'player_id');
        $baseline  = [];
        foreach ($rows as $row) {
            $baseline[$row['player_id']] = $row['avg_points'];
        }

        $forecasts = $this->forecasts($playerIds);
        $next      = $this->nextMatches();
        $now       = date('Y-m-d H:i:s');
        $written   = 0;

        foreach ($playerIds as $pid) {
            if (!isset($next[$pid])) {
                continue;   // kein angesetztes Spiel
            }
            $match = $next[$pid];
            if (strtotime($match['date']) <= time()) {
                continue;   // laeuft schon oder ist vorbei
            }

            // Saison des Spiels: die Zeile in player_performances kennt sie.
            $seasonId = $this->db->value(
                'SELECT season_id FROM player_performances
                 WHERE player_id = ? AND day = ? AND match_state = 0
                 ORDER BY match_date ASC LIMIT 1',
                [$pid, $match['day']]
            );
            if ($seasonId === null) {
                continue;
            }

            // Bereits abgeschlossene Eintraege nicht mehr anfassen.
            $existing = $this->db->one(
                'SELECT resolved_at FROM forecast_log
                 WHERE player_id = ? AND season_id = ? AND day = ?',
                [$pid, $seasonId, $match['day']]
            );
            if ($existing && $existing['resolved_at'] !== null) {
                continue;
            }

            $fc = $forecasts[$pid];
            $this->db->upsert('forecast_log', [
                'player_id'       => (string) $pid,
                'season_id'       => (string) $seasonId,
                'day'             => (int) $match['day'],
                'match_date'      => $match['date'],
                'forecast_points' => $fc['points'],
                'baseline_points' => $baseline[$pid],
                'base'            => $fc['base'],
                'start_rate'      => $fc['start_rate'],
                'availability'    => $fc['availability'],
                'created_at'      => $now,
                'updated_at'      => $now,
            ], ['match_date', 'forecast_points', 'baseline_points', 'base',
                'start_rate', 'availability', 'updated_at']);
            $written++;
        }

        return $written;
    }

    /**
     * Traegt die tatsaechlichen Punkte zu abgeschlossenen Spielen nach.
     *
     * @return int Zahl der aufgeloesten Eintraege
     */
    public function resolveForecasts()
    {
        $open = $this->db->all(
            'SELECT f.player_id, f.season_id, f.day, pp.points, pp.minutes
             FROM forecast_log f
             JOIN player_performances pp
               ON pp.player_id = f.player_id AND pp.season_id = f.season_id AND pp.day = f.day
             WHERE f.resolved_at IS NULL AND pp.match_state = 2'
        );

        $now   = date('Y-m-d H:i:s');
        $count = 0;
        foreach ($open as $row) {
            // Kein Einsatz = 0 Punkte, nicht "unbekannt": auch das ist ein
            // Prognosefehler, wenn wir Punkte erwartet haben.
            $this->db->run(
                'UPDATE forecast_log
                 SET actual_points = ?, actual_minutes = ?, resolved_at = ?
                 WHERE player_id = ? AND season_id = ? AND day = ?',
                [$row['points'] !== null ? (int) $row['points'] : 0,
                 $row['minutes'] !== null ? (int) $row['minutes'] : 0,
                 $now, $row['player_id'], $row['season_id'], $row['day']]
            );
            $count++;
        }
        return $count;
    }

    /**
     * Wie gut war die Prognose?
     *
     * Kennzahl ist der mittlere absolute Fehler (MAE) in Punkten, immer neben
     * dem der Vergleichsrechnung. Ist die Formel nicht besser als der
     * Saisonschnitt, taugt sie nicht - genau das soll hier sichtbar werden.
     */
    public function forecastAccuracy()
    {
        $overall = $this->db->one(
            'SELECT COUNT(*) AS n,
                    AVG(ABS(actual_points - forecast_points)) AS mae,
                    AVG(ABS(actual_points - baseline_points)) AS mae_baseline,
                    AVG(actual_points - forecast_points)      AS bias,
                    AVG(actual_points) AS avg_actual,
                    AVG(forecast_points) AS avg_forecast
             FROM forecast_log
             WHERE resolved_at IS NOT NULL
               AND forecast_points IS NOT NULL AND baseline_points IS NOT NULL'
        );

        $byDay = $this->db->all(
            'SELECT season_id, day, COUNT(*) AS n,
                    AVG(ABS(actual_points - forecast_points)) AS mae,
                    AVG(ABS(actual_points - baseline_points)) AS mae_baseline
             FROM forecast_log
             WHERE resolved_at IS NOT NULL
               AND forecast_points IS NOT NULL AND baseline_points IS NOT NULL
             GROUP BY season_id, day
             ORDER BY season_id DESC, day DESC'
        );

        $byPosition = $this->db->all(
            'SELECT p.position, COUNT(*) AS n,
                    AVG(ABS(f.actual_points - f.forecast_points)) AS mae,
                    AVG(ABS(f.actual_points - f.baseline_points)) AS mae_baseline
             FROM forecast_log f
             LEFT JOIN players p ON p.player_id = f.player_id
             WHERE f.resolved_at IS NOT NULL
               AND f.forecast_points IS NOT NULL AND f.baseline_points IS NOT NULL
             GROUP BY p.position
             ORDER BY p.position'
        );

        $worst = $this->db->all(
            'SELECT f.*, p.known_name, p.last_name, p.first_name, p.position,
                    ABS(f.actual_points - f.forecast_points) AS fehler
             FROM forecast_log f
             LEFT JOIN players p ON p.player_id = f.player_id
             WHERE f.resolved_at IS NOT NULL AND f.forecast_points IS NOT NULL
             ORDER BY fehler DESC
             LIMIT 10'
        );

        $open = (int) $this->db->value(
            'SELECT COUNT(*) FROM forecast_log WHERE resolved_at IS NULL'
        );

        return [
            'overall'     => $overall,
            'by_day'      => $byDay,
            'by_position' => $byPosition,
            'worst'       => $worst,
            'open'        => $open,
        ];
    }

    // -------------------------------------------------------------- Spieler

    public function player($playerId)
    {
        return $this->db->one('SELECT * FROM players WHERE player_id = ?', [$playerId]);
    }

    public function search($term, $limit = 20)
    {
        $like = '%' . $term . '%';
        return $this->db->all(
            'SELECT * FROM players
             WHERE known_name LIKE ? OR last_name LIKE ? OR first_name LIKE ?
             ORDER BY market_value DESC
             LIMIT ' . (int) $limit,
            [$like, $like, $like]
        );
    }

    /** Kennzahlen fuer den Spielervergleich. */
    public function compare(array $playerIds)
    {
        $out = [];
        foreach ($playerIds as $id) {
            $p = $this->player($id);
            if (!$p) {
                continue;
            }
            $mv = (int) $p['market_value'];
            $p['ppm']     = ($mv > 0 && $p['avg_points'] !== null) ? $p['avg_points'] / ($mv / 1000000) : null;
            $p['trend7']  = $this->trend($id, 7);
            $p['trend30'] = $this->trend($id, 30);
            $p['history'] = $this->marketValueHistory($id, 180);
            $out[] = $p;
        }
        return $out;
    }

    // -------------------------------------------------------- Liga-Aktivitaeten

    /**
     * Aktivitaeten der Liga, aufbereitet und mit dem Marktwert zum Zeitpunkt
     * des Geschehens verknuepft.
     *
     * Die Typ-Nummern sind nicht dokumentiert. Die Bedeutung ist aus den
     * eigenen Daten abgeleitet und dort jederzeit nachpruefbar:
     *
     *   3   Kickbase stellt einen Spieler auf den Markt. 34 von 34 dieser
     *       Meldungen tauchten danach als Angebot auf, ausnahmslos mit
     *       Kickbase als Verkaeufer - und die Angebote von Mitspielern
     *       hatten umgekehrt nie eine solche Meldung davor.
     *   15  Transfer. data.t = 1 ist ein Kauf (data.byr = Kaeufer),
     *       data.t = 2 ein Verkauf (data.slr = Verkaeufer). Gilt fuer alle
     *       bisher erfassten Zeilen ohne Ausnahme.
     *   22  Tagesbonus (data.bn = Betrag, data.day = Spieltag).
     *
     * Richtung und Managername stehen nur im Rohdatensatz. Sie hier zu
     * parsen statt in eigene Spalten zu syncen kostet bei diesen Mengen
     * nichts und macht eine spaetere Korrektur ohne Migration moeglich.
     */
    public function activityFeed($leagueId, $days = 30, $limit = 500)
    {
        $rows = $this->db->all(
            'SELECT a.activity_id, a.type, a.happened_at, a.player_id, a.price, a.raw,
                    p.known_name, p.first_name, p.last_name, p.position, p.status, p.image,
                    (SELECT mv.market_value FROM player_market_values mv
                      WHERE mv.player_id = a.player_id AND mv.day <= DATE(a.happened_at)
                      ORDER BY mv.day DESC LIMIT 1) AS mv_at
             FROM activities a
             LEFT JOIN players p ON p.player_id = a.player_id
             WHERE a.league_id = ? AND a.happened_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY a.happened_at DESC
             LIMIT ' . (int) $limit,
            [(string) $leagueId, (int) $days]
        );

        $out = [];
        foreach ($rows as $row) {
            $raw  = json_decode((string) $row['raw'], true);
            $data = (is_array($raw) && isset($raw['data']) && is_array($raw['data'])) ? $raw['data'] : [];

            $row['kind']     = 'other';
            $row['manager']  = null;
            $row['bonus']    = null;
            $row['matchday'] = null;

            switch ((int) $row['type']) {
                case 15:
                    $dir            = pickInt($data, ['t']);
                    $row['kind']    = $dir === 2 ? 'sell' : 'buy';
                    $row['manager'] = pick($data, $dir === 2 ? ['slr'] : ['byr']);
                    break;
                case 3:
                    $row['kind'] = 'listed';
                    // Die Meldung bringt den Marktwert des Moments mit. Der
                    // ist genauer als der Tageswert aus der Historie.
                    $mv = pickInt($data, ['mv']);
                    if ($mv !== null) {
                        $row['mv_at'] = $mv;
                    }
                    break;
                case 22:
                    $row['kind']     = 'bonus';
                    $row['bonus']    = pickInt($data, ['bn']);
                    $row['matchday'] = pickInt($data, ['day']);
                    break;
            }

            // Spieler, die (noch) nicht in players stehen: Namen aus der
            // Meldung selbst, damit playerName() im Frontend etwas findet.
            if ($row['known_name'] === null && $row['last_name'] === null) {
                $row['known_name'] = pick($data, ['pn']);
                $row['first_name'] = pick($data, ['fn']);
                $row['last_name']  = pick($data, ['ln']);
            }

            $price = $row['price'] !== null ? (int) $row['price'] : null;
            $mv    = $row['mv_at'] !== null ? (int) $row['mv_at'] : null;

            $row['price']     = $price;
            $row['mv_at']     = $mv;
            $row['delta_pct'] = ($price !== null && $mv !== null && $mv > 0)
                ? ($price - $mv) / $mv * 100 : null;

            unset($row['raw']);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Kennzahlen je Mitspieler ueber die erfassten Transfers.
     *
     * Der Auf- bzw. Abschlag ist dabei die eigentliche Aussage: wer
     * regelmaessig deutlich ueber Marktwert kauft, treibt die Preise -
     * und wer ueber Marktwert verkauft, verdient daran.
     */
    public function managerStats(array $feed)
    {
        $stats = [];

        foreach ($feed as $row) {
            if ($row['kind'] !== 'buy' && $row['kind'] !== 'sell') {
                continue;
            }
            $name = $row['manager'];
            if ($name === null || $name === '') {
                continue;
            }

            if (!isset($stats[$name])) {
                $stats[$name] = [
                    'manager'      => $name,
                    'buys'         => 0, 'buy_volume'  => 0, 'buy_premium'  => [],
                    'sells'        => 0, 'sell_volume' => 0, 'sell_premium' => [],
                ];
            }

            $key = $row['kind'] === 'buy' ? 'buy' : 'sell';
            $stats[$name][$key . 's']++;
            $stats[$name][$key . '_volume'] += (int) $row['price'];
            if ($row['delta_pct'] !== null) {
                $stats[$name][$key . '_premium'][] = $row['delta_pct'];
            }
        }

        foreach ($stats as &$s) {
            $s['transfers'] = $s['buys'] + $s['sells'];
            // Kassensaldo, nicht Gewinn: was ein noch nicht verkaufter
            // Spieler wert ist, steht hier bewusst nicht drin.
            $s['net']           = $s['sell_volume'] - $s['buy_volume'];
            $s['buy_premium']   = $s['buy_premium']
                ? array_sum($s['buy_premium']) / count($s['buy_premium']) : null;
            $s['sell_premium']  = $s['sell_premium']
                ? array_sum($s['sell_premium']) / count($s['sell_premium']) : null;
        }
        unset($s);

        uasort($stats, function ($a, $b) {
            return $b['transfers'] - $a['transfers'];
        });

        return array_values($stats);
    }

    /**
     * Spieler, die derselbe Manager im erfassten Zeitraum gekauft und wieder
     * verkauft hat.
     *
     * Zur Einordnung wichtig: Kaeufe vor dem Zeitraum sind nicht bekannt.
     * Ein Verkauf ohne passenden Kauf faellt deshalb heraus, statt mit
     * einem geratenen Einstandspreis als Fund zu gelten.
     */
    public function quickFlips(array $feed)
    {
        // Der Feed kommt absteigend - fuer die Paarung chronologisch lesen.
        $chron = array_reverse($feed);

        $open  = [];
        $flips = [];

        foreach ($chron as $row) {
            if ($row['manager'] === null || $row['player_id'] === null || $row['price'] === null) {
                continue;
            }
            $key = $row['manager'] . '|' . $row['player_id'];

            if ($row['kind'] === 'buy') {
                $open[$key][] = $row;
            } elseif ($row['kind'] === 'sell' && !empty($open[$key])) {
                $buy = array_shift($open[$key]);
                $flips[] = [
                    'manager'    => $row['manager'],
                    'player_id'  => $row['player_id'],
                    'known_name' => $row['known_name'],
                    'first_name' => $row['first_name'],
                    'last_name'  => $row['last_name'],
                    'position'   => $row['position'],
                    'image'      => $row['image'],
                    'bought_at'  => $buy['happened_at'],
                    'sold_at'    => $row['happened_at'],
                    'buy_price'  => (int) $buy['price'],
                    'sell_price' => (int) $row['price'],
                    'profit'     => (int) $row['price'] - (int) $buy['price'],
                    'hours'      => (strtotime($row['happened_at']) - strtotime($buy['happened_at'])) / 3600,
                ];
            }
        }

        usort($flips, function ($a, $b) {
            return $b['profit'] - $a['profit'];
        });

        return $flips;
    }

    // ---------------------------------------------------------------- Status

    public function status()
    {
        return [
            'last_sync'   => $this->db->getMeta('last_sync'),
            'budget'      => $this->db->getMeta('budget'),
            'team_value'  => $this->db->getMeta('team_value'),
            'matchday'    => $this->db->getMeta('matchday'),
            'players'     => (int) $this->db->value('SELECT COUNT(*) FROM players'),
            'mv_points'   => (int) $this->db->value('SELECT COUNT(*) FROM player_market_values'),
            'mv_pending'  => (int) $this->db->value(
                'SELECT COUNT(*) FROM players WHERE mv_synced_at IS NULL'),
            // Nur Fehler, die seit dem letzten erfolgreichen Lauf aufgetreten
            // sind. Sonst haengt die Meldung auf dem Dashboard fest, auch wenn
            // die Ursache laengst behoben ist.
            'last_error'  => $this->db->one(
                "SELECT started_at, message FROM sync_runs
                 WHERE status = 'error'
                   AND id > COALESCE((SELECT MAX(id) FROM sync_runs WHERE status = 'ok'), 0)
                 ORDER BY id DESC LIMIT 1"),
        ];
    }
}
