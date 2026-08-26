/**
 * Sync-Knopf in der Topbar.
 *
 * Der Klick startet nur einen Hintergrundprozess, danach wird sync-status.php
 * abgefragt, bis der Lauf durch ist. Ein voller Lauf dauert eine halbe Stunde,
 * deshalb wird das Intervall nach der ersten Minute groesser.
 */
(function () {
    'use strict';

    var box = document.querySelector('.sync-box');
    if (!box) {
        return;
    }

    var button  = box.querySelector('.sync-btn');
    var select  = box.querySelector('.sync-profile');
    var state   = box.querySelector('.sync-state');
    var token   = box.getAttribute('data-token');
    var timer   = null;
    var watched = false;   // haben wir einen laufenden Sync beobachtet?

    var STORE_KEY = 'kickbase.syncProfile';

    // Zuletzt gewaehlten Umfang wiederherstellen.
    try {
        var saved = window.localStorage.getItem(STORE_KEY);
        if (saved && select.querySelector('option[value="' + saved + '"]')) {
            select.value = saved;
        }
    } catch (e) { /* privates Fenster o.ae. - dann eben nicht */ }

    select.addEventListener('change', function () {
        try {
            window.localStorage.setItem(STORE_KEY, select.value);
        } catch (e) { /* egal */ }
    });

    function clock(seconds) {
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function setBusy(busy) {
        button.disabled = busy;
        select.disabled = busy;
        box.classList.toggle('busy', busy);
    }

    function show(text, cls) {
        state.textContent = text;
        state.className = 'sync-state' + (cls ? ' ' + cls : '');
    }

    function poll(delay) {
        window.clearTimeout(timer);
        timer = window.setTimeout(check, delay);
    }

    function check() {
        fetch('sync-status.php', { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.running) {
                    watched = true;
                    setBusy(true);
                    show('laeuft seit ' + clock(data.elapsed || 0));
                    // Nach der ersten Minute reicht ein groesseres Intervall.
                    poll((data.elapsed || 0) > 60 ? 5000 : 2000);
                    return;
                }

                setBusy(false);

                if (!watched) {
                    show('');
                    return;
                }

                // Der beobachtete Lauf ist fertig.
                var run = data.run || {};
                if (run.status === 'error') {
                    show(run.message ? 'Fehler: ' + run.message : 'Sync fehlgeschlagen', 'warn');
                    return;
                }
                show('fertig, lade neu ...', 'ok');
                window.location.reload();
            })
            .catch(function () {
                setBusy(false);
                show('Status nicht erreichbar', 'warn');
            });
    }

    button.addEventListener('click', function () {
        setBusy(true);
        show('starte ...');

        var body = new URLSearchParams();
        body.set('token', token);
        body.set('profile', select.value);

        fetch('sync-start.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: body.toString()
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.ok) {
                    setBusy(false);
                    show(data.error || 'Start fehlgeschlagen', 'warn');
                    return;
                }
                watched = true;
                poll(1000);
            })
            .catch(function () {
                setBusy(false);
                show('Start nicht erreichbar', 'warn');
            });
    });

    // Laeuft beim Seitenaufruf schon ein Sync, gleich mitverfolgen.
    if (box.getAttribute('data-running') === '1') {
        watched = true;
        setBusy(true);
        check();
    }
})();

/**
 * Aufstellung umstellen.
 *
 * Bewusst per Klick statt Drag and Drop: erst einen Spieler waehlen, dann das
 * Ziel. Das funktioniert auch auf dem Handy.
 */
(function () {
    'use strict';

    var pitch = document.querySelector('.lu-pitch');
    var bar   = document.querySelector('.lu-bar');
    if (!pitch || !bar) {
        return;
    }

    var bench     = document.querySelector('.lu-bench');
    var saveBtn   = document.getElementById('lu-save');
    var resetBtn  = document.getElementById('lu-reset');
    var select    = document.getElementById('lu-formation');
    var state     = bar.querySelector('.lu-state');
    var sumValue  = bar.querySelector('.lu-sum .value');
    var sumSub    = bar.querySelector('.lu-sum .sub');
    var token     = bar.getAttribute('data-token');
    var formation = bar.getAttribute('data-formation');

    var selected = null;
    var dirty    = false;

    function show(text, cls) {
        state.textContent = text;
        state.className = 'lu-state' + (cls ? ' ' + cls : ' muted');
    }

    /** Passt die Karte in diesen Container? Die Bank nimmt jeden. */
    function fits(card, container) {
        if (!container) {
            return false;
        }
        if (container.classList.contains('lu-bench')) {
            return true;
        }
        return container.getAttribute('data-position') === card.getAttribute('data-position');
    }

    function slotOf(card) {
        var parent = card.parentElement;
        return parent && parent.classList.contains('lu-slot') ? parent : null;
    }

    /** Leeren Platzhalter im Slot ein- oder ausblenden. */
    function refreshSlot(slot) {
        if (!slot || !slot.classList.contains('lu-slot')) {
            return;
        }
        var hasCard = !!slot.querySelector('.lu-card');
        slot.classList.toggle('empty', !hasCard);
        var placeholder = slot.querySelector('.lu-empty');
        if (placeholder) {
            placeholder.style.display = hasCard ? 'none' : '';
        }
    }

    function recalc() {
        var cards = pitch.querySelectorAll('.lu-slot .lu-card');
        var sum = 0;
        var unknown = 0;
        for (var i = 0; i < cards.length; i++) {
            var raw = cards[i].getAttribute('data-points');
            if (raw === '' || raw === null) {
                unknown++;
            } else {
                sum += parseFloat(raw.replace(',', '.')) || 0;
            }
        }
        if (sumValue) {
            sumValue.textContent = Math.round(sum).toLocaleString('de-DE');
        }
        if (!sumSub) {
            return;
        }

        if (unknown > 0) {
            sumSub.textContent = unknown + ' ohne Prognose';
            sumSub.className = 'sub warn';
        } else if (cards.length < 11) {
            sumSub.textContent = 'nur ' + cards.length + ' von 11 besetzt';
            sumSub.className = 'sub warn';
        } else {
            sumSub.textContent = '';
            sumSub.className = 'sub';
        }
    }

    function deselect() {
        if (selected) {
            selected.classList.remove('sel');
            selected = null;
        }
    }

    /** Karte in einen Container verschieben, ggf. mit Tausch. */
    function place(card, target) {
        var from = card.parentElement;

        var occupant = target.classList.contains('lu-slot') ? target.querySelector('.lu-card') : null;
        if (occupant && occupant !== card) {
            // Platz ist besetzt - nur tauschen, wenn beide passen.
            if (!fits(occupant, from)) {
                show('Tausch geht nicht: ' + occupant.querySelector('.lu-name').textContent
                    + ' passt nicht auf den anderen Platz.', 'warn');
                return false;
            }
            from.appendChild(occupant);
        }

        target.appendChild(card);
        refreshSlot(from);
        refreshSlot(target);
        recalc();
        dirty = true;
        show('geaendert, noch nicht gespeichert');
        return true;
    }

    document.addEventListener('click', function (event) {
        var card = event.target.closest ? event.target.closest('.lu-card') : null;
        var slot = event.target.closest ? event.target.closest('.lu-slot') : null;
        var onBench = event.target.closest ? event.target.closest('.lu-bench') : null;

        // Nichts von uns getroffen: Auswahl aufheben.
        if (!card && !slot && !onBench) {
            deselect();
            return;
        }

        if (!selected) {
            if (card) {
                selected = card;
                card.classList.add('sel');
                show('Ziel waehlen');
            }
            return;
        }

        if (card === selected) {
            deselect();
            show('');
            return;
        }

        var target = null;
        if (card) {
            target = card.parentElement;
        } else if (slot) {
            target = slot;
        } else if (onBench) {
            target = onBench;
        }

        if (!fits(selected, target)) {
            show('Dieser Platz passt nicht zur Position.', 'warn');
            return;
        }

        place(selected, target);
        deselect();
    });

    function collect() {
        var slots = {};
        var cards = document.querySelectorAll('.lu-card');
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var slot = slotOf(card);
            slots[card.getAttribute('data-player')] = slot ? slot.getAttribute('data-slot') : null;
        }
        return slots;
    }

    function post(body, onOk) {
        fetch('lineup-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: body.toString()
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.ok) {
                    show(data.error || 'Speichern fehlgeschlagen', 'warn');
                    return;
                }
                onOk();
            })
            .catch(function () {
                show('Server nicht erreichbar', 'warn');
            });
    }

    saveBtn.addEventListener('click', function () {
        show('speichere ...');
        var body = new URLSearchParams();
        body.set('token', token);
        body.set('formation', formation);
        body.set('slots', JSON.stringify(collect()));

        post(body, function () {
            dirty = false;
            show('gespeichert', 'ok');
        });
    });

    resetBtn.addEventListener('click', function () {
        if (!window.confirm('Eigene Planung verwerfen und die Aufstellung aus Kickbase anzeigen?')) {
            return;
        }
        show('setze zurueck ...');
        var body = new URLSearchParams();
        body.set('token', token);
        body.set('reset', '1');

        post(body, function () {
            dirty = false;
            window.location.href = 'lineup.php';
        });
    });

    select.addEventListener('change', function () {
        if (dirty && !window.confirm('Es gibt ungespeicherte Aenderungen. Formation trotzdem wechseln?')) {
            select.value = formation;
            return;
        }
        window.location.href = 'lineup.php?formation=' + encodeURIComponent(select.value);
    });

    window.addEventListener('beforeunload', function (event) {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
