-- Schema fuer die Kickbase-Auswertung.
-- Wird von bin/setup.php ausgefuehrt; kann auch manuell eingespielt werden.

CREATE TABLE IF NOT EXISTS players (
    player_id      VARCHAR(16)  NOT NULL,
    first_name     VARCHAR(100) NULL,
    last_name      VARCHAR(100) NULL,
    known_name     VARCHAR(100) NULL,
    team_id        VARCHAR(16)  NULL,
    team_name      VARCHAR(100) NULL,
    position       TINYINT      NULL,
    -- Kickbase-Statuscode, keine fortlaufende Nummer sondern Bit-Flags
    -- (1 verletzt, 2 angeschlagen, 4 Aufbautraining, ... 128). TINYINT
    -- reicht dafuer nicht, 128 laeuft ueber.
    status         SMALLINT     NULL,
    shirt_number   SMALLINT     NULL,
    market_value   BIGINT       NULL,
    mv_trend       TINYINT      NULL,
    -- Saison-Aggregate. Kommen aus dem Squad- bzw. Spielerdetail-Endpunkt.
    -- NICHT aus /competitions/{id}/players - der liefert Werte eines einzelnen
    -- Spieltags, die hier frueher als Saisonwerte gelandet sind.
    total_points   INT          NULL,
    avg_points     INT          NULL,
    matches        INT          NULL,   -- Einsaetze in der Saison (smc)
    avg_minutes    SMALLINT     NULL,   -- Minuten je Einsatz (sec / smc)
    goals          INT          NULL,
    assists        INT          NULL,
    -- Letzter erfasster Spieltag, aus der Wettbewerbs-Spielerliste.
    last_points    INT          NULL,
    last_minutes   SMALLINT     NULL,
    image          VARCHAR(255) NULL,
    updated_at     DATETIME     NOT NULL,
    -- Wann zuletzt die Marktwert-Historie dieses Spielers geholt wurde.
    mv_synced_at   DATETIME     NULL,
    -- Wann zuletzt die Saison-Aggregate geholt wurden.
    agg_synced_at  DATETIME     NULL,
    PRIMARY KEY (player_id),
    KEY idx_players_mvsync (mv_synced_at),
    KEY idx_players_aggsync (agg_synced_at),
    KEY idx_players_team (team_id),
    KEY idx_players_pos (position),
    KEY idx_players_mv (market_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marktwert-Historie. Speist sich aus der API-Historie und aus jedem Sync.
-- Das ist das Archiv, das dir mit der Zeit einen Vorsprung verschafft.
CREATE TABLE IF NOT EXISTS player_market_values (
    player_id    VARCHAR(16) NOT NULL,
    day          DATE        NOT NULL,
    market_value BIGINT      NOT NULL,
    PRIMARY KEY (player_id, day),
    KEY idx_pmv_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dein eigener Kader je Liga.
CREATE TABLE IF NOT EXISTS squad_players (
    league_id    VARCHAR(16) NOT NULL,
    player_id    VARCHAR(16) NOT NULL,
    market_value BIGINT      NULL,
    mv_gain      BIGINT      NULL,   -- Gewinn/Verlust seit Kauf (mvgl)
    day_change   BIGINT      NULL,   -- Veraenderung seit gestern (sdmvt)
    lineup_slot  TINYINT     NULL,
    on_market    TINYINT     NOT NULL DEFAULT 0,
    offer_count  INT         NULL,
    updated_at   DATETIME    NOT NULL,
    PRIMARY KEY (league_id, player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transfermarkt-Angebote. Ein Eintrag pro Listing, mit Lebensdauer.
CREATE TABLE IF NOT EXISTS market_listings (
    id           BIGINT       NOT NULL AUTO_INCREMENT,
    league_id    VARCHAR(16)  NOT NULL,
    player_id    VARCHAR(16)  NOT NULL,
    price        BIGINT       NULL,
    market_value BIGINT       NULL,
    seller_id    VARCHAR(32)  NULL,
    seller_name  VARCHAR(100) NULL,
    expires_at   DATETIME     NULL,
    offer_count  INT          NULL,
    first_seen   DATETIME     NOT NULL,
    last_seen    DATETIME     NOT NULL,
    gone_at      DATETIME     NULL,
    raw          JSON         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_listing (league_id, player_id, first_seen),
    KEY idx_listing_open (league_id, gone_at),
    KEY idx_listing_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liga-Aktivitaeten (Kaeufe/Verkaeufe der Mitspieler).
CREATE TABLE IF NOT EXISTS activities (
    activity_id VARCHAR(32) NOT NULL,
    league_id   VARCHAR(16) NOT NULL,
    type        INT         NULL,
    happened_at DATETIME    NULL,
    player_id   VARCHAR(16) NULL,
    price       BIGINT      NULL,
    raw         JSON        NULL,
    PRIMARY KEY (activity_id),
    KEY idx_act_league (league_id, happened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Protokoll der Sync-Laeufe.
CREATE TABLE IF NOT EXISTS sync_runs (
    id          BIGINT       NOT NULL AUTO_INCREMENT,
    task        VARCHAR(50)  NOT NULL,
    started_at  DATETIME     NOT NULL,
    finished_at DATETIME     NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'running',
    message     TEXT         NULL,
    PRIMARY KEY (id),
    KEY idx_sync_task (task, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Punkte je Spieltag. Basis fuer Form und Einsatzquote - die API liefert das
-- pro Spieler und Saison, Zeilen ohne Punkte sind Spiele ohne Einsatz.
CREATE TABLE IF NOT EXISTS player_performances (
    player_id   VARCHAR(16) NOT NULL,
    season_id   VARCHAR(16) NOT NULL,
    day         SMALLINT    NOT NULL,
    -- Wettbewerb und Saison im Klartext. Wichtig, weil die API auch Zweitliga-
    -- Spielzeiten liefert und 90 Punkte dort nicht 90 in der Bundesliga sind.
    competition VARCHAR(60)  NULL,
    season_name VARCHAR(20)  NULL,
    points      INT         NULL,       -- NULL = nicht gespielt
    minutes     SMALLINT    NULL,
    match_date  DATETIME    NULL,
    team_home   VARCHAR(16) NULL,
    team_away   VARCHAR(16) NULL,
    goals_home  TINYINT     NULL,
    goals_away  TINYINT     NULL,
    own_team    VARCHAR(16) NULL,
    match_state TINYINT     NULL,       -- 0 = noch nicht gespielt, 2 = beendet
    updated_at  DATETIME    NOT NULL,
    PRIMARY KEY (player_id, season_id, day),
    KEY idx_perf_season (season_id, day),
    KEY idx_perf_date (match_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Selbst geplante Aufstellung. Bewusst getrennt von squad_players.lineup_slot,
-- das die echte Aufstellung aus Kickbase enthaelt - so bleiben beide
-- vergleichbar und ein Sync ueberschreibt die Planung nicht.
CREATE TABLE IF NOT EXISTS lineup_plan (
    league_id  VARCHAR(16) NOT NULL,
    player_id  VARCHAR(16) NOT NULL,
    slot       TINYINT     NULL,        -- 0..10 = Startelf, NULL = Bank
    updated_at DATETIME    NOT NULL,
    PRIMARY KEY (league_id, player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eingefrorene Prognosen, um sie hinterher an der Wirklichkeit zu messen.
--
-- Ein Eintrag entsteht, solange das Spiel noch nicht angepfiffen ist, und wird
-- bis zum Anpfiff aktualisiert. Danach ist er unveraenderlich - sonst waere es
-- keine Prognose mehr. actual_points kommt nach dem Spiel aus
-- player_performances, baseline_points ist die stumpfe Vergleichsrechnung
-- (einfach der Saisonschnitt), gegen die sich die Formel beweisen muss.
CREATE TABLE IF NOT EXISTS forecast_log (
    player_id       VARCHAR(16) NOT NULL,
    season_id       VARCHAR(16) NOT NULL,
    day             SMALLINT    NOT NULL,
    match_date      DATETIME    NULL,
    forecast_points DECIMAL(7,2) NULL,
    baseline_points DECIMAL(7,2) NULL,
    -- Die Bausteine der Prognose, um spaeter zu sehen, welcher Teil daneben lag.
    base            DECIMAL(7,2) NULL,
    start_rate      DECIMAL(4,3) NULL,
    availability    DECIMAL(4,3) NULL,
    opponent        DECIMAL(4,3) NULL,   -- Gegner-Faktor, NULL = keine Quote
    actual_points   INT          NULL,
    actual_minutes  SMALLINT     NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    resolved_at     DATETIME     NULL,
    PRIMARY KEY (player_id, season_id, day),
    KEY idx_fc_open (resolved_at),
    KEY idx_fc_day (season_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Die Mitspieler der Liga.
CREATE TABLE IF NOT EXISTS managers (
    league_id   VARCHAR(16)  NOT NULL,
    manager_id  VARCHAR(32)  NOT NULL,
    name        VARCHAR(100) NULL,
    place       SMALLINT     NULL,
    points      INT          NULL,
    -- Summe der Kader-Marktwerte. Bewusst selbst gerechnet: das tv aus der
    -- Rangliste ist ein aelterer Stand und weicht ab, sobald jemand handelt.
    team_value  BIGINT       NULL,
    squad_size  SMALLINT     NULL,
    on_market   SMALLINT     NULL,   -- wie viele davon gerade angeboten sind
    is_me       TINYINT      NOT NULL DEFAULT 0,
    image       VARCHAR(255) NULL,
    updated_at  DATETIME     NOT NULL,
    PRIMARY KEY (league_id, manager_id),
    KEY idx_mgr_place (league_id, place)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wer besitzt welchen Spieler.
--
-- Der Primaerschluessel ist (league_id, player_id), nicht (league_id,
-- manager_id, player_id): ein Spieler gehoert je Liga hoechstens einem
-- Manager. Diese Spielregel steht damit in der Datenbank und nicht nur in
-- der Anwendung - ein Wechsel aktualisiert die Zeile, statt eine zweite
-- anzulegen.
CREATE TABLE IF NOT EXISTS manager_players (
    league_id    VARCHAR(16) NOT NULL,
    manager_id   VARCHAR(32) NOT NULL,
    player_id    VARCHAR(16) NOT NULL,
    market_value BIGINT      NULL,
    mv_gain      BIGINT      NULL,   -- Gewinn seit Kauf (mvgl)
    day_change   BIGINT      NULL,   -- Veraenderung seit gestern (sdmvt)
    lineup_slot  SMALLINT    NULL,
    on_market    TINYINT     NOT NULL DEFAULT 0,
    updated_at   DATETIME    NOT NULL,
    PRIMARY KEY (league_id, player_id),
    KEY idx_mp_manager (league_id, manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Spielplan des Wettbewerbs, ein Eintrag je Partie.
--
-- Der Grund, warum das eine eigene Tabelle ist und nicht nur ein Abruf bei
-- Bedarf: die Wettquoten. Kickbase liefert sie nur fuer die naechsten beiden
-- Spieltage und wirft sie danach weg. Wer sie nicht mitschreibt, kann
-- hinterher nie pruefen, ob die Erwartung vor dem Spiel etwas taugte.
CREATE TABLE IF NOT EXISTS matches (
    match_id       VARCHAR(16)  NOT NULL,
    competition_id VARCHAR(16)  NOT NULL,
    day            SMALLINT     NOT NULL,
    kickoff        DATETIME     NULL,
    team_home      VARCHAR(16)  NULL,
    team_away      VARCHAR(16)  NULL,
    home_short     VARCHAR(8)   NULL,
    away_short     VARCHAR(8)   NULL,
    goals_home     TINYINT      NULL,
    goals_away     TINYINT      NULL,
    state          TINYINT      NULL,   -- 0 = nicht angepfiffen
    -- Dezimalquoten des Buchmachers. 6,2 reicht bis 9999,99.
    odds_home      DECIMAL(6,2) NULL,
    odds_draw      DECIMAL(6,2) NULL,
    odds_away      DECIMAL(6,2) NULL,
    odds_seen_at   DATETIME     NULL,   -- wann die Quoten zuletzt geliefert wurden
    updated_at     DATETIME     NOT NULL,
    PRIMARY KEY (match_id),
    KEY idx_matches_day (competition_id, day),
    KEY idx_matches_kickoff (kickoff),
    KEY idx_matches_home (team_home),
    KEY idx_matches_away (team_away)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kleiner Key-Value-Speicher (letzter Sync, Budget, Teamwert, ...).
CREATE TABLE IF NOT EXISTS meta (
    k VARCHAR(64) NOT NULL,
    v TEXT        NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- Migrationen
-- CREATE TABLE IF NOT EXISTS aendert bestehende Tabellen nicht. Was sich
-- nachtraeglich an einer Spalte aendert, gehoert deshalb hier hin. Die
-- Statements muessen sich beliebig oft wiederholen lassen.

-- players.status war TINYINT. Der Kickbase-Statuscode 128 passt da nicht
-- hinein - der Sync brach mit "Out of range value for column 'status'" ab.
ALTER TABLE players MODIFY status SMALLINT NULL;

-- forecast_log kennt seit dem Gegner-Faktor einen vierten Baustein.
-- MySQL kann kein ADD COLUMN IF NOT EXISTS; beim zweiten Setup-Lauf meldet
-- das hier "Duplicate column" (1060). bin/setup.php laesst genau diese
-- Fehlercodes im Migrations-Abschnitt durchgehen.
ALTER TABLE forecast_log ADD COLUMN opponent DECIMAL(4,3) NULL AFTER availability;
