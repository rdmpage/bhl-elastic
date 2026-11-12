<?php

// load-metadata.php
//
// Load item, part and title documents into Elasticsearch from bhl.db.
//
//   php load-metadata.php
//   php load-metadata.php --items testsets/bhl-light-items.txt
//
// Options:
//   --items FILE   only these ItemIDs, their parts and their titles (see testsets/)
//   --ndjson FILE  write the bulk requests to FILE instead of sending them
//   --batch N      documents per bulk request (default 2000)
//
// These documents carry the names that search results display (article and journal
// titles, authors, volumes), so the web interface needs only Elasticsearch. Names go in
// name, fullname, authors and container, never in text, so they do not affect how page
// text is scored.
//
// Documents are keyed item_<ItemID>, part_<PartID> and title_<TitleID>, so a re-run
// overwrites rather than duplicates.

require_once (dirname(__FILE__) . '/sqlite.php');
require_once (dirname(__FILE__) . '/elastic.php');
require_once (dirname(__FILE__) . '/ocr.php');
require_once (dirname(__FILE__) . '/bhl.php');

//----------------------------------------------------------------------------------------
// Fields with no value are left out of a document rather than stored as null.
function set_field($doc, $field, $value)
{
	if (is_string($value))
	{
		$value = trim($value);
	}

	if ($value !== null && $value !== '' && $value !== array())
	{
		$doc->{$field} = $value;
	}
}

//----------------------------------------------------------------------------------------
// DOI of a part or title, lower case, or null. BHL assigns 10.5962/bhl.part.N and
// 10.5962/bhl.title.N; publishers' DOIs for parts are listed here too.
function entity_doi($stmts, $type, $id)
{
	$stmts['doi']->execute(array($type, $id));
	$doi = $stmts['doi']->fetchColumn();
	$stmts['doi']->closeCursor();

	return $doi ? strtolower($doi) : null;
}

//----------------------------------------------------------------------------------------
// Item document, and the item's TitleIDs. An item has one row per title it belongs to.
function item_doc($stmts, $pdo, $itemid, $title_names)
{
	$stmts['item']->execute(array($itemid));
	$rows = $stmts['item']->fetchAll(PDO::FETCH_ASSOC);

	if (count($rows) == 0)
	{
		return array(null, array());
	}

	$titleids = array();
	foreach ($rows as $row)
	{
		$titleids[] = (int)$row['TitleID'];
	}
	$titleids = array_values(array_unique($titleids));

	$item = $rows[0];

	$doc = new stdclass;
	$doc->id      = 'item_' . $itemid;
	$doc->type    = 'item';
	$doc->titleid = array_map(function ($id) { return 'title_' . $id; }, $titleids);

	// "Fieldiana v.16": the first title's name plus the volume, so items can be found
	// by name. Results pages show the title and volume separately.
	$name = isset($title_names[$titleids[0]]) ? $title_names[$titleids[0]] : '';
	if ($item['VolumeInfo'] != '')
	{
		$name = trim($name . ' ' . $item['VolumeInfo']);
	}

	set_field($doc, 'name',      $name);
	set_field($doc, 'volume',    $item['VolumeInfo']);
	set_field($doc, 'year',      first_year($item['Year']));
	set_field($doc, 'thumbnail', item_thumbnail($pdo, $itemid));

	return array($doc, $titleids);
}

//----------------------------------------------------------------------------------------
// Part documents for one item.
function part_docs($stmts, $pdo, $itemid, $titleids)
{
	$docs = array();

	$stmts['part']->execute(array($itemid));
	$parts = $stmts['part']->fetchAll(PDO::FETCH_ASSOC);

	foreach ($parts as $part)
	{
		$partid = (int)$part['PartID'];

		$stmts['partcreator']->execute(array($partid));
		$authors = $stmts['partcreator']->fetchAll(PDO::FETCH_COLUMN);

		$doc = new stdclass;
		$doc->id      = 'part_' . $partid;
		$doc->type    = 'part';
		$doc->itemid  = 'item_' . $itemid;
		$doc->titleid = array_map(function ($id) { return 'title_' . $id; }, $titleids);

		set_field($doc, 'seq',       $part['SequenceOrder'] !== null ? (int)$part['SequenceOrder'] : null);
		set_field($doc, 'name',      $part['Title']);
		set_field($doc, 'authors',   array_values(array_unique($authors)));
		set_field($doc, 'container', $part['ContainerTitle']);
		set_field($doc, 'tags',      $part['SegmentType'] != '' ? array($part['SegmentType']) : null);
		set_field($doc, 'year',      first_year($part['Date']));
		set_field($doc, 'volume',    $part['Volume']);
		set_field($doc, 'issue',     $part['Issue']);
		set_field($doc, 'pages',     $part['PageRange']);
		set_field($doc, 'doi',       entity_doi($stmts, 'Part', $partid));
		set_field($doc, 'thumbnail', item_page_thumbnail($pdo, $itemid, $part['StartPageID']));

		$docs[] = $doc;
	}

	return $docs;
}

//----------------------------------------------------------------------------------------
// Title document. Its thumbnail is that of its first item (lowest ItemID) that has one.
function title_doc($stmts, $pdo, $titleid)
{
	$stmts['title']->execute(array($titleid));
	$title = $stmts['title']->fetch(PDO::FETCH_ASSOC);
	$stmts['title']->closeCursor();

	if (!$title)
	{
		return null;
	}

	$stmts['creator']->execute(array($titleid));
	$authors = $stmts['creator']->fetchAll(PDO::FETCH_COLUMN);

	$doc = new stdclass;
	$doc->id   = 'title_' . $titleid;
	$doc->type = 'title';

	set_field($doc, 'name',     $title['ShortTitle'] != '' ? $title['ShortTitle'] : $title['FullTitle']);
	set_field($doc, 'fullname', $title['FullTitle'] != $doc->name ? $title['FullTitle'] : null);
	set_field($doc, 'authors',  array_values(array_unique($authors)));
	set_field($doc, 'year',     first_year($title['StartYear']));
	set_field($doc, 'doi',      entity_doi($stmts, 'Title', $titleid));

	$stmts['title_items']->execute(array($titleid));
	foreach ($stmts['title_items']->fetchAll(PDO::FETCH_COLUMN) as $itemid)
	{
		$thumbnail = item_thumbnail($pdo, $itemid);
		if ($thumbnail)
		{
			$doc->thumbnail = $thumbnail;
			break;
		}
	}

	return $doc;
}

//----------------------------------------------------------------------------------------

$options = getopt('', array('items:', 'ndjson:', 'batch:'));

$batch  = isset($options['batch'])  ? (int)$options['batch'] : 2000;
$ndjson = isset($options['ndjson']) ? fopen($options['ndjson'], 'w') : null;

if (!$config['has_bhl'])
{
	fwrite(STDERR, "No bhl.db; build it first with import.php\n");
	exit(1);
}

$elastic = null;
if (!$ndjson)
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
	'item'        => $pdo->prepare('SELECT TitleID, VolumeInfo, Year FROM item WHERE ItemID = ?'),
	'part'        => $pdo->prepare('SELECT PartID, MAX(SequenceOrder) AS SequenceOrder, MAX(Title) AS Title, MAX(ContainerTitle) AS ContainerTitle, MAX(SegmentType) AS SegmentType, MAX(Date) AS Date, MAX(Volume) AS Volume, MAX(Issue) AS Issue, MAX(PageRange) AS PageRange, MAX(StartPageID) AS StartPageID FROM part WHERE ItemID = ? GROUP BY PartID'),
	'partcreator' => $pdo->prepare('SELECT CreatorName FROM partcreator WHERE PartID = ? ORDER BY rowid'),
	'title'       => $pdo->prepare('SELECT ShortTitle, FullTitle, StartYear FROM title WHERE TitleID = ? LIMIT 1'),
	'title_names' => $pdo->prepare('SELECT ShortTitle, FullTitle FROM title WHERE TitleID = ? LIMIT 1'),
	'title_items' => $pdo->prepare('SELECT DISTINCT ItemID FROM item WHERE TitleID = ? ORDER BY ItemID'),
	// Main entries before added ones, then catalogue order
	'creator'     => $pdo->prepare("SELECT CreatorName FROM creator WHERE TitleID = ? ORDER BY CreatorType NOT LIKE 'Main%', rowid"),
	'doi'         => $pdo->prepare('SELECT DOI FROM doi WHERE EntityType = ? AND EntityID = ? LIMIT 1'),
);

if (isset($options['items']))
{
	$itemids = array_keys(read_item_list($options['items']));
}
else
{
	$itemids = $pdo->query('SELECT DISTINCT ItemID FROM item ORDER BY ItemID')->fetchAll(PDO::FETCH_COLUMN);
}

$stats = array(
	'items'            => 0,
	'items_not_found'  => 0,
	'parts'            => 0,
	'titles'           => 0,
	'titles_not_found' => 0,
);

$buffer  = array();
$started = microtime(true);

$add = function ($doc) use (&$buffer, $batch, $elastic, $ndjson)
{
	$buffer[] = json_encode(array('index' => array('_id' => $doc->id)));
	$buffer[] = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

	if (count($buffer) >= 2 * $batch)
	{
		$data = join("\n", $buffer) . "\n";
		$ndjson ? fwrite($ndjson, $data) : $elastic->bulk($data);
		$buffer = array();
	}
};

// Title names are needed for item names, so cache them as items are read.
$title_names = array();
$titles_seen = array();

foreach ($itemids as $itemid)
{
	$itemid = (int)$itemid;

	// Look up the names of this item's titles before building the item document
	$stmts['item']->execute(array($itemid));
	foreach ($stmts['item']->fetchAll(PDO::FETCH_COLUMN) as $titleid)
	{
		if (!isset($title_names[$titleid]))
		{
			$stmts['title_names']->execute(array($titleid));
			$row = $stmts['title_names']->fetch(PDO::FETCH_ASSOC);
			$stmts['title_names']->closeCursor();
			$title_names[$titleid] = $row ? ($row['ShortTitle'] != '' ? $row['ShortTitle'] : $row['FullTitle']) : '';
		}
	}

	list($doc, $titleids) = item_doc($stmts, $pdo, $itemid, $title_names);

	if (!$doc)
	{
		$stats['items_not_found']++;
		continue;
	}

	$add($doc);
	$stats['items']++;

	foreach (part_docs($stmts, $pdo, $itemid, $titleids) as $part)
	{
		$add($part);
		$stats['parts']++;
	}

	foreach ($titleids as $titleid)
	{
		$titles_seen[$titleid] = true;
	}

	if ($stats['items'] % 10000 == 0)
	{
		fwrite(STDERR, sprintf("  %s items, %s parts, %.1f min\n",
			number_format($stats['items']), number_format($stats['parts']), (microtime(true) - $started) / 60));
	}
}

foreach (array_keys($titles_seen) as $titleid)
{
	$doc = title_doc($stmts, $pdo, $titleid);

	if ($doc)
	{
		$add($doc);
		$stats['titles']++;
	}
	else
	{
		$stats['titles_not_found']++;
	}
}

if (count($buffer) > 0)
{
	$data = join("\n", $buffer) . "\n";
	$ndjson ? fwrite($ndjson, $data) : $elastic->bulk($data);
}

$stats['minutes'] = round((microtime(true) - $started) / 60, 1);

foreach ($stats as $key => $value)
{
	fwrite(STDERR, str_pad($key, 18) . ' ' . (is_float($value) ? $value : number_format($value)) . "\n");
}

?>
