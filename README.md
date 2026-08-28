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

### Nach einem Update

`schema.sql` hat am Dateiende einen Migrations-Abschnitt. Neue Tabellen und
Spalten kommen dort an, und `bin/setup.php` spielt sie ein:

```
php bin/setup.php
```

Das ist wiederholbar – schon vorhandene Spalten werden übersprungen. Wer es
auslässt, bekommt beim nächsten Sync oder Seitenaufruf einen SQL-Fehler über
eine fehlende Tabelle oder Spalte, keinen stillen Datenverlust.

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
| `php bin/sync.php --market` | Transfermarkt, Kader, Liga-Aktivitäten, Spielplan | ~5 s |
| `php bin/sync.php --perf` | Spieltags-Punkte des eigenen Kaders, Team-Kürzel | ~7 s |
| `php bin/sync.php --agg-only` | Saison-Aggregate (Punkte, Einsätze, Spielzeit) | ~0,7 s je Spieler |
| `php bin/sync.php` | Standardlauf: alle Spieler, Mitspieler-Kader, 60 Marktwert-Historien, 60 Aggregate | ~1,5 min |
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
public/managers.php   Konkurrenz: Kader der Mitspieler, freie Spieler
public/activity.php   Liga-Aktivitäten: Transfers der Mitspieler
public/schedule.php   Spielplan mit Siegchancen und Gegner-Faktor
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

## Konkurrenzanalyse

Die Seite **Konkurrenz** holt, was der eigenen Auswertung am meisten
gefehlt hat: den Kader jedes Mitspielers. Ein Request für die Rangliste,
dann einer je Manager – bei sechs Mitspielern also sieben. Das läuft im
Standardlauf mit, nicht im schnellen `--market`.

Der eigentliche Ertrag ist nicht die Tabelle, sondern die Umkehrung:
**welche guten Spieler gehören noch niemandem.** In Kickbase gehört ein
Spieler je Liga höchstens einem Manager – was in der Liste „Noch frei"
steht, ist tatsächlich zu haben, sobald es auf dem Markt erscheint. Diese
Regel steht auch in der Datenbank: `manager_players` hat den
Primärschlüssel `(league_id, player_id)`, nicht `(…, manager_id, …)`.

Zwei Dinge, die die Seite bewusst **nicht** behauptet:

* **Kein Budget.** Die API gibt den Kontostand nur für den eigenen
  Account heraus. Naheliegend wäre, ihn aus `ranking.tv` minus Kaderwert
  abzuleiten – das ist geprüft und falsch: `ranking.tv` ist schlicht ein
  älterer Stand und weicht genau bei den Managern ab, die zuletzt
  gehandelt haben. Beim eigenen Account ist die Differenz null, obwohl
  Budget vorhanden ist. Damit ist die Hypothese widerlegt, und es steht
  hier kein geschätzter Kontostand.
* **Kein „Max-Gebot".** Folgt direkt aus dem fehlenden Budget.

Der Teamwert wird aus den Marktwerten selbst summiert statt aus
`ranking.tv` übernommen – aus demselben Grund. Gegengeprüft: für den
eigenen Account stimmt die Summe auf den Euro mit dem bekannten Teamwert
überein.

Die eigene Manager-ID steht in keiner Antwort. Sie wird über die
Überschneidung mit dem eigenen Kader bestimmt – wessen Aufgebot dieselben
Spieler enthält wie `squad_players`, das sind wir.

## Liga-Aktivitäten

Die Seite **Liga** wertet den Aktivitäten-Feed aus: wer kauft und verkauft,
zu welchem Preis, und wie der sich zum Marktwert desselben Tages verhält.

Kickbase liefert diese Einträge **unbeschriftet** – jeder trägt nur eine
Typ-Nummer, keine Erklärung. Was die Nummern bedeuten, ist aus den eigenen
Daten abgeleitet und dort nachprüfbar:

| Typ | Bedeutung | Woran das hängt |
|---|---|---|
| 15 | Transfer | `data.t` = 1 Kauf mit `byr` (Käufer), = 2 Verkauf mit `slr` (Verkäufer). Gilt ausnahmslos für alle erfassten Zeilen. |
| 3 | Kickbase stellt einen Spieler auf den Markt | 34 von 34 dieser Meldungen tauchten danach als Angebot auf, alle mit Kickbase als Verkäufer – und die Angebote von Mitspielern hatten nie eine solche Meldung davor. |
| 22 | Tagesbonus | `data.bn` = Betrag, `data.day` = Spieltag |

Die interessanteste Spalte ist der **Aufschlag**: der gezahlte Preis
gegenüber dem Marktwert am selben Tag. Wer regelmäßig weit über Marktwert
kauft, treibt die Preise – und das sieht man hier je Mitspieler.

Zwei Grenzen, die die Seite auch selbst nennt:

* Der Feed reicht nur so weit zurück, wie synchronisiert wurde. Kickbase gibt
  nur die jüngsten Einträge heraus, ältere sind nur da, wenn damals schon ein
  Sync lief. Deshalb läuft `syncActivities()` bei **jedem** `--market` mit und
  nicht nur im vollen Lauf.
* Bei „gekauft und wieder verkauft" zählen nur Paare, die beide im Zeitraum
  liegen. Ein Verkauf ohne bekannten Kauf fällt heraus, statt mit einem
  geratenen Einstandspreis als Fund zu gelten.

Der Saldo je Manager ist reine Kassenrechnung (Verkäufe minus Käufe). Was ein
noch nicht verkaufter Spieler wert ist, steht bewusst nicht darin – dafür
fehlen die Kaderdaten der Mitspieler.

## Spielplan und Gegner-Faktor

Der Spielplan-Endpunkt liefert nebenbei etwas, das man leicht übersieht:
**Wettquoten** (`bo` mit `o1`/`ox`/`o2`). Genau dort steckt die Gegnerstärke,
und zwar samt Heimvorteil und Formkurve, weil der Buchmacher das schon
eingepreist hat.

Der Haken: Kickbase liefert die Quoten nur für die **nächsten beiden
Spieltage** und wirft sie danach weg. Deshalb gibt es die Tabelle `matches`,
deshalb läuft `syncSchedule()` bei jedem `--market` mit, und deshalb werden
Quoten **nie mit `NULL` überschrieben** – einmal gesehen, für immer da. Das
ist dieselbe Idee wie beim Marktwert-Archiv.

Aus den Quoten werden Wahrscheinlichkeiten, bereinigt um die Marge des
Buchmachers (rund 7 %) – ohne diese Normierung ergäben die Kehrwerte
zusammen über 100 %.

Wie stark Kickbase-Punkte am Spielausgang hängen, ist **gemessen**, nicht
angenommen – an den eigenen Spieltagsdaten, nur Bundesliga:

| Ausgang | Einsätze | Ø Punkte | Faktor |
|---|---|---|---|
| Sieg | 351 | 109,1 | ×1,52 |
| Unentschieden | 212 | 73,1 | ×1,02 |
| Niederlage | 341 | 32,7 | ×0,46 |

Ein Spieler im siegreichen Team macht also mehr als das Dreifache eines
Spielers im unterlegenen. Die Faktoren sind auf den Gesamtschnitt normiert,
ihr stichprobengewichteter Mittelwert ist exakt 1,0.

Der Faktor einer Mannschaft ist der Erwartungswert daraus, gewichtet mit
ihren Siegchancen. Bewusst ein Mix über alle drei Ausgänge statt einer
Einsortierung nach dem wahrscheinlichsten: Ein Spiel mit 40 % Siegchance
endet nicht zu 40 % gewonnen, sondern gewonnen oder eben nicht.

### Warum dieser Faktor nicht direkt in die Prognose geht

Die naheliegende Rechnung `Basis × Spielfaktor` ist **falsch**, und zwar
auf eine Art, die man leicht übersieht: Die Basis ist der Punkteschnitt des
Spielers und enthält die Stärke seines Teams bereits. Wer bei einem
Spitzenteam spielt, hat eine hohe Basis, *weil* sein Team oft gewinnt.
Multipliziert man den Spielfaktor obendrauf, zählt dieselbe Teamstärke
zweimal – ein Bayern-Spieler bekäme dauerhaft einen Bonus statt einer
Aussage über das konkrete Spiel.

Gemessen an einem Kader von 14 Spielern hob die naive Variante die
Prognosesumme um 6,6 % an, einzelne Spieler um bis zu 34 %.

Deshalb wird durch das **gewohnte Niveau** des Teams geteilt – den
Ausgangsmix seiner bereits gespielten Partien:

```
Gegner-Faktor = Faktor(nächstes Spiel) / Faktor(bisherige Spiele des Teams)
```

Damit heißt 1,0 „so schwer wie sonst auch". Bayern gegen einen
Spitzengegner landet unter 1,0, obwohl die Siegchance absolut gut ist –
für Bayern ist es eben ein schweres Spiel. Auf demselben Kader bleiben
statt +6,6 % noch +2,2 % übrig.

Teams mit weniger als 40 erfassten Einsätzen bekommen **keinen** Faktor,
statt einen aus Rauschen gebildeten. Der Quotient ist zusätzlich auf
0,5 bis 1,6 begrenzt, damit ein schlecht geschätzter Nenner die Prognose
nicht ins Absurde kippt.

Grenzen: Gemessen wird an den Spielern, zu denen es Spieltagsdaten gibt –
eigener Kader und Marktangebote. Das ist keine Zufallsstichprobe der Liga.
Gezählt werden außerdem **Einsätze, nicht Spiele**: Vereine, von denen
zufällig mehr Spieler erfasst sind, wiegen in der Normierung schwerer.
Für einen relativen Faktor ist das tragfähig, für Aussagen über einzelne
Vereine nicht.

## Zur Prognose auf der Aufstellungsseite

Erwartete Punkte = **Basis × Einsatzquote × Verfügbarkeit × Gegner**.

* **Basis** – Punkte je Einsatz: 60 % aus den letzten fünf Einsätzen, 40 % aus
  allen gespeicherten
* **Einsatzquote** – letzte zehn Spieltage; ein Spiel über 60 Minuten zählt
  voll, ein Kurzeinsatz mit 0,4
* **Verfügbarkeit** – angeschlagen 50 %, Aufbautraining 25 %, verletzt oder
  gesperrt 0 %
* **Gegner** – siehe oben; ohne Quote ist der Faktor 1 und ändert nichts

Grundlage ist `player_performances`, gefüllt von `sync.php --perf`. Zu
Saisonbeginn stammen die Werte zwangsläufig aus der Vorsaison. Wo sie aus der
2. Bundesliga kommen, steht das auf der Spielerkarte – die Punkte sind dann
nicht direkt vergleichbar. Spieler ohne Historie bekommen bewusst keine Zahl.

Nicht enthalten: Wechselgerüchte und die voraussichtliche
Vereinsaufstellung. Gegnerstärke und Heimvorteil stecken seit dem
Gegner-Faktor drin, solange für das Spiel eine Quote vorliegt.

Ob der Faktor die Prognose wirklich verbessert, steht noch nicht fest – die
Seite **Prognose** weist ihn je Eintrag aus, gemessen werden kann er erst
nach ein paar Spieltagen. Bis dahin ist er eine begründete Erweiterung, kein
bewiesener Gewinn.

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
Spielplan (`syncSchedule`), der deshalb vorher läuft.

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

Seit der Konkurrenzanalyse hat die Seite einen dritten Abschnitt: **Noch
frei** – Spieler, die in der Liga niemandem gehören und bei denen ein
Anstieg wahrscheinlich ist. Das ist die Verbindung aus beidem: die
Kaufliste sagt, *worauf* sich das Warten lohnt, sobald einer davon
angeboten wird.

Damit das keine geratene Liste wird, holt `syncPerformances()` auch für
die 25 besten freien Spieler die Spieltagsdaten. Ohne sie wäre die
Einsatzquote unbekannt und würde stillschweigend als 100 % gerechnet –
was die Erwartung nach oben verzerrt. Wo sie trotzdem fehlt, steht
„unsicher“ an der Zeile und ein Hinweis unter der Tabelle.

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
