<?php
/**
 * Holt Daten von der Kickbase-API und schreibt sie in die lokale Datenbank.
 *
 * Grundsatz: Das Web-Frontend liest ausschliesslich aus der DB. Nur dieser
 * Sync spricht mit der API - so bleibt die Seite schnell und die Zahl der
 * API-Requests ueberschaubar.
 */
class Sync
{
    private $db;
    private $kb;
    private $config;
    private $verbose;

    public function __construct(Db $db, Kickbase $kb, array $config, $verbose = true)
    {
        $this->db      = $db;
        $this->kb      = $kb;
        $this->config  = $config;
        $this->verbose = $verbose;
    }

    private function log($msg)
    {
        if ($this->verbose) {
            echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
        }
    }

    // ------------------------------------------------------------ Stammdaten

    /**
     * Alle Spieler der Liga, Verein fuer Verein.
     *
     * Frueher lief das ueber die vier Positionen der Wettbewerbs-Spielerliste -
     * die ist aber eine Top-25-Bestenliste und lieferte nur 89 Spieler. Ueber
     * die Vereinsprofile sind es rund 520, und die Werte sind Saisonwerte
     * statt Einzelspiel-Daten.
     *
     * Nebeneffekt: jeder Lauf schreibt fuer die ganze Liga einen
     * Marktwert-Punkt. Damit werden die Trends belastbar, ohne pro Spieler die
     * Historie abzufragen.
     */
    public function syncPlayers($competitionId = '1')
    {
        $teamIds = $this->knownTeamIds($competitionId);
        if (!$teamIds) {
            $this->log('Keine Vereine bekannt - Spielerliste uebersprungen.');
            return 0;
        }

        $count = 0;
        $now   = date('Y-m-d H:i:s');
        $today = date('Y-m-d');

        foreach ($teamIds as $teamId) {
            try {
                $team = $this->kb->teamProfile($competitionId, $teamId);
            } catch (Exception $ex) {
                $this->log("Verein {$teamId} fehlgeschlagen: " . $ex->getMessage());
                continue;
            }

            $this->log(sprintf('%s: %d Spieler',
                $team['team_name'] !== null ? $team['team_name'] : 'Verein ' . $teamId,
                count($team['players'])));

            foreach ($team['players'] as $item) {
                $playerId = pick($item, ['i', 'pi']);
                if (!$playerId) {
                    continue;
                }

                $data = [
                    'player_id'  => (string) $playerId,
                    'known_name' => pick($item, ['n']),
                    'team_id'    => pick($item, ['tid'], $team['team_id']),
                    'position'   => pickInt($item, ['pos']),
                    'status'     => pickInt($item, ['st'], 0),
                    'image'      => pick($item, ['pim']),
                    'updated_at' => $now,
                ];
                if ($team['team_name'] !== null && $team['team_name'] !== '') {
                    $data['team_name'] = $team['team_name'];
                }
                // Saisonschnitt - dieser Endpunkt liefert echte Saisonwerte.
                foreach (['avg_points' => 'ap', 'mv_trend' => 'mvt'] as $col => $key) {
                    $val = pickInt($item, [$key]);
                    if ($val !== null) {
                        $data[$col] = $val;
                    }
                }

                $mv = pickInt($item, ['mv']);
                if ($mv !== null) {
                    $data['market_value'] = $mv;
                    $this->recordMarketValue((string) $playerId, $today, $mv);
                }

                $updateCols = array_values(array_diff(array_keys($data), ['player_id']));
                $this->db->upsert('players', $data, $updateCols);
                $count++;
            }
        }

        $this->db->setMeta('players_synced_at', date('c'));
        $this->log("Spieler gespeichert: {$count}");
        return $count;
    }

    /**
     * Vereins-IDs. Kommen aus dem Spielplan, den syncTeams als Kuerzel-Liste
     * in meta ablegt - deshalb laeuft syncTeams vorher.
     */
    private function knownTeamIds($competitionId)
    {
        $shorts = json_decode((string) $this->db->getMeta('team_shorts'), true);
        if (is_array($shorts) && $shorts) {
            return array_keys($shorts);
        }

        // Noch nichts gespeichert: Spielplan direkt auswerten.
        $this->syncTeams($competitionId);
        $shorts = json_decode((string) $this->db->getMeta('team_shorts'), true);
        return is_array($shorts) ? array_keys($shorts) : [];
    }

    /**
     * Top-25-Bestenliste je Position - fuellt last_points und last_minutes,
     * also die Werte des letzten erfassten Spieltags. Vier Requests.
     */
    public function syncTopPlayers($competitionId = '1')
    {
        $count = 0;
        $now   = date('Y-m-d H:i:s');

        foreach ([Kickbase::POS_TW, Kickbase::POS_ABW, Kickbase::POS_MF, Kickbase::POS_ST] as $pos) {
            $items = $this->kb->competitionPlayers($competitionId, (string) $pos, '');

            foreach ($items as $item) {
                $playerId = pick($item, ['pi', 'i']);
                if (!$playerId) {
                    continue;
                }

                // Achtung: Dieser Endpunkt liefert Werte eines einzelnen
                // Spieltags - p sind die Punkte in diesem Spiel, mt die
                // Minuten darin. Sie gehoeren deshalb in die last_*-Spalten
                // und nicht in die Saison-Aggregate. Die holt
                // syncPlayerAggregates() aus dem Spielerdetail.
                $data = [
                    'player_id'    => (string) $playerId,
                    'known_name'   => pick($item, ['n']),
                    'team_id'      => pick($item, ['tid']),
                    'position'     => pickInt($item, ['pos'], $pos),
                    'status'       => pickInt($item, ['st'], 0),
                    'last_points'  => pickInt($item, ['p']),
                    'last_minutes' => pickInt($item, ['mt']),
                    'image'        => pick($item, ['pim']),
                    'updated_at'   => $now,
                ];

                $updateCols = array_values(array_diff(array_keys($data), ['player_id']));
                $this->db->upsert('players', $data, $updateCols);
                $count++;
            }
        }

        $this->db->setMeta('top_players_synced_at', date('c'));
        $this->log("Bestenliste letzter Spieltag: {$count} Eintraege");
        return $count;
    }

    // ----------------------------------------------------------- Eigenes Team

    public function syncSquad($leagueId)
    {
        $items = $this->kb->squad($leagueId);
        $now   = date('Y-m-d H:i:s');
        $seen  = [];

        foreach ($items as $item) {
            $playerId = pick($item, ['i', 'pi']);
            if (!$playerId) {
                continue;
            }
            $seen[] = (string) $playerId;

            $this->db->upsert('squad_players', [
                'league_id'    => (string) $leagueId,
                'player_id'    => (string) $playerId,
                'market_value' => pickInt($item, ['mv']),
                'mv_gain'      => pickInt($item, ['mvgl']),
                'day_change'   => pickInt($item, ['sdmvt']),
                'lineup_slot'  => pickInt($item, ['lo']),
                'on_market'    => !empty($item['iotm']) ? 1 : 0,
                'offer_count'  => pickInt($item, ['ofc']),
                'updated_at'   => $now,
            ], ['market_value', 'mv_gain', 'day_change', 'lineup_slot', 'on_market', 'offer_count', 'updated_at']);

            // Der Squad-Endpoint liefert aktuelle Marktwerte - die nehmen wir mit.
            $mv = pickInt($item, ['mv']);
            if ($mv !== null) {
                $this->db->upsert('players', [
                    'player_id'    => (string) $playerId,
                    'known_name'   => pick($item, ['n']),
                    'team_id'      => pick($item, ['tid']),
                    'position'     => pickInt($item, ['pos']),
                    'status'       => pickInt($item, ['st'], 0),
                    'market_value' => $mv,
                    'mv_trend'     => pickInt($item, ['mvt']),
                    'total_points' => pickInt($item, ['p']),
                    'avg_points'   => pickInt($item, ['ap']),
                    'image'        => pick($item, ['pim']),
                    'updated_at'   => $now,
                ], ['known_name', 'team_id', 'position', 'status', 'market_value', 'mv_trend',
                    'total_points', 'avg_points', 'image', 'updated_at']);

                $this->recordMarketValue((string) $playerId, date('Y-m-d'), $mv);
            }
        }

        // Verkaufte Spieler aus dem Kader entfernen.
        if ($seen) {
            $ph = implode(',', array_fill(0, count($seen), '?'));
            $this->db->run(
                "DELETE FROM squad_players WHERE league_id = ? AND player_id NOT IN ({$ph})",
                array_merge([(string) $leagueId], $seen)
            );
        }

        // Budget mitnehmen.
        try {
            $budget = $this->kb->budget($leagueId);
            $this->db->setMeta('budget', pickInt($budget, ['b', 'pbas'], 0));
            $this->db->setMeta('budget_synced_at', date('c'));
        } catch (Exception $ex) {
            $this->log('Budget konnte nicht geladen werden: ' . $ex->getMessage());
        }

        $this->log('Kader gespeichert: ' . count($seen) . ' Spieler');
        return count($seen);
    }

    // ----------------------------------------------------------- Transfermarkt

    public function syncMarket($leagueId)
    {
        $res   = $this->kb->market($leagueId);
        $items = isset($res['it']) ? $res['it'] : [];
        $now   = date('Y-m-d H:i:s');
        $seen  = [];

        foreach ($items as $item) {
            $playerId = pick($item, ['i', 'pi']);
            if (!$playerId) {
                continue;
            }
            $playerId = (string) $playerId;
            $seen[]   = $playerId;

            $price = pickInt($item, ['prc', 'price']);
            $mv    = pickInt($item, ['mv']);

            // Ablauf: entweder Restsekunden (exs) oder ein Zeitstempel (dt).
            $expires = null;
            $exs     = pickInt($item, ['exs']);
            if ($exs !== null) {
                $expires = date('Y-m-d H:i:s', time() + $exs);
            } elseif (pick($item, ['dt'])) {
                $expires = isoToMysql(pick($item, ['dt']));
            }

            // Verkaeufer steht je nach Antwort direkt drin oder in einem Unterobjekt.
            $sellerId   = pick($item, ['u', 'uid', 'usi']);
            $sellerName = pick($item, ['unm', 'un']);
            if (is_array($sellerId)) {
                $sellerName = pick($sellerId, ['n', 'unm'], $sellerName);
                $sellerId   = pick($sellerId, ['i'], null);
            }

            $open = $this->db->one(
                'SELECT id FROM market_listings WHERE league_id = ? AND player_id = ? AND gone_at IS NULL',
                [(string) $leagueId, $playerId]
            );

            if ($open) {
                $this->db->run(
                    'UPDATE market_listings SET price = ?, market_value = ?, expires_at = ?, offer_count = ?,
                     last_seen = ?, raw = ? WHERE id = ?',
                    [$price, $mv, $expires, pickInt($item, ['ofc']), $now,
                     json_encode($item, JSON_UNESCAPED_UNICODE), $open['id']]
                );
            } else {
                $this->db->run(
                    'INSERT INTO market_listings
                     (league_id, player_id, price, market_value, seller_id, seller_name, expires_at,
                      offer_count, first_seen, last_seen, raw)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [(string) $leagueId, $playerId, $price, $mv,
                     $sellerId ? (string) $sellerId : null,
                     $sellerName ? (string) $sellerName : null,
                     $expires, pickInt($item, ['ofc']), $now, $now,
                     json_encode($item, JSON_UNESCAPED_UNICODE)]
                );
            }

            // Spielerstammdaten aus dem Markt-Item nachziehen.
            $playerData = [
                'player_id'  => $playerId,
                'updated_at' => $now,
            ];
            foreach ([['known_name', ['n']], ['first_name', ['fn']], ['last_name', ['ln']],
                      ['team_id', ['tid']], ['image', ['pim']]] as $map) {
                $val = pick($item, $map[1]);
                if ($val !== null) {
                    $playerData[$map[0]] = $val;
                }
            }
            foreach ([['position', ['pos']], ['status', ['st']], ['total_points', ['p']],
                      ['avg_points', ['ap']], ['mv_trend', ['mvt']]] as $map) {
                $val = pickInt($item, $map[1]);
                if ($val !== null) {
                    $playerData[$map[0]] = $val;
                }
            }
            if ($mv !== null) {
                $playerData['market_value'] = $mv;
                $this->recordMarketValue($playerId, date('Y-m-d'), $mv);
            }
            $cols = array_values(array_diff(array_keys($playerData), ['player_id']));
            $this->db->upsert('players', $playerData, $cols);
        }

        // Was nicht mehr auf dem Markt steht, ist weg (gekauft oder abgelaufen).
        if ($seen) {
            $ph = implode(',', array_fill(0, count($seen), '?'));
            $this->db->run(
                "UPDATE market_listings SET gone_at = ? WHERE league_id = ? AND gone_at IS NULL AND player_id NOT IN ({$ph})",
                array_merge([$now, (string) $leagueId], $seen)
            );
        } else {
            $this->db->run(
                'UPDATE market_listings SET gone_at = ? WHERE league_id = ? AND gone_at IS NULL',
                [$now, (string) $leagueId]
            );
        }

        $this->db->setMeta('market_synced_at', date('c'));
        if (isset($res['day'])) {
            $this->db->setMeta('matchday', (int) $res['day']);
        }
        if (isset($res['tv'])) {
            $this->db->setMeta('team_value', (int) $res['tv']);
        }

        $this->log('Transfermarkt: ' . count($seen) . ' Angebote');
        return count($seen);
    }

    // -------------------------------------------------------- Marktwert-Verlauf

    /**
     * Holt die Marktwert-Historie fuer die Spieler, deren Historie am
     * laengsten nicht aktualisiert wurde. $limit begrenzt die Requests
     * pro Lauf, damit ein Sync nicht ewig dauert.
     */
    public function syncMarketValues($leagueId, $limit = 60, $timeframe = 365)
    {
        $rows = $this->db->all(
            'SELECT player_id FROM players
             WHERE mv_synced_at IS NULL OR mv_synced_at < DATE_SUB(NOW(), INTERVAL 20 HOUR)
             ORDER BY mv_synced_at IS NOT NULL, mv_synced_at ASC
             LIMIT ' . (int) $limit
        );

        $done = 0;
        foreach ($rows as $row) {
            $playerId = $row['player_id'];
            try {
                $history = $this->kb->marketValueHistory($leagueId, $playerId, $timeframe);
            } catch (Exception $ex) {
                $this->log("MV-Historie {$playerId} fehlgeschlagen: " . $ex->getMessage());
                // Trotzdem markieren, sonst blockiert der Spieler den naechsten Lauf.
                $this->db->run('UPDATE players SET mv_synced_at = NOW() WHERE player_id = ?', [$playerId]);
                continue;
            }

            $latest = null;
            foreach ($history as $point) {
                $this->recordMarketValue($playerId, $point['date'], $point['value']);
                $latest = $point;
            }

            if ($latest !== null) {
                $this->db->run(
                    'UPDATE players SET market_value = ?, mv_synced_at = NOW() WHERE player_id = ?',
                    [$latest['value'], $playerId]
                );
            } else {
                $this->db->run('UPDATE players SET mv_synced_at = NOW() WHERE player_id = ?', [$playerId]);
            }
            $done++;
        }

        $this->log("Marktwert-Historie aktualisiert: {$done} Spieler");
        return $done;
    }

    private function recordMarketValue($playerId, $day, $value)
    {
        if ($value === null) {
            return;
        }
        $this->db->upsert('player_market_values', [
            'player_id'    => $playerId,
            'day'          => $day,
            'market_value' => (int) $value,
        ], ['market_value']);
    }

    // ------------------------------------------------------- Saison-Aggregate

    /**
     * Holt Punkte, Einsaetze und Spielzeit pro Spieler aus dem Spielerdetail.
     *
     * Ein Request je Spieler, deshalb wie bei der Marktwert-Historie
     * gedrosselt: pro Lauf nur $limit Spieler, die am laengsten nicht
     * aktualisiert wurden. Der eigene Kader kommt zuerst.
     */
    public function syncPlayerAggregates($leagueId, $limit = 150)
    {
        $rows = $this->db->all(
            'SELECT p.player_id
             FROM players p
             LEFT JOIN squad_players s
                    ON s.player_id = p.player_id AND s.league_id = ?
             WHERE p.agg_synced_at IS NULL
                OR p.agg_synced_at < DATE_SUB(NOW(), INTERVAL 20 HOUR)
             ORDER BY s.player_id IS NULL, p.agg_synced_at IS NOT NULL, p.agg_synced_at ASC
             LIMIT ' . (int) $limit,
            [(string) $leagueId]
        );

        $done = 0;
        foreach ($rows as $row) {
            $playerId = $row['player_id'];
            try {
                $data = $this->kb->playerAggregates($leagueId, $playerId);
            } catch (Exception $ex) {
                $this->log("Aggregate {$playerId} fehlgeschlagen: " . $ex->getMessage());
                // Trotzdem stempeln, sonst blockiert der Spieler jeden Lauf.
                $this->db->run('UPDATE players SET agg_synced_at = NOW() WHERE player_id = ?', [$playerId]);
                continue;
            }

            $data['player_id']     = (string) $playerId;
            $data['updated_at']    = date('Y-m-d H:i:s');
            $data['agg_synced_at'] = date('Y-m-d H:i:s');

            $cols = array_values(array_diff(array_keys($data), ['player_id']));
            $this->db->upsert('players', $data, $cols);
            $done++;
        }

        $this->db->setMeta('aggregates_synced_at', date('c'));
        $this->log("Saison-Aggregate aktualisiert: {$done} Spieler");
        return $done;
    }

    // ------------------------------------------------------------------ Teams

    /**
     * Team-Kuerzel (ID -> "FCB") aus dem Spielplan. Ein Request, landet als
     * JSON in meta - fuer eine Handvoll Kuerzel braucht es keine Tabelle.
     */
    public function syncTeams($competitionId = '1')
    {
        try {
            $res = $this->kb->matchdays($competitionId);
        } catch (Exception $ex) {
            $this->log('Spielplan konnte nicht geladen werden: ' . $ex->getMessage());
            return 0;
        }

        $shorts = [];
        foreach (isset($res['it']) ? $res['it'] : [] as $matchday) {
            foreach (isset($matchday['it']) ? $matchday['it'] : [] as $match) {
                foreach ([['t1', 't1sy'], ['t2', 't2sy']] as $pair) {
                    $id    = pick($match, [$pair[0]]);
                    $short = pick($match, [$pair[1]]);
                    if ($id !== null && $short) {
                        $shorts[(string) $id] = (string) $short;
                    }
                }
            }
        }

        if ($shorts) {
            $this->db->setMeta('team_shorts', $shorts);
        }
        $this->log('Team-Kuerzel: ' . count($shorts));
        return count($shorts);
    }

    // ------------------------------------------------------- Punkte je Spieltag

    /**
     * Holt die Spieltags-Punkte der eigenen Kaderspieler. Das sind nur rund
     * ein Dutzend Requests, laeuft also auch im Standardlauf mit.
     *
     * Der aktuelle Spieltag wird bei jedem Lauf neu geholt, aeltere Spieltage
     * aendern sich nicht mehr.
     */
    public function syncPerformances($leagueId, $seasons = 2)
    {
        // Eigener Kader und alles, was aktuell auf dem Markt steht - ohne
        // Spieltagsdaten waere jede Kaufempfehlung geraten.
        $rows = $this->db->all(
            'SELECT DISTINCT player_id FROM (
                 SELECT player_id FROM squad_players WHERE league_id = ?
                 UNION
                 SELECT player_id FROM market_listings
                  WHERE league_id = ? AND gone_at IS NULL
             ) AS beide',
            [(string) $leagueId, (string) $leagueId]
        );

        $now   = date('Y-m-d H:i:s');
        $done  = 0;
        $saved = 0;

        foreach ($rows as $row) {
            try {
                $matches = $this->kb->playerPerformance($leagueId, $row['player_id'], $seasons);
            } catch (Exception $ex) {
                $this->log("Leistungsdaten {$row['player_id']} fehlgeschlagen: " . $ex->getMessage());
                continue;
            }

            foreach ($matches as $match) {
                $match['updated_at'] = $now;

                $cols = array_values(array_diff(array_keys($match), ['player_id', 'season_id', 'day']));
                $this->db->upsert('player_performances', $match, $cols);
                $saved++;
            }
            $done++;
        }

        $this->db->setMeta('performances_synced_at', date('c'));
        $this->log("Leistungsdaten: {$done} Spieler, {$saved} Spieltage");
        return $done;
    }

    // ----------------------------------------------------- Prognose-Protokoll

    /**
     * Prognosen einfrieren und abgeschlossene Spiele nachtragen.
     *
     * Reine Rechenarbeit auf lokalen Daten, keine API-Requests - laeuft
     * deshalb bei jedem Sync mit. Wichtig ist nur die Reihenfolge: erst
     * aufloesen (die Spieltagsdaten sind gerade frisch geholt), dann die
     * naechste Prognose einfrieren.
     */
    public function logForecasts($leagueId)
    {
        $analyse  = new Analyse($this->db);
        $resolved = $analyse->resolveForecasts();
        $frozen   = $analyse->snapshotForecasts($leagueId);

        $this->log("Prognosen: {$frozen} eingefroren, {$resolved} aufgeloest");
        return $frozen;
    }

    // ------------------------------------------------------------ Aktivitaeten

    public function syncActivities($leagueId, $max = 50)
    {
        try {
            $res = $this->kb->activities($leagueId, 0, $max);
        } catch (Exception $ex) {
            $this->log('Aktivitaeten konnten nicht geladen werden: ' . $ex->getMessage());
            return 0;
        }

        $items = pick($res, ['af', 'it'], []);
        $count = 0;

        foreach ((array) $items as $item) {
            $id = pick($item, ['i', 'id']);
            if (!$id) {
                continue;
            }

            $data     = isset($item['data']) && is_array($item['data']) ? $item['data'] : $item;
            $playerId = pick($data, ['pi', 'i']);

            $this->db->upsert('activities', [
                'activity_id' => (string) $id,
                'league_id'   => (string) $leagueId,
                'type'        => pickInt($item, ['t', 'type']),
                'happened_at' => isoToMysql(pick($item, ['dt', 'date'])),
                'player_id'   => $playerId ? (string) $playerId : null,
                'price'       => pickInt($data, ['trp', 'prc', 'p']),
                'raw'         => json_encode($item, JSON_UNESCAPED_UNICODE),
            ], ['type', 'happened_at', 'player_id', 'price', 'raw']);
            $count++;
        }

        $this->log("Aktivitaeten gespeichert: {$count}");
        return $count;
    }

    // -------------------------------------------------------------- Kompletter Lauf

    public function runAll($leagueId, array $options = [])
    {
        $mvLimit   = isset($options['mv_limit']) ? (int) $options['mv_limit'] : 60;
        $aggLimit  = isset($options['agg_limit']) ? (int) $options['agg_limit'] : 60;
        $timeframe = isset($options['timeframe']) ? (int) $options['timeframe'] : 365;
        $withPlayers = array_key_exists('players', $options) ? (bool) $options['players'] : true;

        $runId = $this->resolveRun($options, 'full');
        try {
            $competitionId = $this->config['kickbase']['competition_id'];

            // Zuerst die Vereine: syncPlayers braucht deren IDs.
            $this->syncTeams($competitionId);
            if ($withPlayers) {
                $this->syncPlayers($competitionId);
                $this->syncTopPlayers($competitionId);
            }
            $this->syncSquad($leagueId);
            $this->syncMarket($leagueId);
            $this->syncActivities($leagueId);
            $this->syncPerformances($leagueId);
            $this->syncPlayerAggregates($leagueId, $aggLimit);
            $this->logForecasts($leagueId);
            $this->syncMarketValues($leagueId, $mvLimit, $timeframe);

            $this->db->setMeta('last_sync', date('c'));
            $this->finishRun($runId, 'ok', null);
        } catch (Exception $ex) {
            $this->finishRun($runId, 'error', $ex->getMessage());
            throw $ex;
        }
    }

    /**
     * Schneller Lauf: nur Transfermarkt und eigener Kader. Wie runAll auch
     * in sync_runs protokolliert, damit der Web-Button einen Status hat.
     */
    public function runMarket($leagueId, array $options = [])
    {
        $runId = $this->resolveRun($options, 'market');
        try {
            $this->syncMarket($leagueId);
            $this->syncSquad($leagueId);
            // Billig und rein lokal - haelt das Protokoll auch bei kurzen
            // Laeufen auf dem letzten Stand.
            $this->logForecasts($leagueId);

            $this->db->setMeta('last_sync', date('c'));
            $this->finishRun($runId, 'ok', null);
        } catch (Exception $ex) {
            $this->finishRun($runId, 'error', $ex->getMessage());
            throw $ex;
        }
    }

    /**
     * Nimmt die Lauf-ID, die der Aufrufer schon angelegt hat (der Web-Button
     * tut das, damit zwischen Klick und Prozessstart keine Luecke entsteht),
     * sonst wird eine neue angelegt.
     */
    private function resolveRun(array $options, $task)
    {
        if (!empty($options['run_id'])) {
            $id = (int) $options['run_id'];
            $this->db->run(
                "UPDATE sync_runs SET task = ?, started_at = NOW(), status = 'running' WHERE id = ?",
                [$task, $id]
            );
            return $id;
        }
        return $this->startRun($task);
    }

    private function startRun($task)
    {
        $this->db->run('INSERT INTO sync_runs (task, started_at, status) VALUES (?, NOW(), ?)', [$task, 'running']);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function finishRun($id, $status, $message)
    {
        $this->db->run(
            'UPDATE sync_runs SET finished_at = NOW(), status = ?, message = ? WHERE id = ?',
            [$status, $message !== null ? substr($message, 0, 2000) : null, $id]
        );
    }
}
