# Kickbase Insights

Lokale Auswertung des eigenen Kickbase-Managerspiels: Kaderübersicht,
Marktwert-Verläufe, Spielervergleich und eine Bewertung der Angebote auf dem
Transfermarkt.

## Wie es funktioniert

Die App spricht **nicht** live mit Kickbase, wenn du eine Seite aufrufst.
Ein Sync-Script holt die Daten in die lokale MySQL-Datenbank, das Frontend
liest nur von dort. Das hält die Seite schnell und die Zahl der API-Requests
klein.

```
bin/sync.php  ──>  api.kickbase.com
      │
      v
   MySQL (kickbase)  ──>  public/*.php
```

Der wichtigste Nebeneffekt: Jeder Lauf schreibt die aktuellen Marktwerte in
`player_market_values`. Nach einigen Wochen hast du damit ein Archiv, das über
das hinausgeht, was die API selbst zurückliefert.

## Voraussetzungen

* **PHP 7.0 oder neuer** mit den Erweiterungen `curl`, `pdo_mysql`, `json`,
  `mbstring` – alle in XAMPP, Laragon, WAMP und jedem Standard-PHP enthalten
* **MySQL oder MariaDB**
* Ein **Webserver**, der auf das Verzeichnis `public/` zeigt (Apache, nginx
  oder für einen schnellen Test der eingebaute PHP-Server)
* Ein **Kickbase-Account mit Passwort**. Wer sich dort mit Google oder Apple
  anmeldet, hat keines und muss sich in der Kickbase-App erst eines setzen –
  sonst antwortet die API mit `AccessDenied`.

`bin/setup.php` prüft das alles und sagt, was fehlt.

## Einrichtung

1. **Projekt ablegen** – irgendein Verzeichnis unter dem Document Root des
   Webservers, zum Beispiel `htdocs/kickbase` bei XAMPP.

2. **Konfiguration anlegen** – die Vorlage kopieren und ausfüllen:

   ```
   copy config.local.php.example config.local.php     (Windows)
   cp    config.local.php.example config.local.php     (Linux, macOS)
   ```

   Darin E-Mail und Passwort deines Kickbase-Accounts eintragen. `league_id`
   leer lassen. Die Datei ist von `.gitignore` ausgeschlossen, deine
   Zugangsdaten landen also nicht im Repository.

3. **Setup ausführen** – prüft die Umgebung, legt Datenbank und Tabellen an,
   testet den Login und zeigt deine Ligen:

   ```
   php bin/setup.php
   ```

   Am Ende nennt es die URL, unter der die App erreichbar ist.

4. **Erstbefüllung** – holt alle Spieler, Marktwert-Historien und
   Leistungsdaten. Dauert 20 Minuten und mehr:

   ```
   php bin/sync.php --full
   ```

   Wer nicht warten will: `php bin/sync.php` (rund 1,5 Minuten) genügt, um
   loszulegen. Der Rest wird bei den folgenden Läufen nachgeholt.

5. **Öffnen** – das Verzeichnis `public/` im Browser, bei einer XAMPP-Ablage
   unter `htdocs/kickbase` also <http://localhost/kickbase/public/>.

### Wenn die Seite nicht erscheint

**Ein anderes Projekt antwortet (fremde 404-Seite).** Sobald in Apache ein
einziger `<VirtualHost *:80>` definiert ist, bedient dessen Eintrag alle
Hostnamen, die auf keinen `ServerName` passen – auch `localhost`. Dann landet
der Aufruf im erstgenannten vhost statt im Document Root. Abhilfe: als
**ersten** vhost einen für `localhost` mit dem Document Root eintragen, oder
einen eigenen vhost für dieses Projekt anlegen, der auf `public/` zeigt.

**Ohne Webserver zum Ausprobieren** genügt der eingebaute:

```
php -S localhost:8080 -t public
```

Dann <http://localhost:8080/> öffnen. Der Sync-Knopf funktioniert dabei
ebenfalls, weil er einen eigenen CLI-Prozess startet.

## Laufender Betrieb

Der bequemste Weg ist der Knopf **Aktualisieren** oben rechts in der App. Er
startet denselben Sync als Hintergrundprozess und zeigt den Fortschritt an;
die Konsole brauchst du dafür nicht. Zur Auswahl stehen dieselben drei
Umfänge wie auf der Kommandozeile:

| Auswahl | entspricht | Dauer |
|---|---|---|
| Transfermarkt | `--market` | ~5 s |
| Standard | ohne Flags | ~1,5 min |
| Alles | `--full` | 15–30 min |

Es läuft immer nur ein Sync; ein zweiter Klick wird abgewiesen, solange der
erste arbeitet. Bricht ein Lauf hart ab, gibt ihn `web_sync.stale_after`
(Standard 45 Minuten) wieder frei. Die Ausgabe des letzten per Knopf
gestarteten Laufs steht in `var/sync-web.log`, der Verlauf in der Tabelle
`sync_runs`.

Abschalten oder anpassen lässt sich das in `config.php` unter `web_sync` –
dort steht auch der Pfad zum PHP-CLI (`php_bin`), mit dem der
Hintergrundprozess gestartet wird.

### Auf der Kommandozeile

| Befehl | Zweck | Dauer |
|---|---|---|
| `php bin/sync.php --market` | nur Transfermarkt und eigener Kader | ~5 s |
| `php bin/sync.php --perf` | Spieltags-Punkte des eigenen Kaders, Team-Kürzel | ~7 s |
| `php bin/sync.php --agg-only` | Saison-Aggregate (Punkte, Einsätze, Spielzeit) | ~0,7 s je Spieler |
| `php bin/sync.php` | Standardlauf: alle Spieler, 60 Marktwert-Historien, 60 Aggregate | ~1,5 min |
| `php bin/sync.php --full` | alles: alle Marktwert-Historien und alle Saison-Aggregate | ab 20 min |
| `php bin/test-api.php` | API-Verbindung prüfen, Rohdaten nach `var/dumps/` | ~10 s |

Sinnvoller Rhythmus: der Standardlauf alle paar Stunden, `--market` häufiger,
wenn du den Markt eng beobachten willst. Kickbase aktualisiert die Marktwerte
einmal täglich (nachts), häufiger als stündlich lohnt sich für die Historie
also nicht.

### Automatisch im Hintergrund

Beide Pfade an die eigene Installation anpassen – `bin/setup.php` nennt den
Pfad zum PHP-CLI.

**Windows** (geplante Aufgabe, alle vier Stunden):

```powershell
schtasks /create /tn "Kickbase Sync" /tr "<PFAD>\php.exe <PROJEKT>\bin\sync.php --quiet" /sc hourly /mo 4
```

**Linux und macOS** (crontab, alle vier Stunden):

```
0 */4 * * * /usr/bin/php <PROJEKT>/bin/sync.php --quiet
```

## Aufbau

```
config.php            Grundeinstellungen
config.local.php      deine Zugangsdaten (nicht im Git)
schema.sql            Datenbankschema
bootstrap.php         lädt Config und Klassen

lib/Kickbase.php      API-Client mit Token-Cache und Drosselung
lib/Db.php            PDO-Wrapper
lib/Sync.php          holt Daten und schreibt sie in die DB
lib/Analyse.php       Auswertungen und Bewertung
lib/WebSync.php       Sync-Knopf: Profile, Prozessstart, Fortschritt
lib/helpers.php       Formatierung, Feldzugriff, Datei-Cache

bin/setup.php         einmalige Einrichtung
bin/sync.php          Datenabgleich (Cron)
bin/test-api.php      Diagnose, schreibt Rohdaten-Dumps

public/index.php      Dashboard mit eigenem Kader
public/lineup.php     Aufstellung planen, erwartete Punkte
public/lineup-save.php speichert die Planung (POST)
public/radar.php      erwartete Marktwert-Entwicklung
public/accuracy.php   Prognose gegen die Wirklichkeit
public/market.php     Transfermarkt mit Bewertung
public/trends.php     Gewinner, Verlierer, Punkte pro Million
public/compare.php    Spielervergleich
public/player.php     Spielerdetail mit Marktwert-Chart
public/sync-start.php startet einen Sync im Hintergrund (POST)
public/sync-status.php Fortschritt als JSON
public/assets/app.js  Sync-Knopf im Browser
```

## Zur Bewertung der Angebote

Der Score auf der Transfermarkt-Seite ist ein **relativer** Rang innerhalb der
aktuell verfügbaren Angebote, kein absolutes Urteil:

* 45 % Preisabschlag gegenüber dem Marktwert
* 35 % Punkte pro Million Marktwert
* 20 % Marktwert-Trend der letzten sieben Tage

Verletzte und gesperrte Spieler werden halbiert. Was der Score bewusst *nicht*
kennt: Aufstellungssituation, Gegnerstärke, Wechselgerüchte. Diese Angaben
stehen auf der Spielerseite und gehören in die eigene Einschätzung.

## Zur Prognose auf der Aufstellungsseite

Erwartete Punkte = **Basis × Einsatzquote × Verfügbarkeit**.

* **Basis** – Punkte je Einsatz: 60 % aus den letzten fünf Einsätzen, 40 % aus
  allen gespeicherten
* **Einsatzquote** – letzte zehn Spieltage; ein Spiel über 60 Minuten zählt
  voll, ein Kurzeinsatz mit 0,4
* **Verfügbarkeit** – angeschlagen 50 %, Aufbautraining 25 %, verletzt oder
  gesperrt 0 %

Grundlage ist `player_performances`, gefüllt von `sync.php --perf`. Zu
Saisonbeginn stammen die Werte zwangsläufig aus der Vorsaison. Wo sie aus der
2. Bundesliga kommen, steht das auf der Spielerkarte – die Punkte sind dann
nicht direkt vergleichbar. Spieler ohne Historie bekommen bewusst keine Zahl.

Nicht enthalten: Gegnerstärke, Heimvorteil, Wechselgerüchte, die
voraussichtliche Vereinsaufstellung.

### Taugt die Prognose etwas?

Die Seite **Prognose** beantwortet das mit Zahlen statt mit Zutrauen.
Jeder Sync friert vor dem Anpfiff die aktuelle Prognose in `forecast_log` ein;
nach dem Spiel werden die tatsächlichen Punkte nachgetragen. Ein aufgelöster
Eintrag wird nie mehr verändert – sonst wäre es keine Prognose.

Kennzahl ist der **mittlere absolute Fehler** in Punkten, und daneben steht
immer derselbe Wert für die stumpfe Rechnung „nimm den Saisonschnitt". Das ist
der Maßstab: Eine Formel, die den Saisonschnitt nicht schlägt, ist ihren
Aufwand nicht wert. Zusätzlich wird der systematische Fehler ausgewiesen –
liegt die Prognose immer zu hoch oder zu tief, ist das leichter zu beheben als
ein zufälliger Fehler.

Einschränkungen, die die Seite auch selbst nennt:

* gemessen wird nur der eigene Kader – rund 13 Prognosen je Spieltag, für
  andere Spieler fehlen die Spieltagsdaten
* wer keinen Saisonschnitt hat (Aufsteiger, Neuzugänge), fällt aus dem
  Vergleich heraus statt ihn zu verfälschen
* unter etwa drei Spieltagen ist das Ergebnis Zufall, und die Seite sagt es

Die eigene Planung liegt in `lineup_plan` und ist von der echten Aufstellung
(`squad_players.lineup_slot`) getrennt – ein Sync überschreibt sie nicht, und
**es wird nichts an Kickbase gesendet**.

## Woher die Punktzahlen kommen

Das ist die Stelle, an der die API leicht in die Irre führt: **dasselbe Feld
`p` bedeutet je Endpunkt etwas anderes.**

| Endpunkt | liefert | Bedeutung von `p` / `mt` |
|---|---|---|
| `/leagues/{id}/squad` | Saison-Aggregate | `p` = Saisonpunkte |
| `/leagues/{id}/market` | Saison-Aggregate | `p` = Saisonpunkte |
| `/leagues/{id}/players/{pid}` | Saison-Aggregate | `tp`, `ap`, `smc` = Einsätze, `sec` = Spielzeit |
| `/competitions/{id}/teams/{tid}/teamprofile` | Vereinskader, Saisonwerte | `ap` = Punkteschnitt |
| `/competitions/{id}/players` | **einen einzelnen Spieltag** | `p` = Punkte in *diesem* Spiel, `mt` = Minuten darin |

Der letzte Endpunkt schrieb früher seine Einzelspiel-Werte in
`total_points`, `avg_points` und `matches`. Dadurch standen in `matches`
Minuten (93–98 statt 3–34), und die Effizienz-Rangliste führte Spieler mit
drei Einsätzen an. Jetzt gilt:

* Einzelspiel-Werte → `last_points`, `last_minutes`
* Saison-Aggregate → `total_points`, `avg_points`, `matches`, `avg_minutes`,
  gefüllt aus dem Spielerdetail (`--agg-only`, ein Request je Spieler)
* `valueRanking()` verlangt eine bekannte Einsatzzahl – Spieler ohne
  Aggregate fallen heraus, statt die Liste zu verfälschen

`avg_minutes` ist die Kickbase-Spielzeit je Einsatz (`sec / smc`) und liegt
normal bei 90–105, weil die Nachspielzeit mitzählt. Bei etwa jedem achten
Spieler kommen aber 150 und mehr heraus – dort umfasst `sec` offenbar mehr
Partien als `smc` zählt. Werte über 130 werden deshalb **nicht** gespeichert;
die Spalte ist ein Anhaltspunkt, keine belastbare Größe. Die Prognose auf der
Aufstellungsseite benutzt sie nicht, sondern die Minuten je Spieltag aus
`player_performances`.

Wer den Sync erweitert: **Feldnamen nie zwischen diesen Endpunkten
übernehmen**, ohne den Wert gegen einen bekannten Spieler zu prüfen.

### Woher die Spielerliste kommt

`/competitions/{id}/players` ist eine **Top-25-Bestenliste je Position**, keine
Spielerliste – sie lässt sich nicht durchblättern (`max`, `limit`, `start`,
`page`, `offset` und jede `sorting` liefern dieselben 25). Damit kamen früher
nur 89 Spieler in die Datenbank, und Trends wie Effizienz-Rangliste bezogen
sich auf einen Bruchteil der Liga.

Die Spielerliste kommt deshalb aus den **Vereinsprofilen**: 18 Requests,
je 22–30 Spieler, zusammen rund 470. Die Vereins-IDs stammen aus dem
Spielplan (`syncTeams`), der deshalb vorher läuft.

Nebeneffekt, der mehr wert ist als die Liste selbst: jeder Lauf schreibt für
**alle** Spieler einen Marktwert-Punkt in `player_market_values`. Die
Marktwert-Trends werden damit belastbar, ohne pro Spieler die Historie
abzufragen.

## Marktwert-Radar

In Kickbase gewinnt man nicht nur über Punkte, sondern über Marktwerte – und
der Marktwert folgt den Punkten. Das ist in den eigenen Daten messbar, und
genau so entsteht die Seite **Radar**: die Punkte-Prognose wird über den
gemessenen Zusammenhang in eine Marktwert-Erwartung übersetzt.

Aus den lokalen Daten gelernt (609 Spiele, wächst mit jedem Spieltag):

| Punkte im Spiel | Marktwert 3 Tage später |
|---|---|
| kein Einsatz | −4,5 % |
| schwach (1–40) | ±0 |
| ok (41–80) | +1,7 % |
| gut (81–140) | +5,7 % |
| stark (141+) | +7,7 % |

Zwei Fallen, die dabei unbedingt vermieden werden müssen – beide sind mir beim
Bauen zuerst untergelaufen:

* **Marktwert-Inflation.** Alle Marktwerte steigen, im Schnitt 1,8 % je drei
  Tage. Wer die rohe Veränderung misst, hält diesen Drift für eine Reaktion auf
  Punkte – dann sieht jeder Spieler nach Gewinn aus, sogar nach einem schwachen
  Spiel. Gemessen wird deshalb der **Abstand zur allgemeinen Marktbewegung**
  desselben Zeitraums.
* **Ausreißer.** Einzelne Sprünge gehen bis über 3000 % (Platzhalter-Marktwerte
  bei Neuzugängen). Änderungen über 50 Prozentpunkte werden verworfen.

Die Erwartung mischt zwei Fälle statt die Prognose stumpf einzusortieren: den
Anteil *ohne* Einsatz mit der Zeile „kein Einsatz", den Anteil *mit* Einsatz
mit der Klasse der Basis. Ein Erwartungswert von 40 Punkten entsteht meist aus
halber Einsatzchance mal 80 Punkten – die Wirklichkeit ist dann 0 oder 80, nie
40.

Grenzen: Der Zusammenhang ist ein Signal, kein Automat – die lineare
Korrelation liegt bei etwa 0,17, einzelne Spieler weichen deutlich ab. Gelernt
wird nur aus Spielern mit Spieltagsdaten (eigener Kader und aktuelle
Marktangebote, siehe `--perf`). Nachfrage innerhalb der eigenen Liga ist nicht
abgebildet. Die gelernte Tabelle wird 30 Minuten zwischengespeichert
(`var/cache`), direkt nach einem Sync kann sie also noch den vorigen Stand
zeigen.

## Hinweise

Die verwendete API ist **inoffiziell** und nicht dokumentiert. Sie kann sich
jederzeit ändern. Endpunkte und Feldnamen stammen aus dem
Community-Projekt [kickbase-api-doc](https://github.com/kevinskyba/kickbase-api-doc).

Der Client drosselt Requests (`request_delay` in `config.php`, Standard 0,4 s)
und cacht das Login-Token in `var/token.json`. Häufige Logins in kurzer Folge
sind das, was auffällt – deshalb nicht bei jedem Seitenaufruf neu einloggen.

Die App steht unter der MIT-Lizenz (siehe `LICENSE`) und hat nichts mit
Kickbase zu tun – sie ist ein privates Auswertungswerkzeug.

Dein Kickbase-Passwort liegt im Klartext in `config.local.php`. Das ist für
eine lokale Installation vertretbar. Sollte die App jemals öffentlich
erreichbar sein, muss das anders gelöst werden: Login pro Nutzer im Browser,
nur das Token in der Session, kein Passwort auf dem Server.
