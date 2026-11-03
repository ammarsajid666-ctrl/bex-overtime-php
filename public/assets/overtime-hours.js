// Updates the Hours box on the File overtime form as the start and end times change.
(function () {
    var start = document.getElementById('f-start');
    var end = document.getElementById('f-end');
    var out = document.getElementById('hours-out');
    var warn = document.getElementById('hours-warn');
    if (!start || !end || !out) return;
    var max = parseFloat(out.getAttribute('data-max')) || 12;

    function minutes(t) {
        var p = t.split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
    }

    function update() {
        if (warn) warn.textContent = '';
        if (!/^\d{2}:\d{2}$/.test(start.value) || !/^\d{2}:\d{2}$/.test(end.value)) {
            out.textContent = '0.00';
            return;
        }
        var m = minutes(end.value) - minutes(start.value);
        if (m < 0) m += 1440; // an end earlier than the start is an overnight shift
        var hours = m / 60;
        out.textContent = hours.toFixed(2);
        if (warn && hours > max) {
            warn.textContent = 'That is over ' + max + ' hours. Check the AM/PM of the end time.';
        }
    }

    ['input', 'change'].forEach(function (evt) {
        start.addEventListener(evt, update);
        end.addEventListener(evt, update);
    });
    update();
})();
