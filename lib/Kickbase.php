<?php
/**
 * Client fuer die inoffizielle Kickbase-API v4.
 *
 * Wichtig: Das Token wird in var/token.json gecached. Nicht bei jedem
 * Seitenaufruf neu einloggen - haeufige Logins sind das, was Accounts
 * auffaellig macht.
 */
class Kickbase
{
    /** Positions-IDs der API */
    const POS_TW  = 1;
    const POS_ABW = 2;
    const POS_MF  = 3;
    const POS_ST  = 4;

    private $email;
    private $password;
    private $baseUrl;
    private $userAgent;
    private $tokenFile;
    private $delay;

    private $token = null;
    private $tokenExpires = 0;
    private $lastRequestAt = 0.0;

    /** @var array letzte Roh-Antwort, hilfreich beim Debuggen */
    public $lastRaw = null;

    public function __construct(array $cfg, $tokenFile = null)
    {
        $this->email     = $cfg['email'];
        $this->password  = $cfg['password'];
        $this->baseUrl   = rtrim($cfg['base_url'], '/');
        $this->userAgent = $cfg['user_agent'];
        $this->delay     = isset($cfg['request_delay']) ? (float) $cfg['request_delay'] : 0.4;
        $this->tokenFile = $tokenFile !== null ? $tokenFile : dirname(__DIR__) . '/var/token.json';

        $this->loadToken();
    }

    // ---------------------------------------------------------------- Auth

    private function loadToken()
    {
        if (!is_file($this->tokenFile)) {
            return;
        }
        $data = json_decode((string) file_get_contents($this->tokenFile), true);
        if (!is_array($data) || empty($data['token'])) {
            return;
        }
        // Nur uebernehmen, wenn das Token zum aktuell konfigurierten Account gehoert.
        if (isset($data['email']) && $data['email'] !== $this->email) {
            return;
        }
        if (isset($data['expires']) && $data['expires'] < time() + 300) {
            return; // laeuft gleich ab -> lieber neu holen
        }
        $this->token        = $data['token'];
        $this->tokenExpires = isset($data['expires']) ? (int) $data['expires'] : 0;
    }

    private function storeToken()
    {
        $dir = dirname($this->tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->tokenFile, json_encode([
            'email'   => $this->email,
            'token'   => $this->token,
            'expires' => $this->tokenExpires,
        ]));
    }

    /**
     * Loggt ein und cacht das Token. Gibt die Login-Antwort zurueck.
     */
    public function login()
    {
        if ($this->email === '' || $this->password === '') {
            throw new RuntimeException('Kickbase-Zugangsdaten fehlen. Trage sie in config.local.php ein.');
        }

        $res = $this->request('POST', '/v4/user/login', null, [
            'em'   => $this->email,
            'pass' => $this->password,
            // Die App sendet diese Felder mit; ohne sie antwortet die API teils mit 400.
            'loy'  => false,
            'rep'  => new stdClass(),
        ], false);

        $token = null;
        foreach (['tkn', 'token', 'accessToken'] as $key) {
            if (!empty($res[$key])) {
                $token = $res[$key];
                break;
            }
        }
        if ($token === null) {
            throw new RuntimeException('Login-Antwort enthaelt kein Token. Antwort: ' . substr(json_encode($res), 0, 300));
        }

        $this->token = $token;
        $this->tokenExpires = time() + 86400 * 7;
        foreach (['tknex', 'tokenExp', 'exp'] as $key) {
            if (!empty($res[$key]) && is_string($res[$key])) {
                $ts = strtotime($res[$key]);
                if ($ts) {
                    $this->tokenExpires = $ts;
                }
                break;
            }
        }

        $this->storeToken();
        return $res;
    }

    private function ensureToken()
    {
        if ($this->token === null || ($this->tokenExpires > 0 && $this->tokenExpires < time() + 60)) {
            $this->login();
        }
    }

    // ------------------------------------------------------------ Requests

    /**
     * @param string     $method  GET/POST
     * @param string     $path    z.B. /v4/leagues/123/squad
     * @param array|null $query
     * @param array|null $body
     * @param bool       $auth    false nur beim Login
     */
    public function request($method, $path, array $query = null, array $body = null, $auth = true, $retry = true)
    {
        if ($auth) {
            $this->ensureToken();
        }
        $this->throttle();

        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: ' . $this->userAgent,
        ];
        if ($auth && $this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Netzwerkfehler bei {$method} {$path}: {$err}");
        }

        $this->lastRaw = $raw;

        // Token abgelaufen -> einmal neu einloggen und wiederholen
        if ($code === 401 && $auth && $retry) {
            $this->token = null;
            $this->login();
            return $this->request($method, $path, $query, $body, $auth, false);
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("HTTP {$code} bei {$method} {$path}: " . substr($raw, 0, 300));
        }

        $data = json_decode($raw, true);
        if ($data === null && trim($raw) !== '') {
            throw new RuntimeException("Ungueltiges JSON bei {$method} {$path}: " . substr($raw, 0, 200));
        }

        return is_array($data) ? $data : [];
    }

    private function throttle()
    {
        if ($this->delay <= 0) {
            return;
        }
        $wait = ($this->lastRequestAt + $this->delay) - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $this->lastRequestAt = microtime(true);
    }

    public function get($path, array $query = null)
    {
        return $this->request('GET', $path, $query);
    }

    // ----------------------------------------------------------- Endpoints

    /** Alle Ligen des eingeloggten Users. */
    public function leagues()
    {
        $res = $this->get('/v4/leagues/selection');
        return isset($res['it']) ? $res['it'] : [];
    }

    /** Eigener Kader in einer Liga. */
    public function squad($leagueId)
    {
        $res = $this->get('/v4/leagues/' . rawurlencode($leagueId) . '/squad');
        return isset($res['it']) ? $res['it'] : [];
    }

    /** Budget und Teamwert. */
    public function budget($leagueId)
    {
        return $this->get('/v4/leagues/' . rawurlencode($leagueId) . '/me/budget');
    }

    /** Aktueller Transfermarkt inklusive Metadaten (Ablauf, Spieltag). */
    public function market($leagueId)
    {
        return $this->get('/v4/leagues/' . rawurlencode($leagueId) . '/market');
    }

    /** Spielerdetails im Liga-Kontext (inkl. kommender Spiele). */
    public function player($leagueId, $playerId)
    {
        return $this->get('/v4/leagues/' . rawurlencode($leagueId) . '/players/' . rawurlencode($playerId));
    }

    /**
     * Marktwert-Verlauf.
     * @param int $timeframe 92 (3 Monate) oder 365 (1 Jahr)
     * @return array Liste aus ['date' => 'Y-m-d', 'value' => int]
     */
    public function marketValueHistory($leagueId, $playerId, $timeframe = 365)
    {
        $res = $this->get(sprintf(
            '/v4/leagues/%s/players/%s/marketValue/%d',
            rawurlencode($leagueId), rawurlencode($playerId), (int) $timeframe
        ));

        $out = [];
        foreach (isset($res['it']) ? $res['it'] : [] as $point) {
            if (!isset($point['dt'], $point['mv'])) {
                continue;
            }
            // dt ist die Anzahl Tage seit dem 01.01.1970.
            $out[] = [
                'date'  => gmdate('Y-m-d', ((int) $point['dt']) * 86400),
                'value' => (int) $point['mv'],
            ];
        }
        return $out;
    }

    /**
     * Saison-Aggregate eines Spielers aus dem Spielerdetail.
     *
     * Das ist die verlaessliche Quelle fuer Punkte, Einsaetze und Spielzeit.
     * Die Wettbewerbs-Spielerliste (competitionPlayers) liefert stattdessen
     * Werte eines einzelnen Spieltags - wer die als Saisonwerte speichert,
     * bekommt Effizienz-Ranglisten voller Eintagsfliegen.
     *
     * @return array Felder passend zur Tabelle players (nur die bekannten).
     */
    public function playerAggregates($leagueId, $playerId)
    {
        $res = $this->player($leagueId, $playerId);

        $out = [];

        $map = [
            'total_points' => ['tp'],
            'avg_points'   => ['ap'],
            'matches'      => ['smc'],
            'goals'        => ['g'],
            'assists'      => ['a'],
            'position'     => ['pos'],
            'status'       => ['st'],
            'shirt_number' => ['shn'],
        ];
        foreach ($map as $col => $keys) {
            $val = pickInt($res, $keys);
            if ($val !== null) {
                $out[$col] = $val;
            }
        }

        $teamName = pick($res, ['tn']);
        if ($teamName !== null && $teamName !== '') {
            $out['team_name'] = $teamName;
        }
        $teamId = pick($res, ['tid']);
        if ($teamId !== null && $teamId !== '') {
            $out['team_id'] = (string) $teamId;
        }

        // sec ist die Spielzeit in Sekunden. Geteilt durch die Einsaetze ergibt
        // das meist 90 bis 105 Minuten (Kickbase zaehlt die Nachspielzeit mit),
        // bei etwa jedem achten Spieler aber auch 150 und mehr - dann umfasst
        // sec offenbar mehr Partien als smc zaehlt. Solche Werte sind nicht
        // interpretierbar und werden deshalb nicht gespeichert.
        $seconds = pickInt($res, ['sec']);
        $games   = isset($out['matches']) ? (int) $out['matches'] : 0;
        if ($seconds !== null && $games > 0) {
            $minutes = (int) round($seconds / 60 / $games);
            if ($minutes > 0 && $minutes <= 130) {
                $out['avg_minutes'] = $minutes;
            }
        }

        return $out;
    }

    /**
     * Punkte je Spieltag, nach Saison gruppiert. Grundlage fuer Form und
     * Einsatzquote - der Rest der App kennt nur Saison-Summen.
     *
     * Kommende Spiele stehen mit drin, dann ohne Punkte und mit mdst = 0.
     *
     * @param int $seasons Nur die n juengsten Saisons zurueckgeben (0 = alle).
     * @return array Liste aus flachen Zeilen, siehe player_performances.
     */
    public function playerPerformance($leagueId, $playerId, $seasons = 2)
    {
        $res = $this->get(sprintf(
            '/v4/leagues/%s/players/%s/performance',
            rawurlencode($leagueId), rawurlencode($playerId)
        ));

        $all = isset($res['it']) ? $res['it'] : [];
        if ($seasons > 0 && count($all) > $seasons) {
            $all = array_slice($all, -$seasons);
        }

        $out = [];
        foreach ($all as $season) {
            $sid = (string) pick($season, ['sid'], '');
            if ($sid === '') {
                continue;
            }
            foreach (isset($season['ph']) ? $season['ph'] : [] as $match) {
                $day = pickInt($match, ['day']);
                if ($day === null) {
                    continue;
                }
                // mp kommt als "100'" - die Zahl davor ist die Einsatzzeit.
                $minutes = null;
                if (isset($match['mp']) && $match['mp'] !== '') {
                    $minutes = (int) preg_replace('/[^0-9]/', '', (string) $match['mp']);
                }

                $out[] = [
                    'player_id'   => (string) $playerId,
                    'season_id'   => $sid,
                    'season_name' => pick($season, ['ti']),
                    // Die API liefert auch Zweitliga-Saisons - der Wettbewerb
                    // gehoert mit in die Daten, sonst sind die Punkte nicht
                    // einzuordnen.
                    'competition' => pick($season, ['n']),
                    'day'         => $day,
                    'points'      => pickInt($match, ['p']),
                    'minutes'     => $minutes,
                    'match_date'  => isoToMysql(pick($match, ['md'])),
                    'team_home'   => pick($match, ['t1']),
                    'team_away'   => pick($match, ['t2']),
                    'goals_home'  => pickInt($match, ['t1g']),
                    'goals_away'  => pickInt($match, ['t2g']),
                    'own_team'    => pick($match, ['pt']),
                    'match_state' => pickInt($match, ['mdst']),
                ];
            }
        }
        return $out;
    }

    /**
     * Kader eines Vereins samt Vereinsname.
     *
     * Das ist der einzige gefundene Weg zu allen Spielern der Liga: 18
     * Requests, je rund 29 Spieler. Die Wettbewerbs-Spielerliste
     * (competitionPlayers) ist dagegen eine Top-25-Bestenliste und laesst
     * sich nicht durchblaettern.
     *
     * Die Werte sind Saisonwerte (ap = Punkteschnitt), keine Einzelspiel-Daten.
     *
     * @return array ['team_id', 'team_name', 'players' => [...]]
     */
    public function teamProfile($competitionId, $teamId)
    {
        $res = $this->get(sprintf(
            '/v4/competitions/%s/teams/%s/teamprofile',
            rawurlencode($competitionId), rawurlencode($teamId)
        ));

        return [
            'team_id'   => (string) pick($res, ['tid'], $teamId),
            'team_name' => pick($res, ['tn']),
            'players'   => isset($res['it']) ? $res['it'] : [],
        ];
    }

    /**
     * Top-25-Bestenliste je Position.
     *
     * ACHTUNG: liefert Werte eines einzelnen Spieltags - p sind die Punkte in
     * diesem Spiel, mt die Minuten darin. Nicht als Saisonwerte speichern.
     *
     * @param string $position '' = alle, sonst 1..4
     * @param string $sorting  '' = Standard
     */
    public function competitionPlayers($competitionId = '1', $position = '', $sorting = '')
    {
        $res = $this->get('/v4/competitions/' . rawurlencode($competitionId) . '/players', [
            'position' => $position,
            'sorting'  => $sorting,
        ]);
        return isset($res['it']) ? $res['it'] : [];
    }

    /**
     * Spielplan des Wettbewerbs. Enthaelt pro Spiel die Team-Kuerzel, aus
     * denen sich Team-IDs benennen lassen.
     */
    public function matchdays($competitionId = '1')
    {
        return $this->get('/v4/competitions/' . rawurlencode($competitionId) . '/matchdays');
    }

    /** Aktivitaeten der Liga (Kaeufe, Verkaeufe der Mitspieler). */
    public function activities($leagueId, $start = 0, $max = 25)
    {
        return $this->get('/v4/leagues/' . rawurlencode($leagueId) . '/activitiesFeed', [
            'start' => $start,
            'max'   => $max,
        ]);
    }

    // ------------------------------------------------------------- Mapping

    public static function positionName($pos)
    {
        $map = [1 => 'TW', 2 => 'ABW', 3 => 'MF', 4 => 'ST'];
        $pos = (int) $pos;
        return isset($map[$pos]) ? $map[$pos] : '?';
    }

    public static function statusName($status)
    {
        $map = [
            0  => 'Fit',
            1  => 'Verletzt',
            2  => 'Angeschlagen',
            4  => 'Aufbautraining',
            8  => 'Gesperrt',
            16 => 'Abwesend',
        ];
        $status = (int) $status;
        return isset($map[$status]) ? $map[$status] : 'Status ' . $status;
    }
}
