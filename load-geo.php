<?php

// load-geo.php
//
// Add latitude and longitude pairs found in page text (see geotag.php) to the index.
//
//   php load-geo.php                 tag pages, then roll up to parts and items
//   php load-geo.php --tag           only tag pages, from their text in the index
//   php load-geo.php --rollup        only roll up
//   php load-geo.php --tag --limit 100000 --dry-run
//
// Options:
//   --tag       give each page with pairs in its text locations (its distinct points,
//               a geo_point list) and annotations (each pair, quoted, with where it is in
//               the text and its point); a page that no longer has any loses them. Only
//               pages that change are updated, so a re-run is cheap.
//   --rollup    give each part and item the distinct locations of its pages, and take
//               them from any that no longer have any. Run after load-metadata.php,
//               which replaces part and item documents.
//   --limit N   tag at most N pages (for trying it out)
//   --dry-run   count, but change nothing
//
// load-ocr.php tags pages as it loads them, so this is for an index loaded before
// that, or after geotag.php changes; --rollup is needed after every load.
//
// Pages are read from a point in time, so the updates made while reading do not move
// pages in or out of what is read.

// Command line only
if (PHP_SAPI != 'cli')
{
	exit(1);
}

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/elastic.php');
require_once (dirname(__FILE__) . '/geotag.php');

//----------------------------------------------------------------------------------------
// Call $callback with each batch of hits for $query, read from a point in time
function scan($elastic, $query, $source, $callback, $size = 2000)
{
	// See recent changes (the index refreshes only every 30 s)
	$elastic->send('POST', '_refresh');

	$resp = $elastic->send('POST', '_pit?keep_alive=10m');
	if ($resp[0] != 200)
	{
		throw new Exception('Could not open a point in time (HTTP ' . $resp[0] . ')');
	}
	$pit = $resp[1]->id;

	$after = null;

	try
	{
		while (true)
		{
			$body = array(
				'size'    => $size,
				'query'   => $query,
				'_source' => $source,
				'pit'     => array('id' => $pit, 'keep_alive' => '10m'),
				'sort'    => array(array('_shard_doc' => 'asc')),
			);
			if ($after)
			{
				$body['search_after'] = $after;
			}

			$resp = $elastic->send('POST', '/_search', json_encode($body));
			if ($resp[0] != 200)
			{
				throw new Exception('Search failed (HTTP ' . $resp[0] . '): ' . json_encode($resp[1]));
			}

			$pit  = $resp[1]->pit_id;
			$hits = $resp[1]->hits->hits;

			if (count($hits) == 0 || $callback($hits) === false)
			{
				break;
			}

			$after = end($hits)->sort;
		}
	}
	finally
	{
		$elastic->send('DELETE', '/_pit', json_encode(array('id' => $pit)));
	}
}

//----------------------------------------------------------------------------------------
// Partial updates, as id => fields (a field set to null is removed). Returns the number
// of documents not in the index, which are skipped (a page can name a part that has no
// document of its own).
function send_updates($elastic, $updates, $dry_run)
{
	if ($dry_run || count($updates) == 0)
	{
		return 0;
	}

	$lines = array();
	foreach ($updates as $id => $fields)
	{
		$lines[] = json_encode(array('update' => array('_id' => $id)));
		$lines[] = json_encode(array('doc' => $fields), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	list($code, $response) = $elastic->send('POST', '_bulk', implode("\n", $lines) . "\n", 'application/x-ndjson');

	if ($code != 200 || !$response)
	{
		throw new Exception('Bulk request failed with HTTP ' . $code);
	}

	$missing = 0;
	if (!empty($response->errors))
	{
		foreach ($response->items as $item)
		{
			$result = reset($item);
			if (isset($result->error))
			{
				if ($result->error->type == 'document_missing_exception')
				{
					$missing++;
				}
				else
				{
					throw new Exception('Update failed for ' . $result->_id . ': ' . json_encode($result->error));
				}
			}
		}
	}

	return $missing;
}

//----------------------------------------------------------------------------------------
// JSON with object keys sorted, to compare values regardless of key order (a partial
// update stores keys in another order than they were sent)
function canonical_json($value)
{
	$sort = function ($v) use (&$sort) {
		if (is_array($v))
		{
			$v = array_map($sort, $v);
			if (array_keys($v) !== range(0, count($v) - 1))
			{
				ksort($v);
			}
		}
		return $v;
	};

	return json_encode($sort(json_decode(json_encode($value), true)));
}

//----------------------------------------------------------------------------------------
// Tag every page that has text
function tag_pages($elastic, $limit, $dry_run)
{
	$stats = array('pages' => 0, 'with_geo' => 0, 'pairs' => 0, 'added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0);
	$started = microtime(true);

	$query = array('bool' => array('filter' => array(
		array('term' => array('type' => 'page')),
		array('exists' => array('field' => 'text')),
	)));

	scan($elastic, $query, array('text', 'locations', 'annotations'), function ($hits) use ($elastic, $limit, $dry_run, &$stats, $started) {
		$updates = array();

		foreach ($hits as $hit)
		{
			$stats['pages']++;

			$geo = page_geo($hit->_source->text);
			$had = isset($hit->_source->locations);

			if ($geo)
			{
				$stats['with_geo']++;
				$stats['pairs'] += count($geo['annotations']);

				$old = $had ? canonical_json(array('locations' => $hit->_source->locations, 'annotations' => isset($hit->_source->annotations) ? $hit->_source->annotations : null)) : null;
				if ($old == canonical_json($geo))
				{
					$stats['unchanged']++;
				}
				else
				{
					$stats[$had ? 'changed' : 'added']++;
					$updates[$hit->_id] = $geo;
				}
			}
			elseif ($had)
			{
				$stats['removed']++;
				$updates[$hit->_id] = array('locations' => null, 'annotations' => null);
			}

			if ($limit && $stats['pages'] >= $limit)
			{
				break;
			}
		}

		send_updates($elastic, $updates, $dry_run);

		if ($stats['pages'] % 100000 < count($hits))
		{
			fwrite(STDERR, sprintf("%s pages, %s with pairs, %.1f min\n",
				number_format($stats['pages']), number_format($stats['with_geo']), (microtime(true) - $started) / 60));
		}

		return !($limit && $stats['pages'] >= $limit);
	});

	return $stats;
}

//----------------------------------------------------------------------------------------
// Give parts and items the distinct locations of their pages
function rollup($elastic, $dry_run)
{
	// Points by part and by item
	$points = array();

	$query = array('bool' => array('filter' => array(
		array('term' => array('type' => 'page')),
		array('exists' => array('field' => 'locations')),
	)));

	scan($elastic, $query, array('locations', 'partid', 'itemid'), function ($hits) use (&$points) {
		foreach ($hits as $hit)
		{
			$owners = isset($hit->_source->partid) ? $hit->_source->partid : array();
			$owners[] = $hit->_source->itemid;

			foreach ($owners as $owner)
			{
				foreach ($hit->_source->locations as $point)
				{
					$points[$owner][$point->lat . ',' . $point->lon] = $point;
				}
			}
		}
	});

	// Parts and items that have locations now, to take them from any that should not
	$stale = array();

	$query = array('bool' => array(
		'filter' => array(
			array('terms' => array('type' => array('part', 'item'))),
			array('exists' => array('field' => 'locations')),
		),
	));

	scan($elastic, $query, false, function ($hits) use (&$stale, $points) {
		foreach ($hits as $hit)
		{
			if (!isset($points[$hit->_id]))
			{
				$stale[] = $hit->_id;
			}
		}
	});

	$stats = array('parts' => 0, 'items' => 0, 'missing' => 0, 'removed' => count($stale));

	$updates = array();
	foreach ($points as $id => $list)
	{
		$stats[strpos($id, 'part_') === 0 ? 'parts' : 'items']++;
		$updates[$id] = array('locations' => array_values($list));

		if (count($updates) == 500)
		{
			$stats['missing'] += send_updates($elastic, $updates, $dry_run);
			$updates = array();
		}
	}
	foreach ($stale as $id)
	{
		$updates[$id] = array('locations' => null);
	}
	$stats['missing'] += send_updates($elastic, $updates, $dry_run);

	return $stats;
}

//----------------------------------------------------------------------------------------

$options = getopt('', array('tag', 'rollup', 'limit:', 'dry-run'));

$do_tag    = isset($options['tag']) || !isset($options['rollup']);
$do_rollup = isset($options['rollup']) || !isset($options['tag']);
$limit     = isset($options['limit']) ? (int)$options['limit'] : 0;
$dry_run   = isset($options['dry-run']);

$elastic = new ElasticSearch($config['elastic_options']);

// The annotations field, if the index was made before it was in index.json
if (!$dry_run)
{
	$resp = $elastic->send('PUT', '_mapping', json_encode(array('properties' => array(
		'annotations' => array('type' => 'object', 'enabled' => false),
	))));
	if ($resp[0] != 200)
	{
		throw new Exception('Could not add annotations to the mapping: ' . json_encode($resp[1]));
	}
}

if ($do_tag)
{
	echo 'Pages: ' . json_encode(tag_pages($elastic, $limit, $dry_run)) . "\n";
}

if ($do_rollup)
{
	echo 'Rollup: ' . json_encode(rollup($elastic, $dry_run)) . "\n";
}

?>
