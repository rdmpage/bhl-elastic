<?php

// Reconciliation service for articles, in the form OpenRefine uses
// (https://reconciliation-api.github.io/specs/latest/). Based on BioStor's
// (~/Development/biostor-lite api_reconciliation.php).
//
//   reconcile.php                       the service manifest
//   reconcile.php?queries={"q0":{"query":"Dillon 1984 Flourensia Fieldiana Botany 16: 1-66"}}
//
// queries can also be POSTed as a form field. Each query is run as an article search,
// and the top three hits are compared with it by longest common subsequence; a hit
// that passes BioStor's test has "match": true.

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/search.php');
require_once (dirname(__FILE__) . '/compare.php');

//----------------------------------------------------------------------------------------
function send_json($obj, $callback = '')
{
	header('Content-Type: application/json; charset=utf-8');
	header('Access-Control-Allow-Origin: *');

	$json = json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	// JSONP, for older clients
	if ($callback != '' && preg_match('/^[A-Za-z_$][A-Za-z0-9_$.]*$/', $callback))
	{
		header('Content-Type: application/javascript; charset=utf-8');
		$json = $callback . '(' . $json . ')';
	}

	echo $json;
}

//----------------------------------------------------------------------------------------
function reconcile_types()
{
	return array((object)array('id' => 'CreativeWork', 'name' => 'Article'));
}

//----------------------------------------------------------------------------------------
function reconcile_manifest()
{
	global $config;

	$manifest = new stdclass;
	$manifest->versions        = array('0.2');
	$manifest->name            = $config['site_name'] . ' articles';
	$manifest->identifierSpace = 'https://www.biodiversitylibrary.org/part/';
	$manifest->schemaSpace     = 'http://schema.org/';
	$manifest->defaultTypes    = reconcile_types();
	$manifest->view            = (object)array('url' => 'https://www.biodiversitylibrary.org/part/{{id}}');

	return $manifest;
}

//----------------------------------------------------------------------------------------
// Candidates for one citation string, best first.
function reconcile_one($elastic, $text, $limit = 3)
{
	$candidates = array();

	$text = trim($text);
	if ($text == '')
	{
		return $candidates;
	}

	$query = array(
		'size' => 3,
		'query' => article_query($text, '3<50%'),
		'_source' => array('name', 'authors', 'citation'),
	);

	$resp = $elastic->send('POST', '_search', json_encode($query));

	if ($resp[0] != 200)
	{
		throw new Exception('Search failed (HTTP ' . $resp[0] . ')');
	}

	foreach ($resp[1]->hits->hits as $hit)
	{
		if (!isset($hit->_source->citation))
		{
			continue;
		}

		$citation = $hit->_source->citation;
		$normalised = compare_common_subsequence($text, $citation);

		// Not every citation gives the authors, so also compare without them, and keep
		// whichever comparison passes the test (or failing that, matches more of the query)
		if (isset($hit->_source->authors))
		{
			$authors = implode('; ', $hit->_source->authors);
			if (strpos($citation, $authors) === 0)
			{
				$without = compare_common_subsequence($text, substr($citation, strlen($authors)));
				if (is_match($without) != is_match($normalised) ? is_match($without) : $without[1] > $normalised[1])
				{
					$normalised = $without;
				}
			}
		}

		$candidate = new stdclass;
		$candidate->id    = (string)id_number($hit->_id);
		$candidate->name  = $citation;
		$candidate->score = round($normalised[1], 3);
		$candidate->match = is_match($normalised);
		$candidate->type  = reconcile_types();

		// how much of the longer string the match covers, for anyone tuning the test
		$candidate->coverage = round($normalised[0], 3);

		$candidates[] = $candidate;
	}

	// matches first, then by score
	usort($candidates, function ($a, $b) {
		if ($a->match != $b->match)
		{
			return $a->match ? -1 : 1;
		}
		return $b->score <=> $a->score;
	});

	return array_slice($candidates, 0, $limit);
}

//----------------------------------------------------------------------------------------
function main()
{
	global $config;

	$queries  = isset($_REQUEST['queries']) ? $_REQUEST['queries'] : '';
	$callback = isset($_REQUEST['callback']) ? $_REQUEST['callback'] : '';

	if ($queries == '')
	{
		send_json(reconcile_manifest(), $callback);
		return;
	}

	$queries = json_decode($queries);
	if (!is_object($queries))
	{
		http_response_code(400);
		send_json(array('error' => 'queries should be a JSON object of queries'), $callback);
		return;
	}

	$elastic = new ElasticSearch($config['elastic_options']);

	$result = new stdclass;

	try
	{
		foreach ($queries as $key => $query)
		{
			$text  = isset($query->query) ? (string)$query->query : '';
			$limit = isset($query->limit) ? max(1, (int)$query->limit) : 3;

			$result->{$key} = (object)array('result' => reconcile_one($elastic, $text, $limit));
		}
	}
	catch (Exception $e)
	{
		http_response_code(502);
		send_json(array('error' => $e->getMessage()), $callback);
		return;
	}

	send_json($result, $callback);
}

main();

?>
