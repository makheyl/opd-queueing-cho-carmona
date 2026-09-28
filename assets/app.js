// assets/app.js
//
// Shared behavior for the staff screens (index.php, doctor.php,
// printer_setup.php). A plain script, no modules or build step: pages load it
// with a normal <script src> tag and call QueueUI.* from their own inline
// scripts. Ported from the Lab app's LabUI.
//
//   - QueueUI.startPolling(): the queue_status.php poller. It reloads the page
//     when the queue changes, but never while someone is typing (a text field
//     has focus) or a pop-up is open, since that would throw their work away.
//     It reloads as soon as they are done. It keeps polling after a failed
//     request, so a server hiccup never freezes a screen.
//   - Pop-ups: .modal-overlay elements opened with [data-open-dialog="id"] or
//     QueueUI.openDialog(id). They close on [data-close-dialog], Escape, or a
//     click on the dark background.
//   - Keyboard shortcuts: any visible, enabled button with data-key="N" is
//     clicked when N is pressed. Ignored while typing or while a pop-up is open.
//   - Messages marked data-autohide fade out after a few seconds.
//   - QueueUI.toast(): a pop-up notification in the top-right corner.
//     QueueUI.toastAfterReload() shows one after the next page load, for
//     actions that submit a form and come back through a redirect.
(function() {
    function isTextField(el) {
        if (!el) return false;
        const tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    }

    function openOverlay() {
        return document.querySelector('.modal-overlay.open');
    }

    // Reloading now would lose something the person is in the middle of.
    function isBusy() {
        return !!openOverlay() || isTextField(document.activeElement);
    }

    function startPolling(options) {
        const interval = (options && options.interval) || 3000;
        const holdWhile = (options && options.holdWhile) || function() { return false; };
        let last = null;
        let reloadPending = false;

        async function tick() {
            try {
                const res = await fetch('queue_status.php', { cache: 'no-store' });
                if (res.ok) {
                    const text = JSON.stringify(await res.json());
                    if (last === null) {
                        last = text;
                    } else if (text !== last) {
                        reloadPending = true;
                    }
                }
            } catch (e) {}
            if (reloadPending && !isBusy() && !holdWhile()) {
                location.reload();
                return;
            }
            setTimeout(tick, interval);
        }
        tick();
    }

    function openDialog(id) {
        const overlay = document.getElementById(id);
        if (!overlay) return;
        overlay.classList.add('open');
        // data-autofocus picks the button to focus; otherwise the first one
        // (put the safe choice, e.g. Close/Cancel, first).
        const target = overlay.querySelector('[data-autofocus]') || overlay.querySelector('.btn:not([disabled]):not([hidden])');
        if (target) target.focus();
    }

    function closeDialog(overlay) {
        if (overlay) overlay.classList.remove('open');
    }

    document.addEventListener('click', function(e) {
        const opener = e.target.closest('[data-open-dialog]');
        if (opener && !opener.disabled) {
            closeDialog(openOverlay()); // one pop-up at a time (e.g. Transfer opened from another pop-up)
            openDialog(opener.getAttribute('data-open-dialog'));
            return;
        }
        if (e.target.closest('[data-close-dialog]')) {
            closeDialog(e.target.closest('.modal-overlay'));
            return;
        }
        if (e.target.classList && e.target.classList.contains('modal-overlay')) {
            closeDialog(e.target);
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDialog(openOverlay());
            return;
        }
        if (e.altKey || e.ctrlKey || e.metaKey) return;
        if (isTextField(document.activeElement) || openOverlay()) return;
        const key = e.key.length === 1 ? e.key.toUpperCase() : '';
        if (!/^[A-Z]$/.test(key)) return;
        const btn = Array.prototype.find.call(document.querySelectorAll('[data-key="' + key + '"]'), function(b) {
            return !b.disabled && b.offsetParent !== null;
        });
        if (btn) {
            e.preventDefault();
            btn.click();
        }
    });

    // data-autohide="10000" keeps a message up longer than the default 6 s.
    function autohide(el) {
        const ms = parseInt(el.getAttribute('data-autohide'), 10) || 6000;
        setTimeout(function() {
            el.classList.add('fading');
            setTimeout(function() { el.remove(); }, 700);
        }, ms);
    }

    function fadeMessages() {
        document.querySelectorAll('[data-autohide]').forEach(autohide);
    }

    // Pop-up notification in the top-right corner (see .toast in theme.css).
    // kind: 'success' (default) or 'warning'.
    function toast(title, message, kind, ms) {
        let stack = document.querySelector('.toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        const el = document.createElement('div');
        el.className = 'toast' + (kind === 'warning' ? ' toast-warning' : '');
        el.setAttribute('role', 'status');
        el.setAttribute('data-autohide', String(ms || 6000));
        const icon = document.createElement('span');
        icon.className = 'toast-icon';
        icon.textContent = kind === 'warning' ? '!' : '✓';
        const body = document.createElement('div');
        const strong = document.createElement('span');
        strong.className = 'toast-title';
        strong.textContent = title;
        body.appendChild(strong);
        if (message) body.appendChild(document.createTextNode(message));
        el.appendChild(icon);
        el.appendChild(body);
        stack.appendChild(el);
        autohide(el);
    }

    // Remembered in sessionStorage (this tab only) and shown after the reload.
    const PENDING_TOAST_KEY = 'queueuiPendingToast';
    function toastAfterReload(title, message, kind, ms) {
        try {
            sessionStorage.setItem(PENDING_TOAST_KEY, JSON.stringify([title, message || '', kind || '', ms || 0]));
        } catch (e) {}
    }
    function showPendingToast() {
        let pending = null;
        try {
            pending = JSON.parse(sessionStorage.getItem(PENDING_TOAST_KEY) || 'null');
            sessionStorage.removeItem(PENDING_TOAST_KEY);
        } catch (e) {}
        if (Array.isArray(pending)) toast(pending[0], pending[1], pending[2], pending[3] || undefined);
    }

    function onReady() {
        fadeMessages();
        showPendingToast();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }

    window.QueueUI = {
        startPolling: startPolling,
        openDialog: openDialog,
        closeDialog: closeDialog,
        isBusy: isBusy,
        toast: toast,
        toastAfterReload: toastAfterReload,
    };
})();
