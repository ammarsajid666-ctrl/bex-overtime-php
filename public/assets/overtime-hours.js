// Updates the Hours box on the File overtime form as the start and end times change.
// Each time has a dropdown (5-minute steps) and a "Clock" option that shows a clock input.
(function () {
    var out = document.getElementById('hours-out');
    var warn = document.getElementById('hours-warn');
    if (!out) return;
    var max = parseFloat(out.getAttribute('data-max')) || 12;
    var keys = ['start', 'end'];

    function minutes(t) {
        var p = t.split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
    }

    // The value in use: the clock input when it is active, otherwise the dropdown.
    function value(key) {
        var c = document.getElementById('f-' + key + '-clock');
        if (c && !c.disabled) return c.value;
        var s = document.getElementById('f-' + key);
        return s ? s.value : '';
    }

    function setClock(key, on) {
        var s = document.getElementById('f-' + key);
        var c = document.getElementById('f-' + key + '-clock');
        var wrap = document.getElementById('clock-' + key);
        if (!s || !c || !wrap) return;
        if (on) {
            if (!c.value && /^\d{2}:\d{2}$/.test(s.value)) c.value = s.value;
            s.disabled = true;
            c.disabled = false;
            wrap.hidden = false;
            c.focus();
        } else {
            var v = c.value;
            var fiveMin = /^\d{2}:\d{2}$/.test(v) && parseInt(v.slice(3), 10) % 5 === 0;
            s.disabled = false;
            s.value = fiveMin ? v : '';
            c.disabled = true;
            c.value = '';
            wrap.hidden = true;
            s.focus();
        }
        update();
    }

    function update() {
        if (warn) warn.textContent = '';
        var a = value('start');
        var b = value('end');
        if (!/^\d{2}:\d{2}$/.test(a) || !/^\d{2}:\d{2}$/.test(b)) {
            out.textContent = '0.00';
            return;
        }
        var m = minutes(b) - minutes(a);
        if (m < 0) m += 1440; // an end earlier than the start is an overnight shift
        var hours = m / 60;
        out.textContent = hours.toFixed(2);
        if (warn && hours > max) {
            warn.textContent = 'That is over ' + max + ' hours. Check the AM/PM of the end time.';
        }
    }

    keys.forEach(function (key) {
        var s = document.getElementById('f-' + key);
        var c = document.getElementById('f-' + key + '-clock');
        var back = document.querySelector('[data-time-back="' + key + '"]');
        if (s) s.addEventListener('change', function () {
            if (s.value === '__clock') setClock(key, true);
            update();
        });
        if (c) {
            c.addEventListener('input', update);
            c.addEventListener('change', update);
        }
        if (back) back.addEventListener('click', function () { setClock(key, false); });
    });
    update();
})();
