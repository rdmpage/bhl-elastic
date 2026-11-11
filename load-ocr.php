<?php

// load-ocr.php
//
// Bulk load page documents into Elasticsearch from the BHL OCR export, joining each
// page to its metadata in bhl.db. One sequential pass over the archive; nothing is
// extracted to disk.
//
//   php load-ocr.php --archive /Volumes/LaCie/bhl/ocr/bhl-ocr-20260514.tar.bz2
//   php load-ocr.php --archive ocr/bhl-light.tar.zst --items testsets/bhl-light-items.txt
//   bzip2 -dc bhl-ocr-20260514.tar.bz2 | php load-ocr.php --stdin
//
// --archive takes .tar.bz2, .tar.zst or .tar (see ocr_open()).
//
// Options:
//   --items FILE       only these ItemIDs, one per line (see testsets/)
//   --limit N          stop after about N pages (at the end of an item)
//   --ndjson FILE      write the bulk requests to FILE instead of sending them
//   --report           read and count only, send nothing
//   --batch N          documents per bulk request (default 2000)
//   --checkpoint FILE  resume point (default load-ocr.checkpoint; delete to start over)
//
// An item's pages are contiguous in the archive, so pages are gathered per item and the
// item's metadata is read from SQLite in three queries rather than three per page.
//
// Every page bhl.db lists for an item gets a document, with or without OCR text, so an
// item can be browsed without gaps. Documents are keyed page_<PageID>, so a re-run or a
// newer archive overwrites rather than duplicates.
//
// part/ entries are counted and skipped: page-to-part membership comes from partpage.

require_once (dirname(__FILE__) . '/sqlite.php');
require_once (dirname(__FILE__) . '/elastic.php');
require_once (dirname(__FILE__) . '/ocr.php');
require_once (dirname(__FILE__) . '/bhl.php');

//----------------------------------------------------------------------------------------
// First plausible four-digit year in a BHL date string ("1900", "1900-1902", "c1875").
function first_year($value)
{
	if ($value !== null && preg_match('/(1[5-9]\d\d|20\d\d)/', $value, $m))
	{
		return (int)$m[1];
	}
	return null;
}

//----------------------------------------------------------------------------------------
// Page documents for one item. $ocr maps PageID => raw OCR text.
function item_page_docs($stmts, $itemid, $ocr, &$stats)
{
	$stmts['item']->execute(array($itemid));
	$item_rows = $stmts['item']->fetchAll(PDO::FETCH_ASSOC);

	if (count($item_rows) == 0)
	{
		$stats['items_no_metadata']++;
		$stats['pages_no_metadata'] += count($ocr);
		return array();
	}

	// One item row per title the item belongs to.
	$titles = array();
	foreach ($item_rows as $row)
	{
		$titles['title_' . $row['TitleID']] = true;
	}
	$item = $item_rows[0];

	// A virtual item has no scan of its own; its images live under each part's barcode.
	$virtual = is_virtual_barcode($item['BarCode']);

	$stmts['partpage']->execute(array($itemid));
	$parts  = array();
	$thumbs = array();
	foreach ($stmts['partpage']->fetchAll(PDO::FETCH_ASSOC) as $row)
	{
		$parts[$row['PageID']]['part_' . $row['PartID']] = true;

		// Part barcodes only occur in virtual items, where the sequence that names the
		// image is the page's position in the part, not in the item.
		if ($row['BarCode'] !== null && !isset($thumbs[$row['PageID']]))
		{
			$thumbs[$row['PageID']] = page_thumbnail($row['BarCode'], $row['SequenceOrder']);
		}
	}

	// One page row per page type, so group by PageID.
	$stmts['page']->execute(array($itemid));
	$pages = array();
	foreach ($stmts['page']->fetchAll(PDO::FETCH_ASSOC) as $row)
	{
		$id = $row['PageID'];
		if (!isset($pages[$id]))
		{
			$pages[$id] = $row;
			$pages[$id]['types'] = array();
		}
		if ($row['PageTypeName'] !== null)
		{
			$pages[$id]['types'][$row['PageTypeName']] = true;
		}
	}

	foreach ($ocr as $pageid => $raw)
	{
		if (!isset($pages[$pageid]))
		{
			$stats['pages_no_metadata']++;
		}
	}

	$docs = array();

	foreach ($pages as $pageid => $page)
	{
		$doc = new stdclass;
		$doc->id      = 'page_' . $pageid;
		$doc->type    = 'page';
		$doc->itemid  = 'item_' . $itemid;
		$doc->titleid = array_keys($titles);

		if (isset($parts[$pageid]))
		{
			$doc->partid = array_keys($parts[$pageid]);
		}

		$doc->seq = (int)$page['SequenceOrder'];

		if (isset($thumbs[$pageid]))
		{
			$doc->thumbnail = $thumbs[$pageid];
		}
		elseif (!$virtual)
		{
			$doc->thumbnail = page_thumbnail($item['BarCode'], $doc->seq);
		}

		// The printed page label, or the sequence number in brackets as BHL shows it.
		$doc->name = $page['PageNumber'] !== null
			? trim($page['PagePrefix'] . ' ' . $page['PageNumber'])
			: '[' . $doc->seq . ']';

		if (count($page['types']) > 0)
		{
			$doc->tags = array_keys($page['types']);
			sort($doc->tags);
		}

		$year = first_year($page['Year']);
		if ($year === null)
		{
			$year = first_year($item['Year']);
		}
		if ($year !== null)
		{
			$doc->year = $year;
		}

		$volume = $page['Volume'] !== null ? $page['Volume'] : $item['VolumeInfo'];
		if ($volume !== null)
		{
			$doc->volume = $volume;
		}

		if ($page['Issue'] !== null)
		{
			$doc->issue = $page['Issue'];
		}

		if (!isset($ocr[$pageid]))
		{
			$stats['pages_no_ocr']++;
		}
		else
		{
			$text = ocr_clean($ocr[$pageid]);

			if ($text == '')
			{
				$stats['pages_empty']++;
			}
			else
			{
				$doc->text = $text;
			}
		}

		$docs[] = $doc;
	}

	return $docs;
}

//----------------------------------------------------------------------------------------

$options = getopt('', array('archive:', 'stdin', 'items:', 'limit:', 'ndjson:', 'report', 'batch:', 'checkpoint:'));

$limit      = isset($options['limit'])      ? (int)$options['limit'] : 0;
$batch      = isset($options['batch'])      ? (int)$options['batch'] : 2000;
$checkpoint = isset($options['checkpoint']) ? $options['checkpoint'] : dirname(__FILE__) . '/load-ocr.checkpoint';
$report     = isset($options['report']);
$ndjson     = isset($options['ndjson'])     ? fopen($options['ndjson'], 'w') : null;
$items      = isset($options['items'])      ? read_item_list($options['items']) : null;

if (!$config['has_bhl'])
{
	fwrite(STDERR, "No bhl.db; build it first with import.php\n");
	exit(1);
}

if (isset($options['stdin']))
{
	$input = STDIN;
}
elseif (isset($options['archive']))
{
	$input = ocr_open($options['archive']);
}
else
{
	fwrite(STDERR, "Give --archive FILE or --stdin\n");
	exit(1);
}

$elastic = null;
if (!$report && !$ndjson)
{
	$elastic = new ElasticSearch($config['elastic_options']);

	list($code, $response) = $elastic->send('GET');
	if ($code != 200)
	{
		fwrite(STDERR, "Index " . $elastic->index . " not found at " . $elastic->url
			. "; create it first:\n  curl -X PUT " . $elastic->url . '/' . $elastic->index
			. " -H 'Content-Type: application/json' -d @index.json\n");
		exit(1);
	}
}

$pdo = $config['bhlpdo'];

$stmts = array(
	'item'     => $pdo->prepare('SELECT TitleID, BarCode, Year, VolumeInfo FROM item WHERE ItemID = ?'),
	'page'     => $pdo->prepare('SELECT PageID, SequenceOrder, Year, Volume, Issue, PagePrefix, PageNumber, PageTypeName FROM page WHERE ItemID = ?'),
	'partpage' => $pdo->prepare('SELECT pp.PageID, pp.PartID, pp.SequenceOrder, MAX(part.BarCode) AS BarCode FROM partpage pp LEFT JOIN part USING (PartID) WHERE pp.ItemID = ? GROUP BY pp.PageID, pp.PartID'),
);

$stats = array(
	'items'             => 0,
	'items_no_metadata' => 0,
	'ocr_files'         => 0,
	'docs'              => 0,
	'pages_empty'       => 0,
	'pages_no_ocr'      => 0,
	'pages_no_metadata' => 0,
	'part_files'        => 0,
	'unparsed'          => 0,
	'skipped'           => 0,
);

// Resume: skip everything up to and including the checkpointed item.
$resume_after = file_exists($checkpoint) ? (int)trim(file_get_contents($checkpoint)) : 0;
$resume_seen  = false;

if ($resume_after)
{
	fwrite(STDERR, "# resuming after item $resume_after\n");
}

// Only read the contents of item pages we are going to use.
$want = function ($path) use (&$resume_after, &$resume_seen, $items)
{
	$parsed = ocr_parse_path($path);

	if (!$parsed || $parsed[0] != 'item')
	{
		return false;
	}

	if ($items !== null && !isset($items[$parsed[1]]))
	{
		return false;
	}

	if ($resume_after)
	{
		if ($parsed[1] == $resume_after)
		{
			$resume_seen = true;
			return false;
		}
		if (!$resume_seen)
		{
			return false;
		}
		$resume_after = 0;
	}

	return true;
};

$buffer       = array();
$buffer_docs  = 0;
$current_item = null;
$last_done    = null;
$current_ocr  = array();
$started      = microtime(true);

$flush = function ($last_item) use (&$buffer, &$buffer_docs, $elastic, $ndjson, $checkpoint)
{
	if ($buffer_docs > 0)
	{
		$data = join("\n", $buffer) . "\n";

		if ($ndjson)
		{
			fwrite($ndjson, $data);
		}
		elseif ($elastic)
		{
			$elastic->bulk($data);
		}
	}

	$buffer      = array();
	$buffer_docs = 0;

	// Only record the checkpoint once this item's documents are stored.
	if ($last_item !== null && ($elastic || $ndjson))
	{
		file_put_contents($checkpoint, $last_item . "\n");
	}
};

$finish_item = function () use (&$current_item, &$last_done, &$current_ocr, &$buffer, &$buffer_docs, &$stats, $stmts, $batch, $flush, $started)
{
	if ($current_item === null)
	{
		return;
	}

	$stats['items']++;
	$last_done = $current_item;

	foreach (item_page_docs($stmts, $current_item, $current_ocr, $stats) as $doc)
	{
		$buffer[] = json_encode(array('index' => array('_id' => $doc->id)));
		$buffer[] = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		$buffer_docs++;
		$stats['docs']++;
	}

	// Flush only between items, so a checkpoint always marks a whole item.
	if ($buffer_docs >= $batch)
	{
		$flush($current_item);
	}

	if ($stats['items'] % 1000 == 0)
	{
		$elapsed = microtime(true) - $started;
		fwrite(STDERR, sprintf("  %s items, %s pages, %.1f min (%s pages/s)\n",
			number_format($stats['items']), number_format($stats['docs']), $elapsed / 60,
			number_format($stats['docs'] / max($elapsed, 1))));
	}

	$current_ocr = array();
};

foreach (tar_entries($input, $want) as $entry)
{
	list($path, $size, $data) = $entry;

	$parsed = ocr_parse_path($path);

	if (!$parsed)
	{
		$stats['unparsed']++;
		continue;
	}

	list($kind, $id, $pageid, $seq) = $parsed;

	if ($kind == 'part')
	{
		$stats['part_files']++;
		continue;
	}

	// Any entry from another item closes the current one, including entries we skip,
	// so the last listed item is stored as soon as the archive moves past it.
	if ($id !== $current_item && $current_item !== null)
	{
		$finish_item();
		$current_item = null;

		if (($limit && $stats['docs'] >= $limit) || ($items !== null && $stats['items'] >= count($items)))
		{
			break;
		}
	}

	if ($data === null)
	{
		$stats['skipped']++;
		continue;
	}

	$current_item = $id;
	$current_ocr[$pageid] = $data;
	$stats['ocr_files']++;
}

$finish_item();
$flush($last_done);

if ($ndjson)
{
	fclose($ndjson);
}

$stats['minutes'] = round((microtime(true) - $started) / 60, 1);

foreach ($stats as $k => $v)
{
	fwrite(STDERR, str_pad($k, 20) . (is_int($v) ? number_format($v) : $v) . "\n");
}

?>
