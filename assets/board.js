// assets/board.js
//
// Live behavior of the waiting-area display board, shared by display.php and
// display_backgrounded.php (ported from the Lab app's display board):
//
//   QueueBoard.start({ refreshUrl: 'display.php' });
//
// Voice announcements
//   notify.json holds the last few calls as a list (see announce_call() in
//   opd_functions.php). The board speaks every call newer than the last one
//   it handled, one after another, and never deletes anything, so calls made
//   seconds apart are all announced and every open board hears them. It starts
//   after whatever is already in the file, so old calls don't replay on load.
//
//   Browsers refuse to play any sound, speech included, on a page nobody has
//   touched yet. The browser enforces that; no script can lift it. Opening the
//   board from the Display Board shortcut (--autoplay-policy=no-user-gesture-
//   required, see printer_setup.php) avoids it entirely. Without the shortcut,
//   calls are queued and sound switches on silently at the very first click,
//   tap or key anywhere on the screen. No prompt or dialog is ever shown. One
//   unlock lasts all day, because the board refreshes in place instead of
//   reloading.
//
// Board refresh
//   When queue_status.php changes, the board fetches its own page and swaps
//   .kiosk-body in place: a full reload would switch sound back off. The swap
//   waits only while a call is actually being spoken (not while calls are
//   merely queued behind blocked sound, or a silent board would freeze), and a
//   change that arrives mid-refresh triggers one more refresh.
(function() {
    const FLASH_MS = 10000;          // how long a called number pulses
    const NOTIFY_POLL_MS = 250;
    const STATUS_POLL_MS = 3000;
    const SPEECH_TIMEOUT_MS = 15000; // some voices never fire 'end'
    const PAUSE_BETWEEN_CALLS_MS = 700;

    const hasSpeech = 'speechSynthesis' in window;
    const speechQueue = [];
    let lastCallId = null;   // null until notify.json has been read once
    let speaking = false;
    let soundBlocked = false;
    let unlockArmed = false;
    let voice = null;
    let spokenCount = 0;

    // ---------- number flash (survives the in-place refresh) ----------
    const flashUntil = {};
    const flashTimers = {};

    function flashFor(el, ms) {
        const key = el.id;
        if (flashTimers[key]) clearTimeout(flashTimers[key]);
        el.classList.add('board-flashing');
        flashTimers[key] = setTimeout(function() {
            const current = document.getElementById(key);
            if (current) current.classList.remove('board-flashing');
            delete flashTimers[key];
            delete flashUntil[key];
        }, ms);
    }

    // The card may not be on screen yet (the refresh can lag the call by a
    // moment); restoreFlashes() picks it up after the next refresh.
    function flashId(id) {
        flashUntil[id] = Date.now() + FLASH_MS;
        const el = document.getElementById(id);
        if (el) flashFor(el, FLASH_MS);
    }

    function restoreFlashes() {
        Object.keys(flashUntil).forEach(function(key) {
            const left = flashUntil[key] - Date.now();
            const el = document.getElementById(key);
            if (el && left > 0) flashFor(el, left);
        });
    }

    // ---------- voice ----------
    function armSilentUnlock() {
        soundBlocked = true;
        if (unlockArmed) return;
        unlockArmed = true;
        const unlock = function() {
            document.removeEventListener('click', unlock, true);
            document.removeEventListener('keydown', unlock, true);
            document.removeEventListener('touchstart', unlock, true);
            soundBlocked = false;
            unlockArmed = false;
            // Only the latest few calls are still worth announcing after a pause.
            while (speechQueue.length > 3) speechQueue.shift();
            speakNext();
        };
        document.addEventListener('click', unlock, true);
        document.addEventListener('keydown', unlock, true);
        document.addEventListener('touchstart', unlock, true);
    }

    function pickVoice() {
        if (!hasSpeech) return;
        const voices = window.speechSynthesis.getVoices();
        voice = voices.find(function(v) { return /^en[-_]PH/i.test(v.lang); })
            || voices.find(function(v) { return /^en[-_]US/i.test(v.lang); })
            || voices.find(function(v) { return /^en/i.test(v.lang); })
            || null;
    }

    // OPD's sentence. "Table" matches the Table headers on the board.
    function sentenceFor(call) {
        return 'Queue number ' + call.queue_number + ', queue number ' + call.queue_number
            + ', please proceed to table ' + call.table_number;
    }

    function makeUtterance(text) {
        const utter = new SpeechSynthesisUtterance(text);
        utter.lang = voice ? voice.lang : 'en-US';
        if (voice) utter.voice = voice;
        utter.rate = 0.9;
        return utter;
    }

    function speakNext() {
        if (speaking || soundBlocked || speechQueue.length === 0) return;
        const call = speechQueue.shift();
        flashId('serving-' + call.queue_number);
        if (!hasSpeech) {
            speakNext();
            return;
        }
        speaking = true;
        let finished = false;
        // Safety net: a voice that never fires 'end' must not jam the queue.
        const guard = setTimeout(function() { finish(); }, SPEECH_TIMEOUT_MS);
        function finish() {
            if (finished) return;
            finished = true;
            clearTimeout(guard);
            speaking = false;
            spokenCount++;
            setTimeout(speakNext, PAUSE_BETWEEN_CALLS_MS);
        }
        const utter = makeUtterance(sentenceFor(call));
        utter.onend = finish;
        utter.onerror = function(e) {
            if (e.error === 'not-allowed') {
                // Blocked until the screen is touched: keep this call for after
                // that, with nothing shown on screen (see armSilentUnlock()).
                finished = true;
                clearTimeout(guard);
                speaking = false;
                speechQueue.unshift(call);
                armSilentUnlock();
                return;
            }
            finish();
        };
        window.speechSynthesis.speak(utter);
    }

    // A silent test line at load tells whether the browser allows speech,
    // without waiting for a real call to find out.
    function checkSoundAllowed() {
        if (!hasSpeech) return;
        const test = makeUtterance('ready');
        test.volume = 0;
        test.onerror = function(e) {
            if (e.error === 'not-allowed') armSilentUnlock();
        };
        window.speechSynthesis.speak(test);
    }

    function pollNotify() {
        fetch('notify.json?rand=' + Math.random(), { cache: 'no-store' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                const calls = data && Array.isArray(data.calls) ? data.calls : [];
                if (lastCallId === null) {
                    // First read: start after whatever is already there.
                    lastCallId = calls.length ? calls[calls.length - 1].id : 0;
                    return;
                }
                calls.forEach(function(call) {
                    if (call.id > lastCallId) {
                        speechQueue.push(call);
                        lastCallId = call.id;
                    }
                });
                speakNext();
            })
            .catch(function() {}) // e.g. read mid-write or an old '{}' file: try again next round
            .then(function() { setTimeout(pollNotify, NOTIFY_POLL_MS); });
    }

    // ---------- board refresh ----------
    function start(options) {
        const refreshUrl = (options && options.refreshUrl) || location.pathname;
        let lastStatus = null;
        let refreshing = false;
        let refreshAgain = false; // another change arrived while a refresh was in flight

        async function refreshBoard() {
            // Don't swap the numbers out from under an announcement in progress.
            if (speaking) {
                setTimeout(refreshBoard, 500);
                return;
            }
            try {
                const res = await fetch(refreshUrl, { cache: 'no-store' });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                const fresh = doc.querySelector('.kiosk-body');
                const current = document.querySelector('.kiosk-body');
                if (fresh && current) {
                    current.innerHTML = fresh.innerHTML;
                    restoreFlashes();
                }
                if (refreshAgain) {
                    refreshAgain = false;
                    refreshBoard();
                } else {
                    refreshing = false;
                }
            } catch (e) {
                setTimeout(refreshBoard, 3000); // server hiccup: try again shortly
            }
        }

        async function pollQueueStatus() {
            try {
                const res = await fetch('queue_status.php', { cache: 'no-store' });
                // A failed request (e.g. a 500 during a MySQL hiccup) just skips
                // this round. It must not stop the loop, or an unattended board freezes.
                if (res.ok) {
                    const text = JSON.stringify(await res.json());
                    if (lastStatus === null) {
                        lastStatus = text;
                    } else if (text !== lastStatus) {
                        lastStatus = text;
                        if (!refreshing) {
                            refreshing = true;
                            refreshBoard();
                        } else {
                            refreshAgain = true;
                        }
                    }
                }
            } catch (e) {}
            setTimeout(pollQueueStatus, STATUS_POLL_MS);
        }

        if (hasSpeech) {
            pickVoice();
            window.speechSynthesis.addEventListener('voiceschanged', pickVoice);
        }
        function begin() {
            checkSoundAllowed();
            pollNotify();
            pollQueueStatus();
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', begin);
        } else {
            begin();
        }
    }

    // status(): read-only snapshot, handy when checking a board from the console.
    function status() {
        return {
            lastCallId: lastCallId,
            queued: speechQueue.length,
            speaking: speaking,
            soundBlocked: soundBlocked,
            spoken: spokenCount,
            voice: voice ? voice.name + ' (' + voice.lang + ')' : null,
        };
    }

    window.QueueBoard = { start: start, status: status };
})();
