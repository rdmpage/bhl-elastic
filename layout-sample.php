<?php

// layout-sample.php
//
// A stratified sample of page images for training and testing page layout detection
// (see bhl-layout-detection-plan.md): pages of each kind, spread over time and over
// items, split into training, validation and test sets, with their images.
//
//   php layout-sample.php                      choose pages, write the list, get images
//   php layout-sample.php --per-stratum 50     fewer pages
//   php layout-sample.php --no-images          just the list
//   php layout-sample.php --images             just get the images for the list
//
// Options:
//   --per-stratum N  pages of each kind (default 150)
//   --seed S         seed for the random choice (default 1); the same seed and index
//                    give the same sample
//   --out DIR        where to write (default testsets/layout)
//   --no-images      do not download images
//   --images         only download the images for an existing list
//
// Writes DIR/pages.tsv, one page a line (see the header line for the columns), and
// DIR/images/<split>/<PageID>.jpg. Images are BHL's JPEGs from its open data on AWS
// (jpg/<barcode>/<barcode>_<seq>.jpg), about 1,000 pixels wide, half the resolution of
// the scans; the OCR's page size (ocr_width, ocr_height) is the full resolution.
//
// Kinds of page (stratum), from BHL's page types, taken in this order, so a page is
// counted once:
//
//   foldout       Foldout
//   map           Map
//   title         Title Page or Cover
//   chart         Chart: tables and graphs
//   list          Index, Table of Contents, List of Illustrations
//   plate         Illustration, without Text
//   text_figure   Illustration and Text: figures in the text, or plates with captions
//   rotated       Text only, but printed sideways (from the OCR's word positions):
//                 mostly tables turned to fit the page, which BHL does not mark
//   two_column    Text only, laid out in columns (from the OCR's word positions):
//                 mostly lists, such as indexes, references and gazetteers, and some
//                 narrow tables, that BHL does not mark
//   text          Text only, one column (the control)
//   blank         Blank: empty pages, often with show-through from the next page
//
// Within a kind, pages are spread evenly over four periods (before 1850, 1850-1899,
// 1900-1949, 1950 on) where there are enough, and no item gives more than one page of
// a kind. Each item is in one split (train 60%, validation 20%, test 20%, by a hash of
// its ItemID), so no item is in two splits and a model is never tested on pages from
// a volume it was trained on.
//
// Only pages with OCR word positions in bhl-ocr-store/bhl-light-words.db are used, as
// they give the image's barcode and sequence number, and the tests for sideways text
// and two columns (see text_layout()).

// Command line only
if (PHP_SAPI != 'cli')
{
	exit(1);
}

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/elastic.php');

define('ERAS', 'pre1850,1850-1899,1900-1949,1950on');

//----------------------------------------------------------------------------------------
// The kinds of page, as stratum => [tags it must have, tags it must not have], in order
function strata()
{
	return array(
		'foldout'     => array(array('Foldout'), array()),
		'map'         => array(array('Map'), array('Foldout')),
		'title'       => array(array(array('Title Page', 'Cover')), array('Foldout', 'Map')),
		'chart'       => array(array('Chart'), array('Foldout', 'Map', 'Title Page', 'Cover')),
		'list'        => array(array(array('Index', 'Table of Contents', 'List of Illustrations')), array('Foldout', 'Map', 'Title Page', 'Cover', 'Chart')),
		'plate'       => array(array('Illustration'), array('Text', 'Foldout', 'Map', 'Title Page', 'Cover', 'Chart', 'Index', 'Table of Contents', 'List of Illustrations')),
		'text_figure' => array(array('Illustration', 'Text'), array('Foldout', 'Map', 'Title Page', 'Cover', 'Chart', 'Index', 'Table of Contents', 'List of Illustrations')),
		'rotated'     => array(array('Text'), array('Illustration', 'Foldout', 'Map', 'Title Page', 'Cover', 'Chart', 'Index', 'Table of Contents', 'List of Illustrations', 'Blank')),
		'two_column'  => array(array('Text'), array('Illustration', 'Foldout', 'Map', 'Title Page', 'Cover', 'Chart', 'Index', 'Table of Contents', 'List of Illustrations', 'Blank')),
		'text'        => array(array('Text'), array('Illustration', 'Foldout', 'Map', 'Title Page', 'Cover', 'Chart', 'Index', 'Table of Contents', 'List of Illustrations', 'Blank')),
		'blank'       => array(array('Blank'), array('Text', 'Illustration')),
	);
}

//----------------------------------------------------------------------------------------
// Years for a period
function era_range($era)
{
	$ranges = array(
		'pre1850'   => array(null, 1849),
		'1850-1899' => array(1850, 1899),
		'1900-1949' => array(1900, 1949),
		'1950on'    => array(1950, null),
	);
	return $ranges[$era];
}

//----------------------------------------------------------------------------------------
// The split an item is in
function item_split($itemid)
{
	$h = crc32('layout-split:' . $itemid) % 100;
	return $h < 60 ? 'train' : ($h < 80 ? 'validation' : 'test');
}

//----------------------------------------------------------------------------------------
// Pages of a kind and period, in a random order (fixed by $seed), as hits with
// itemid, year and tags
function candidates($elastic, $must, $must_not, $era, $seed, $size)
{
	$filter = array(array('term' => array('type' => 'page')));
	foreach ($must as $tag)
	{
		// A list is any one of them
		$filter[] = is_array($tag) ? array('terms' => array('tags' => $tag)) : array('term' => array('tags' => $tag));
	}

	list($from, $to) = era_range($era);
	$range = array();
	if ($from) { $range['gte'] = $from; }
	if ($to)   { $range['lte'] = $to; }
	$filter[] = array('range' => array('year' => $range));

	$query = array(
		'size'    => $size,
		'_source' => array('itemid', 'year', 'tags', 'name'),
		'query'   => array('function_score' => array(
			'query'        => array('bool' => array(
				'filter'   => $filter,
				'must_not' => count($must_not) ? array(array('terms' => array('tags' => $must_not))) : array(),
			)),
			'random_score' => array('seed' => $seed, 'field' => '_seq_no'),
			'boost_mode'   => 'replace',
		)),
	);

	$resp = $elastic->send('POST', '_search', json_encode($query));
	if ($resp[0] != 200)
	{
		throw new Exception('Search failed (HTTP ' . $resp[0] . '): ' . json_encode($resp[1]));
	}
	return $resp[1]->hits->hits;
}

//----------------------------------------------------------------------------------------
// A page's OCR words and where its image is: [barcode, seq, width, height, words], or
// null if the page is not in the store
function page_words($pdo, $pageid)
{
	static $stmt = null;
	if (!$stmt)
	{
		$stmt = $pdo->prepare('SELECT item.BarCode, page.SequenceOrder, page.width, page.height, page.words
			FROM page JOIN item ON item.ItemID = page.ItemID WHERE page.PageID = ? AND item.status = "ok"');
	}
	$stmt->execute(array($pageid));
	$row = $stmt->fetch(PDO::FETCH_NUM);
	if (!$row)
	{
		return null;
	}
	$words = $row[4] ? json_decode(gzdecode($row[4])) : null;
	return array($row[0], (int)$row[1], (int)$row[2], (int)$row[3], $words);
}

//----------------------------------------------------------------------------------------
// How the text on a page is laid out, from the OCR's word boxes: "rotated" (printed
// sideways, as tables turned to fit the page are: most words taller than they are
// wide), "two_column" (lines lying wholly to one side of the middle, with enough on
// each side and few lines crossing it: in this collection mostly lists, such as
// indexes and references, as two columns of prose are rare), "one_column", or null if
// there is too little text to tell.
function text_layout($words)
{
	if (!$words || !isset($words->lines))
	{
		return null;
	}

	$lines = array();
	$min = PHP_INT_MAX;
	$max = 0;
	$long = 0;
	$tall = 0;
	foreach ($words->lines as $line)
	{
		if (count($line) == 0)
		{
			continue;
		}
		$x0 = PHP_INT_MAX;
		$x1 = 0;
		foreach ($line as $w)
		{
			$x0 = min($x0, $w[0]);
			$x1 = max($x1, $w[0] + $w[2]);
			// Words of three letters or more are wider than tall, unless turned
			if (mb_strlen($w[4]) >= 3)
			{
				$long++;
				if ($w[3] > $w[2])
				{
					$tall++;
				}
			}
		}
		$lines[] = array($x0, $x1, count($line));
		$min = min($min, $x0);
		$max = max($max, $x1);
	}

	if (count($lines) < 20 || $long < 100)
	{
		return null;
	}

	if ($tall > 0.3 * $long)
	{
		return 'rotated';
	}

	if ($max - $min < $words->width * 0.4)
	{
		return null;
	}

	$width  = $max - $min;
	$mid    = ($min + $max) / 2;
	$margin = $width * 0.03;
	$left = $right = $across = 0;
	foreach ($lines as $l)
	{
		if ($l[1] < $mid - $margin)      { $left++; }
		elseif ($l[0] > $mid + $margin)  { $right++; }
		else                             { $across++; }
	}

	return $left >= 12 && $right >= 12 && $across <= 0.15 * count($lines) ? 'two_column' : 'one_column';
}

//----------------------------------------------------------------------------------------
// Choose the pages
function choose($elastic, $pdo, $per_stratum, $seed)
{
	$eras   = explode(',', ERAS);
	$chosen = array();
	$items  = array(); // stratum => itemid => true
	$pages  = array(); // pageid => true, so a page is in one stratum only

	foreach (strata() as $stratum => $tags)
	{
		list($must, $must_not) = $tags;
		$items[$stratum] = array();
		$want = array_fill_keys($eras, (int)ceil($per_stratum / count($eras)));
		$got  = array_fill_keys($eras, 0);
		$rows = array();

		// Each period's share; what a period cannot fill is taken from the others
		foreach (array(false, true) as $topping_up)
		{
			foreach ($eras as $era)
			{
				if (count($rows) >= $per_stratum)
				{
					break;
				}
				$need = $topping_up ? $per_stratum - count($rows) : min($want[$era], $per_stratum - count($rows));
				if ($need <= 0)
				{
					continue;
				}

				// Sideways text and two columns are rare, and found by looking, so look at more pages
				$size = in_array($stratum, array('rotated', 'two_column', 'text')) ? 5000 : 1500;
				foreach (candidates($elastic, $must, $must_not, $era, $seed, $size) as $hit)
				{
					if ($need <= 0)
					{
						break;
					}
					$pageid = (int)preg_replace('/\D/', '', $hit->_id);
					$itemid = (int)preg_replace('/\D/', '', $hit->_source->itemid);
					if (isset($pages[$pageid]) || isset($items[$stratum][$itemid]))
					{
						continue;
					}

					$page = page_words($pdo, $pageid);
					if (!$page)
					{
						continue;
					}
					list($barcode, $seq, $width, $height, $words) = $page;

					// Text only pages are told apart by how the text is laid out
					$layouts = array('rotated' => 'rotated', 'two_column' => 'two_column', 'text' => 'one_column');
					if (isset($layouts[$stratum]) && text_layout($words) !== $layouts[$stratum])
					{
						continue;
					}

					$pages[$pageid] = true;
					$items[$stratum][$itemid] = true;
					$need--;
					$got[$era]++;

					$rows[] = array(
						'page_id'    => $pageid,
						'item_id'    => $itemid,
						'split'      => item_split($itemid),
						'stratum'    => $stratum,
						'era'        => $era,
						'year'       => isset($hit->_source->year) ? $hit->_source->year : '',
						'bhl_types'  => implode('; ', $hit->_source->tags),
						'page_label' => isset($hit->_source->name) ? $hit->_source->name : '',
						'barcode'    => $barcode,
						'seq'        => $seq,
						'ocr_width'  => $width,
						'ocr_height' => $height,
						'image_url'  => $GLOBALS['config']['bhl_images'] . '/jpg/' . $barcode . '/' . $barcode . '_' . sprintf('%04d', $seq) . '.jpg',
						'image'      => 'images/' . item_split($itemid) . '/' . $pageid . '.jpg',
						'bhl_url'    => 'https://www.biodiversitylibrary.org/page/' . $pageid,
					);
				}
			}
		}

		$splits = array_count_values(array_column($rows, 'split'));
		fwrite(STDERR, sprintf("%-12s %4d pages  (%s)  train %d, validation %d, test %d\n", $stratum, count($rows),
			implode(', ', array_map(function ($e) use ($got) { return $e . ' ' . $got[$e]; }, $eras)),
			isset($splits['train']) ? $splits['train'] : 0, isset($splits['validation']) ? $splits['validation'] : 0, isset($splits['test']) ? $splits['test'] : 0));

		$chosen = array_merge($chosen, $rows);
	}

	return $chosen;
}

//----------------------------------------------------------------------------------------
function write_tsv($file, $rows)
{
	$f = fopen($file, 'w');
	fwrite($f, implode("\t", array_keys($rows[0])) . "\n");
	foreach ($rows as $row)
	{
		fwrite($f, implode("\t", array_map(function ($v) { return str_replace(array("\t", "\n"), ' ', $v); }, $row)) . "\n");
	}
	fclose($f);
}

//----------------------------------------------------------------------------------------
function read_tsv($file)
{
	$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$keys  = explode("\t", array_shift($lines));
	$rows  = array();
	foreach ($lines as $line)
	{
		$rows[] = array_combine($keys, explode("\t", $line));
	}
	return $rows;
}

//----------------------------------------------------------------------------------------
// Download the images not already there, several at a time
function get_images($dir, $rows)
{
	$todo = array();
	foreach ($rows as $row)
	{
		$path = $dir . '/' . $row['image'];
		if (!file_exists($path) || filesize($path) == 0)
		{
			$todo[] = array($row['image_url'], $path);
		}
	}
	fwrite(STDERR, count($todo) . " images to get\n");

	$failed = 0;
	foreach (array_chunk($todo, 8) as $batch)
	{
		$multi   = curl_multi_init();
		$handles = array();
		foreach ($batch as $job)
		{
			if (!is_dir(dirname($job[1])))
			{
				mkdir(dirname($job[1]), 0777, true);
			}
			$ch = curl_init($job[0]);
			curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));
			curl_multi_add_handle($multi, $ch);
			$handles[] = array($ch, $job[1], $job[0]);
		}
		do
		{
			curl_multi_exec($multi, $running);
			curl_multi_select($multi);
		} while ($running > 0);

		foreach ($handles as $h)
		{
			list($ch, $path, $url) = $h;
			$body = curl_multi_getcontent($ch);
			if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 && strlen($body) > 0)
			{
				file_put_contents($path, $body);
			}
			else
			{
				$failed++;
				fwrite(STDERR, 'Failed: ' . $url . "\n");
			}
			curl_multi_remove_handle($multi, $ch);
			curl_close($ch);
		}
		curl_multi_close($multi);
	}

	return $failed;
}

//----------------------------------------------------------------------------------------

$options = getopt('', array('per-stratum:', 'seed:', 'out:', 'no-images', 'images'));

$per_stratum = isset($options['per-stratum']) ? (int)$options['per-stratum'] : 150;
$seed        = isset($options['seed']) ? (int)$options['seed'] : 1;
$dir         = isset($options['out']) ? rtrim($options['out'], '/') : dirname(__FILE__) . '/testsets/layout';
$tsv         = $dir . '/pages.tsv';

if (!is_dir($dir))
{
	mkdir($dir, 0777, true);
}

if (isset($options['images']))
{
	$rows = read_tsv($tsv);
}
else
{
	$elastic = new ElasticSearch($config['elastic_options']);
	$pdo = new PDO('sqlite:' . dirname(__FILE__) . '/bhl-ocr-store/bhl-light-words.db');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$rows = choose($elastic, $pdo, $per_stratum, $seed);
	write_tsv($tsv, $rows);

	$splits = array_count_values(array_column($rows, 'split'));
	fwrite(STDERR, sprintf("%d pages from %d items in %s: train %d, validation %d, test %d\n", count($rows),
		count(array_unique(array_column($rows, 'item_id'))), $tsv, $splits['train'], $splits['validation'], $splits['test']));
}

if (!isset($options['no-images']))
{
	$failed = get_images($dir, $rows);
	fwrite(STDERR, $failed ? "$failed images could not be downloaded\n" : "All images downloaded\n");
}

?>
