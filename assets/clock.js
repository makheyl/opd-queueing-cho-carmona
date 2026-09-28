/* Shared live date/time widget for the standardized header.
   Populates any [data-live-date] / [data-live-time] element on the page.
   Purely presentational — reads only the client clock, touches no app state. */
(function () {
    function pad(n) { return n < 10 ? '0' + n : String(n); }

    var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    function formatDate(d) {
        return DAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }
    function formatTime(d) {
        var h = d.getHours();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12; if (h === 0) h = 12;
        return pad(h) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + ' ' + ampm;
    }

    function tick() {
        var now = new Date();
        var dateStr = formatDate(now);
        var timeStr = formatTime(now);
        var dateEls = document.querySelectorAll('[data-live-date]');
        var timeEls = document.querySelectorAll('[data-live-time]');
        for (var i = 0; i < dateEls.length; i++) dateEls[i].textContent = dateStr;
        for (var j = 0; j < timeEls.length; j++) timeEls[j].textContent = timeStr;
    }

    function start() {
        tick();
        setInterval(tick, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
