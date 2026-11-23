<?php

// load-annotations.php
//
// Add what is found in page text (see tagging.php: latitude and longitude pairs, and
// scientific names) to the pages already in the index, and roll it up to parts and
// items.
//
//   php load-annotations.php                     tag pages, then roll up
//   php load-annotations.php --tag               only tag pages
//   php load-annotations.php --rollup            only roll up
//   php load-annotations.php --tag --slice 0/4   a quarter of the pages; run 0/4 to 3/4
//                                                side by side, then --rollup
//   php load-annotations.php --tag --limit 10000 --dry-run
//   php load-annotations.php --item 309167       re-tag one item's pages and roll up
//                                                (after taxonfinder changes, to check)
//
// Options:
//   --tag        give each page with text its locations, names, new_names and
//                annotations (see tagging.php), and take them from pages that no
//                longer have any. Only pages that change are updated, so a re-run is
//                cheap.
//   --rollup     give each part and item the distinct locations, names and new_names
//                of its pages, and take them from any that no longer have any. Run
//                after load-metadata.php, which replaces part and item documents.
//   --slice i/n  tag only slice i of n of the pages (Elasticsearch sliced search), to
//                run several at once
//   --item N     tag only the pages of item N (BHL's number); with no --tag or --rollup,
//                also roll up (which is always the whole index)
//   --limit N    tag at most N pages (for trying it out)
//   --dry-run    count, but change nothing
//
// load-ocr.php tags pages as it loads them, so --tag is for an index loaded before
// that, or after geotag.php or taxonfinder changes; --rollup is needed after every load.
//
// Pages are read from a point in time, so the updates made while reading do not move
// pages in or out of what is read.

// Command line only
if (PHP_SAPI != 'cli')
{
	exit(1);
}

error_reporting(E_ALL);
ini_set('memory_limit', '2G');

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/elastic.php');
require_once (dirname(__FILE__) . '/tagging.php');

// The fields tagging adds to pages; all but annotations are rolled up
define('TAG_FIELDS', 'locations,names,new_names,annotations');
define('ROLLUP_FIELDS', 'locations,names,new_names');

//----------------------------------------------------------------------------------------
// Call $callback with each batch of hits for $query, read from a point in time;
// $slice is array(i, n) or null
function scan($elastic, $query, $source, $callback, $size = 1000, $slice = null)
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
			if ($slice)
			{
				$body['slice'] = array('id' => $slice[0], 'max' => $slice[1]);
			}
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
		// OCR can hold bytes that are not UTF-8; they become U+FFFD rather than failing
		$lines[] = json_encode(array('doc' => $fields), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	list($code, $response) = $elastic->send('POST', '_bulk', implode("\n", $lines) . "\n", 'application/x-ndjson');

	if ($code != 200 || !$response)
	{
		throw new Exception('Bulk request failed with HTTP ' . $code . ': ' . substr(json_encode($response), 0, 1000));
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
// Tag every page that has text (or one slice of them)
function tag_pages($elastic, $limit, $dry_run, $slice, $itemid = 0)
{
	$stats = array('pages' => 0, 'with_points' => 0, 'with_names' => 0, 'names' => 0, 'with_new_names' => 0,
		'changed' => 0, 'unchanged' => 0);
	$started = microtime(true);
	$fields  = explode(',', TAG_FIELDS);

	$query = array('bool' => array('filter' => array(
		array('term' => array('type' => 'page')),
		array('exists' => array('field' => 'text')),
	)));
	if ($itemid)
	{
		$query['bool']['filter'][] = array('term' => array('itemid' => 'item_' . $itemid));
	}

	scan($elastic, $query, array_merge(array('text'), $fields), function ($hits) use ($elastic, $limit, $dry_run, $fields, &$stats, $started) {
		$updates = array();

		foreach ($hits as $hit)
		{
			$stats['pages']++;

			$new = page_fields($hit->_source->text);

			if ($new['locations'])   { $stats['with_points']++; }
			if ($new['names'])       { $stats['with_names']++; $stats['names'] += count($new['names']); }
			if ($new['new_names'])   { $stats['with_new_names']++; }

			$old = array();
			foreach ($fields as $field)
			{
				$old[$field] = isset($hit->_source->{$field}) ? $hit->_source->{$field} : null;
			}

			if (canonical_json($old) == canonical_json($new))
			{
				$stats['unchanged']++;
			}
			else
			{
				$stats['changed']++;
				$updates[$hit->_id] = $new;
			}

			if ($limit && $stats['pages'] >= $limit)
			{
				break;
			}
		}

		send_updates($elastic, $updates, $dry_run);

		if ($stats['pages'] % 50000 < count($hits))
		{
			fwrite(STDERR, sprintf("%s pages, %s with names, %.1f min\n",
				number_format($stats['pages']), number_format($stats['with_names']), (microtime(true) - $started) / 60));
		}

		return !($limit && $stats['pages'] >= $limit);
	}, 1000, $slice);

	$stats['minutes'] = round((microtime(true) - $started) / 60, 1);

	return $stats;
}

//----------------------------------------------------------------------------------------
// Give parts and items the distinct locations, names and new_names of their pages
function rollup($elastic, $dry_run)
{
	$fields = explode(',', ROLLUP_FIELDS);

	// Values by part and by item: owner => field => key => value
	$values = array();

	$should = array();
	foreach ($fields as $field)
	{
		$should[] = array('exists' => array('field' => $field));
	}
	$query = array('bool' => array(
		'filter' => array(array('term' => array('type' => 'page'))),
		'should' => $should,
		'minimum_should_match' => 1,
	));

	scan($elastic, $query, array_merge($fields, array('partid', 'itemid')), function ($hits) use (&$values, $fields) {
		foreach ($hits as $hit)
		{
			$owners = isset($hit->_source->partid) ? $hit->_source->partid : array();
			$owners[] = $hit->_source->itemid;

			foreach ($fields as $field)
			{
				if (!isset($hit->_source->{$field}))
				{
					continue;
				}
				foreach ($hit->_source->{$field} as $value)
				{
					$key = is_object($value) ? $value->lat . ',' . $value->lon : $value;
					foreach ($owners as $owner)
					{
						$values[$owner][$field][$key] = $value;
					}
				}
			}
		}
	}, 5000);

	// Parts and items that have any of the fields now, to take them from any that
	// should not
	$stale = array();

	$query = array('bool' => array(
		'filter' => array(array('terms' => array('type' => array('part', 'item')))),
		'should' => $should,
		'minimum_should_match' => 1,
	));

	scan($elastic, $query, false, function ($hits) use (&$stale, $values) {
		foreach ($hits as $hit)
		{
			if (!isset($values[$hit->_id]))
			{
				$stale[] = $hit->_id;
			}
		}
	}, 5000);

	$stats = array('parts' => 0, 'items' => 0, 'missing' => 0, 'removed' => count($stale));

	$updates = array();
	foreach ($values as $id => $by_field)
	{
		$stats[strpos($id, 'part_') === 0 ? 'parts' : 'items']++;

		$update = array();
		foreach ($fields as $field)
		{
			$update[$field] = isset($by_field[$field]) ? array_values($by_field[$field]) : null;
		}
		$updates[$id] = $update;

		if (count($updates) == 200)
		{
			$stats['missing'] += send_updates($elastic, $updates, $dry_run);
			$updates = array();
		}
	}
	foreach ($stale as $id)
	{
		$updates[$id] = array_fill_keys($fields, null);
	}
	$stats['missing'] += send_updates($elastic, $updates, $dry_run);

	return $stats;
}

//----------------------------------------------------------------------------------------

$options = getopt('', array('tag', 'rollup', 'slice:', 'limit:', 'dry-run', 'item:'));

$do_tag    = isset($options['tag']) || !isset($options['rollup']);
$do_rollup = isset($options['rollup']) || !isset($options['tag']);
$limit     = isset($options['limit']) ? (int)$options['limit'] : 0;
$dry_run   = isset($options['dry-run']);
$itemid    = isset($options['item']) ? (int)$options['item'] : 0;

$slice = null;
if (isset($options['slice']))
{
	if (!preg_match('/^(\d+)\/(\d+)$/', $options['slice'], $m) || $m[1] >= $m[2])
	{
		fwrite(STDERR, "--slice is i/n, e.g. 0/4\n");
		exit(1);
	}
	$slice = array((int)$m[1], (int)$m[2]);
}

$elastic = new ElasticSearch($config['elastic_options']);

// Fields added to index.json since the index was made
if (!$dry_run)
{
	$resp = $elastic->send('PUT', '_mapping', json_encode(array('properties' => array(
		'names'       => array('type' => 'keyword'),
		'new_names'   => array('type' => 'keyword'),
		'annotations' => array('type' => 'object', 'enabled' => false),
	))));
	if ($resp[0] != 200)
	{
		throw new Exception('Could not add to the mapping: ' . json_encode($resp[1]));
	}
}

if ($do_tag)
{
	echo 'Pages: ' . json_encode(tag_pages($elastic, $limit, $dry_run, $slice, $itemid)) . "\n";
}

if ($do_rollup)
{
	echo 'Rollup: ' . json_encode(rollup($elastic, $dry_run)) . "\n";
}

?>
