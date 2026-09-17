/* Black Vendetta - grafieken op het beheerdashboard.
   Leest de cijfers uit #beheer-data (door de server als veilige JSON
   neergezet) en tekent ze met de zelf-gehoste Chart.js. */

(function () {
    'use strict';

    var blok = document.getElementById('beheer-data');
    if (!blok || typeof Chart === 'undefined') { return; }

    var data;
    try {
        data = JSON.parse(blok.textContent);
    } catch (e) {
        return;
    }

    function basis(legend) {
        return { plugins: { legend: { display: !!legend } } };
    }

    function lijn(id, labels, waarden, kleur) {
        var el = document.getElementById(id);
        if (!el) { return; }
        new Chart(el, {
            type: 'line',
            data: { labels: labels, datasets: [{ data: waarden, borderColor: kleur, tension: .25, pointRadius: 0 }] },
            options: Object.assign(basis(false), { scales: { x: { display: false } } })
        });
    }

    function staaf(id, labels, waarden, kleur) {
        var el = document.getElementById(id);
        if (!el) { return; }
        new Chart(el, {
            type: 'bar',
            data: { labels: labels, datasets: [{ data: waarden, backgroundColor: kleur }] },
            options: basis(false)
        });
    }

    if (data.trend) {
        lijn('grafiek-spelers', data.trend.dagen, data.trend.spelers, '#3ba2f0');
        lijn('grafiek-geld', data.trend.dagen, data.trend.geld_totaal, '#35c17a');
        staaf('grafiek-registraties', data.trend.dagen, data.trend.registraties, '#e8a33d');
    }

    if (data.stad) {
        staaf('grafiek-stad', Object.keys(data.stad), Object.values(data.stad), '#6ec8ff');
    }

    if (data.rang) {
        staaf('grafiek-rang', Object.keys(data.rang), Object.values(data.rang), '#a83246');
    }
}());
