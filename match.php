<?php

// Match citations to articles in BHL, using the reconciliation service (reconcile.php).
// Like BioStor's match.html: paste citations one per line, and each row shows the best
// match, if any. The page calls the service from the browser, as any client would.

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/html.php');

$examples = array(
	'Dillon, M.O. 1984. A systematic study of Flourensia (Asteraceae, Heliantheae). Fieldiana Botany 16: 1-66',
	'Blake, S.F. 1935. New Asteraceae from the United States, Mexico, and South America. J. Wash. Acad. Sci. 25: 311-325',
	'Boulenger, G.A. 1887. On new reptiles and batrachians from North Borneo. Ann. Mag. Nat. Hist. (5) 20: 95-97',
	'Smith, J. 1999. A revision of the genus Nonexistentia. Journal of Imaginary Botany 12: 1-20',
);

display_html_start('Match citations - ' . $config['site_name']);
display_navbar('');
display_main_start();

echo '<div class="results">
	<h1>Match citations</h1>
	<p>Paste citations, one per line, to find them in BHL. Each one is run as an article search,
	and the best hits are checked against it, so only close matches count. The page uses the
	<a href="reconcile.php">reconciliation service</a>, which works with tools such as
	<a href="https://openrefine.org">OpenRefine</a>
	(see the <a href="https://reconciliation-api.github.io/specs/latest/">Reconciliation Service API</a>).</p>

	<textarea id="citations" class="match-input" rows="8">' . htmlspecialchars(implode("\n", $examples)) . '</textarea>
	<p><button id="match">Match</button> <span id="status" class="results-summary"></span></p>

	<table class="match-table">
		<thead><tr><th>Citation</th><th>Match</th><th>Score</th></tr></thead>
		<tbody id="rows"></tbody>
	</table>

	<details class="match-json">
		<summary>Request and response</summary>
		<pre id="json-query"></pre>
		<pre id="json-result"></pre>
	</details>
</div>';

display_main_end();
?>
<script>
function cell(row, text) {
	var td = document.createElement('td');
	td.textContent = text;
	row.appendChild(td);
	return td;
}

document.getElementById('match').addEventListener('click', function () {
	var lines = document.getElementById('citations').value.split('\n')
		.map(function (line) { return line.trim(); })
		.filter(function (line) { return line != ''; });

	var queries = {};
	lines.forEach(function (line, i) {
		queries['q' + i] = { query: line, limit: 3 };
	});

	var rows = document.getElementById('rows');
	rows.innerHTML = '';
	document.getElementById('json-query').textContent = JSON.stringify(queries, null, 2);
	document.getElementById('json-result').textContent = '';

	var status = document.getElementById('status');
	status.textContent = 'Matching ' + lines.length + (lines.length == 1 ? ' citation…' : ' citations…');

	var body = new URLSearchParams();
	body.set('queries', JSON.stringify(queries));

	fetch('reconcile.php', { method: 'POST', body: body })
		.then(function (response) { return response.json(); })
		.then(function (data) {
			document.getElementById('json-result').textContent = JSON.stringify(data, null, 2);

			var matched = 0;
			lines.forEach(function (line, i) {
				var result = data['q' + i] ? data['q' + i].result : [];
				var best = result.length > 0 && result[0].match ? result[0] : null;

				var row = document.createElement('tr');
				cell(row, line);

				if (best) {
					matched++;
					var td = cell(row, '');
					var a = document.createElement('a');
					a.href = 'https://www.biodiversitylibrary.org/part/' + best.id;
					a.textContent = best.name;
					td.appendChild(a);
					cell(row, best.score);
				} else {
					row.className = 'no-match';
					cell(row, 'No match');
					cell(row, '');
				}
				rows.appendChild(row);
			});

			status.textContent = matched + ' of ' + lines.length + ' matched';
		})
		.catch(function (error) {
			status.textContent = 'Failed: ' + error;
		});
});
</script>
<?php
display_html_end();
?>
