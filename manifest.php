<?php

// IIIF Presentation 3 manifests for items and articles, for viewer.html or any IIIF
// viewer; iiif.php serves them.
//
// A manifest is built from the same JSON-LD that api.php returns (get_item() in
// records.php), plus each page's size, which canvases need, from the words database
// made by bhl-ocr-store ($config['words_db']). Images are BHL's "large" copies on AWS
// (about 930 pixels wide), as viewer.html does not zoom; canvases keep the size of the
// full scan, so word boxes, which are in its pixels, can be placed on them later.
//
// A page is a canvas whose id is the same in every manifest it is in, so an article's
// pages are the same canvases as in its item's manifest.
//
// Each canvas links to an AnnotationPage of the page's text (page_annotations()), fetched
// only when a viewer shows the page: an annotation for each line, or each word, of the
// OCR, with its box on the canvas. TIFY shows these as boxes and as a text panel (lines
// suit it best); viewer.html lays the text over the image so it can be selected (words
// suit it best). Manifests take text=line (the default), word, or none.

require_once (dirname(__FILE__) . '/search.php');
require_once (dirname(__FILE__) . '/records.php');

define('IIIF_CONTEXT', 'http://iiif.io/api/presentation/3/context.json');

// For textGranularity (https://iiif.io/api/extension/text-granularity/)
define('TEXT_GRANULARITY_CONTEXT', 'http://iiif.io/api/extension/text-granularity/context.json');

// IIIF Content Search 2.0, for searching inside an item or article (search_inside())
define('SEARCH_CONTEXT', 'http://iiif.io/api/search/2/context.json');

// Pages of hits in each response from search_inside(); the rest follow through "next"
define('SEARCH_PAGE_SIZE', 50);

// We have no manifest for this, as opposed to something going wrong (404 rather than 502)
class NoManifest extends Exception {}

//----------------------------------------------------------------------------------------
function words_pdo()
{
	global $config;

	static $pdo = null;

	if ($pdo === null)
	{
		if (!file_exists($config['words_db']))
		{
			throw new NoManifest('No page sizes (missing ' . basename($config['words_db']) . ')');
		}
		$pdo = new PDO('sqlite:' . $config['words_db'], null, null,
			array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}

	return $pdo;
}

//----------------------------------------------------------------------------------------
// This site's absolute URL, for ids in manifests, which must be absolute
function site_url($path = '')
{
	global $config;

	$https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off')
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');
	$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

	return ($https ? 'https' : 'http') . '://' . $host . $config['web_root'] . $path;
}

//----------------------------------------------------------------------------------------
// BHL's number from one of its URLs (.../page/57579637)
function url_number($url)
{
	return (int)preg_replace('/^.*\//', '', $url);
}

//----------------------------------------------------------------------------------------
// A IIIF language map; we don't know the language of BHL's labels
function iiif_label($text)
{
	return (object)array('none' => array((string)$text));
}

//----------------------------------------------------------------------------------------
function iiif_metadata($label, $value)
{
	return (object)array('label' => (object)array('en' => array($label)), 'value' => iiif_label($value));
}

//----------------------------------------------------------------------------------------
// The item's barcode and its pages' sizes, by PageID, or null if bhl-ocr-store has
// none (34 BHL-Light items have no OCR, and 40 have OCR that doesn't match BHL's pages)
function item_page_sizes($itemid)
{
	$pdo = words_pdo();

	$stmt = $pdo->prepare('SELECT BarCode, status FROM item WHERE ItemID = ?');
	$stmt->execute(array((int)$itemid));
	$item = $stmt->fetch(PDO::FETCH_OBJ);

	if (!$item || $item->status != 'ok')
	{
		return null;
	}

	$stmt = $pdo->prepare('SELECT PageID, width, height FROM page WHERE ItemID = ?');
	$stmt->execute(array((int)$itemid));

	$sizes = array();
	foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row)
	{
		$sizes[$row->PageID] = array((int)$row->width, (int)$row->height);
	}

	return (object)array('barcode' => $item->BarCode, 'sizes' => $sizes);
}

//----------------------------------------------------------------------------------------
// Whether we can make a manifest for an item (we have its page sizes)
function has_manifest($itemid)
{
	try
	{
		$stmt = words_pdo()->prepare('SELECT status FROM item WHERE ItemID = ?');
		$stmt->execute(array((int)$itemid));
		return $stmt->fetchColumn() == 'ok';
	}
	catch (Exception $e)
	{
		return false;
	}
}

//----------------------------------------------------------------------------------------
// Which of a list of BHL part and item URLs we can make manifests for, as a set
// (url => true): one look-up of the parts' items, then one of the items' page sizes
function works_with_manifests($urls)
{
	global $config;

	$ids   = array();
	$items = array();
	foreach ($urls as $url)
	{
		if (preg_match('/\/part\/\d+$/', $url))
		{
			$ids[] = 'part_' . url_number($url);
		}
		elseif (preg_match('/\/item\/\d+$/', $url))
		{
			$items[url_number($url)] = true;
		}
	}

	$result = array();

	try
	{
		$docs = array();
		if (count($ids) > 0)
		{
			$elastic = new ElasticSearch($config['elastic_options']);
			$docs = fetch_docs($elastic, $ids, array('itemid'));
		}

		foreach ($docs as $doc)
		{
			if (isset($doc->itemid))
			{
				$items[id_number($doc->itemid)] = true;
			}
		}
		if (count($items) == 0)
		{
			return $result;
		}

		$stmt = words_pdo()->prepare('SELECT ItemID FROM item WHERE status = \'ok\' AND ItemID IN ('
			. implode(',', array_fill(0, count($items), '?')) . ')');
		$stmt->execute(array_keys($items));
		$ok = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

		foreach ($docs as $id => $doc)
		{
			if (isset($doc->itemid) && isset($ok[id_number($doc->itemid)]))
			{
				$result['https://www.biodiversitylibrary.org/part/' . id_number($id)] = true;
			}
		}
		foreach ($ok as $itemid => $i)
		{
			$result['https://www.biodiversitylibrary.org/item/' . $itemid] = true;
		}
	}
	catch (Exception $e)
	{
		// No links to the viewer, then
	}

	return $result;
}

//----------------------------------------------------------------------------------------
// A page's canvas id, the same in every manifest
function page_canvas_id($pageid)
{
	return site_url('iiif/canvas/' . (int)$pageid);
}

//----------------------------------------------------------------------------------------
// The page viewer showing a manifest (a URL relative to this site), at a page if a BHL
// page URL is given, and searching inside it for $q if given
function viewer_url($manifest, $page_url = '', $q = '')
{
	return 'viewer.html?manifest=' . rawurlencode($manifest)
		. ($q != '' ? '&q=' . rawurlencode($q) : '')
		. ($page_url != '' ? '#canvas=' . rawurlencode(page_canvas_id(url_number($page_url))) : '');
}

//----------------------------------------------------------------------------------------
// The manifest for a BHL part or item URL, relative to this site, with text as words,
// as viewer.html wants
function manifest_path($url)
{
	return 'iiif.php?' . (preg_match('/\/part\//', $url) ? 'part' : 'item') . '=' . url_number($url) . '&text=word';
}

//----------------------------------------------------------------------------------------
// One page of an item's JSON-LD as a canvas. $seq is its place in the item.
function page_canvas($page, $seq, $sizes, $text = 'line')
{
	global $config;

	$pageid = url_number($page->id);
	list($width, $height) = $sizes->sizes[$pageid];

	$canvas = new stdclass;
	$canvas->id     = page_canvas_id($pageid);
	$canvas->type   = 'Canvas';
	if (isset($page->name))
	{
		$canvas->label = iiif_label($page->name);
	}
	$canvas->width  = $width;
	$canvas->height = $height;

	$base = $config['bhl_images'] . '/web/' . $sizes->barcode . '/' . $sizes->barcode . '_' . sprintf('%04d', $seq);

	$canvas->thumbnail = array((object)array(
		'id'     => $base . '_thumb.webp',
		'type'   => 'Image',
		'format' => 'image/webp',
		'width'  => 150,
		'height' => (int)round(150 * $height / $width),
	));

	$canvas->items = array((object)array(
		'id'    => $canvas->id . '/page',
		'type'  => 'AnnotationPage',
		'items' => array((object)array(
			'id'         => $canvas->id . '/image',
			'type'       => 'Annotation',
			'motivation' => 'painting',
			'body'       => (object)array(
				'id'     => $base . '_large.webp',
				'type'   => 'Image',
				'format' => 'image/webp',
			),
			'target'     => $canvas->id,
		)),
	));

	if ($text != 'none')
	{
		$canvas->annotations = array((object)array(
			'id'   => site_url('iiif.php?page=' . $pageid . ($text == 'word' ? '&text=word' : '')),
			'type' => 'AnnotationPage',
		));
	}

	return $canvas;
}

//----------------------------------------------------------------------------------------
// The text= parameter of a manifest's URL (nothing for the default, line)
function text_param($text)
{
	return $text == 'line' ? '' : '&text=' . $text;
}

//----------------------------------------------------------------------------------------
// A page's text as a IIIF AnnotationPage: an annotation for each line (or, with $text
// 'word', each word) of the OCR, whose body is the text and whose target is its box on
// the canvas (canvas pixels are the OCR's). A line's box is the union of its words'.
// Null if we have no words for the page.
function page_annotations($pageid, $text = 'line')
{
	$stmt = words_pdo()->prepare('SELECT words FROM page WHERE PageID = ?');
	$stmt->execute(array((int)$pageid));
	$blob = $stmt->fetchColumn();

	if ($blob === false)
	{
		return null;
	}

	$words = json_decode(gzdecode($blob));

	$canvas_id = page_canvas_id($pageid);

	$page = new stdclass;
	$page->{'@context'} = array(TEXT_GRANULARITY_CONTEXT, IIIF_CONTEXT);
	$page->id    = site_url('iiif.php?page=' . (int)$pageid . ($text == 'word' ? '&text=word' : ''));
	$page->type  = 'AnnotationPage';
	$page->items = array();

	$annotation = function ($n, $box, $value) use ($canvas_id, $text) {
		return (object)array(
			'id'              => $canvas_id . '/' . $text . '/' . $n,
			'type'            => 'Annotation',
			'motivation'      => 'supplementing',
			'textGranularity' => $text,
			'body'            => (object)array('type' => 'TextualBody', 'value' => $value, 'format' => 'text/plain'),
			'target'          => $canvas_id . '#xywh=' . implode(',', $box),
		);
	};

	$n = 0;
	foreach ($words->lines as $line)
	{
		if ($text == 'word')
		{
			foreach ($line as $word)
			{
				$page->items[] = $annotation(++$n, array_slice($word, 0, 4), $word[4]);
			}
		}
		elseif (count($line) > 0)
		{
			$x1 = $y1 = PHP_INT_MAX;
			$x2 = $y2 = 0;
			$strings = array();
			foreach ($line as $word)
			{
				$x1 = min($x1, $word[0]);
				$y1 = min($y1, $word[1]);
				$x2 = max($x2, $word[0] + $word[2]);
				$y2 = max($y2, $word[1] + $word[3]);
				$strings[] = $word[4];
			}
			$page->items[] = $annotation(++$n, array($x1, $y1, $x2 - $x1, $y2 - $y1), implode(' ', $strings));
		}
	}

	return $page;
}

//----------------------------------------------------------------------------------------
// What a manifest says about rights and where it came from, from the item's JSON-LD
function add_provenance($manifest, $item)
{
	if (isset($item->provider))
	{
		$manifest->requiredStatement = iiif_metadata('Contributed by', $item->provider->name);
	}

	// IIIF only takes Creative Commons and RightsStatements.org URIs, written with http
	if (isset($item->license) && preg_match('/creativecommons\.org/', $item->license))
	{
		$manifest->rights = preg_replace('/^https:/', 'http:', $item->license);
	}

	if (isset($item->copyrightNotice))
	{
		$manifest->metadata[] = iiif_metadata('Copyright', $item->copyrightNotice);
	}
	if (isset($item->copyrightHolder))
	{
		$manifest->metadata[] = iiif_metadata('Rights holder', $item->copyrightHolder->name);
	}
}

//----------------------------------------------------------------------------------------
// A manifest for an item, or null if there is no such item
function item_manifest($itemid, $text = 'line')
{
	$item = get_item($itemid);
	return $item ? item_manifest_from($item, $itemid, $text) : null;
}

//----------------------------------------------------------------------------------------
// A manifest from an item's JSON-LD (from get_item())
function item_manifest_from($item, $itemid, $text = 'line')
{
	$sizes = item_page_sizes($itemid);
	if (!$sizes)
	{
		throw new NoManifest('No page sizes for item ' . (int)$itemid);
	}

	$label = $item->name . (isset($item->volumeNumber) ? ' ' . $item->volumeNumber : '');

	$manifest = new stdclass;
	$manifest->{'@context'} = array(TEXT_GRANULARITY_CONTEXT, IIIF_CONTEXT);
	$manifest->id       = site_url('iiif.php?item=' . (int)$itemid . text_param($text));
	$manifest->type     = 'Manifest';
	$manifest->label    = iiif_label($label);
	$manifest->metadata = array(iiif_metadata('Title', $item->name));
	if (isset($item->volumeNumber))  { $manifest->metadata[] = iiif_metadata('Volume', $item->volumeNumber); }
	if (isset($item->datePublished)) { $manifest->metadata[] = iiif_metadata('Year', $item->datePublished); }

	add_provenance($manifest, $item);

	if (isset($item->thumbnailUrl))
	{
		$manifest->thumbnail = array((object)array('id' => $item->thumbnailUrl, 'type' => 'Image', 'format' => 'image/webp'));
	}
	$manifest->homepage = array((object)array(
		'id'     => $item->id,
		'type'   => 'Text',
		'label'  => iiif_label($label),
		'format' => 'text/html',
	));
	$manifest->service = array(search_service('item', $itemid));
	$manifest->seeAlso = array((object)array(
		'id'      => site_url('api.php?item=' . (int)$itemid),
		'type'    => 'Dataset',
		'format'  => 'application/ld+json',
		'profile' => 'https://schema.org/',
	));

	// Pages, and the canvases of each article
	$manifest->items = array();
	$in_part = array();

	foreach ($item->itemListElement as $element)
	{
		$page = $element->item;
		if (!isset($sizes->sizes[url_number($page->id)]))
		{
			continue;
		}
		$canvas = page_canvas($page, $element->position, $sizes, $text);
		$manifest->items[] = $canvas;

		if (isset($page->isPartOf))
		{
			foreach ($page->isPartOf as $part)
			{
				$in_part[$part->id][] = (object)array('id' => $canvas->id, 'type' => 'Canvas');
			}
		}
	}

	// Articles as ranges, in their order in the item
	$structures = array();
	foreach ($item->hasPart as $part)
	{
		if (isset($in_part[$part->id]))
		{
			$structures[] = (object)array(
				'id'    => site_url('iiif/range/' . url_number($part->id)),
				'type'  => 'Range',
				'label' => iiif_label(isset($part->name) ? $part->name : 'Part ' . url_number($part->id)),
				'items' => $in_part[$part->id],
			);
		}
	}
	if (count($structures) > 0)
	{
		$manifest->structures = $structures;
	}

	return $manifest;
}

//----------------------------------------------------------------------------------------
// A manifest for an article: its pages, from its item's manifest, or null if there is
// no such article
function part_manifest($partid, $text = 'line')
{
	global $config;

	$elastic = new ElasticSearch($config['elastic_options']);

	$id = 'part_' . (int)$partid;
	$docs = fetch_docs($elastic, array($id), array('itemid'));

	if (!isset($docs[$id]) || !isset($docs[$id]->itemid))
	{
		return null;
	}

	$itemid = id_number($docs[$id]->itemid);
	$item = get_item($itemid);

	if (!$item)
	{
		return null;
	}

	$item_manifest = item_manifest_from($item, $itemid, $text);

	$range = null;
	if (isset($item_manifest->structures))
	{
		foreach ($item_manifest->structures as $r)
		{
			if ($r->id == site_url('iiif/range/' . (int)$partid))
			{
				$range = $r;
			}
		}
	}

	if (!$range)
	{
		throw new NoManifest('No pages for part ' . (int)$partid);
	}

	$canvases = array();
	foreach ($range->items as $c)
	{
		$canvases[$c->id] = true;
	}

	// The article's own JSON-LD, for its metadata
	$part = null;
	foreach ($item->hasPart as $p)
	{
		if (url_number($p->id) == (int)$partid)
		{
			$part = $p;
		}
	}

	$manifest = new stdclass;
	$manifest->{'@context'} = array(TEXT_GRANULARITY_CONTEXT, IIIF_CONTEXT);
	$manifest->id       = site_url('iiif.php?part=' . (int)$partid . text_param($text));
	$manifest->type     = 'Manifest';
	$manifest->label    = $range->label;
	$manifest->metadata = array();

	if (isset($part->author))
	{
		$names = array();
		foreach ($part->author as $author)
		{
			$names[] = $author->name;
		}
		$manifest->metadata[] = iiif_metadata('Authors', implode('; ', $names));
	}
	$manifest->metadata[] = iiif_metadata('In', $item_manifest->label->none[0]);
	if (isset($part->pagination))    { $manifest->metadata[] = iiif_metadata('Pages', $part->pagination); }
	if (isset($part->datePublished)) { $manifest->metadata[] = iiif_metadata('Year', $part->datePublished); }
	if (isset($part->sameAs))        { $manifest->metadata[] = iiif_metadata('DOI', $part->sameAs); }

	add_provenance($manifest, $item);

	if (isset($part->thumbnailUrl))
	{
		$manifest->thumbnail = array((object)array('id' => $part->thumbnailUrl, 'type' => 'Image', 'format' => 'image/webp'));
	}
	$manifest->homepage = array((object)array(
		'id'     => $part->id,
		'type'   => 'Text',
		'label'  => $range->label,
		'format' => 'text/html',
	));
	$manifest->partOf = array((object)array('id' => $item_manifest->id, 'type' => 'Manifest'));
	$manifest->service = array(search_service('part', $partid));

	$manifest->items = array();
	foreach ($item_manifest->items as $canvas)
	{
		if (isset($canvases[$canvas->id]))
		{
			$manifest->items[] = $canvas;
		}
	}

	return $manifest;
}

//----------------------------------------------------------------------------------------
// Searching inside an item or article (IIIF Content Search 2.0, served by
// iiif-search.php). The service's id is a path, iiif-search.php/item/262715, as clients
// add ?q= to it.
function search_service($scope, $id)
{
	return (object)array(
		'id'   => site_url('iiif-search.php/' . $scope . '/' . (int)$id),
		'type' => 'SearchService2',
	);
}

//----------------------------------------------------------------------------------------
// The hits in a page's text, from Elasticsearch's highlighting of the whole text with
// $pre and $post around each matching word, as [prefix, exact, suffix, start, end],
// start and end being where the hit is in the text (in bytes). Words of a phrase,
// matched one by one, become one hit; prefix and suffix are up to about $context
// characters, ending at a word.
function text_hits($highlighted, $pre, $post, $context = 50)
{
	// Alternately text and a match
	$pieces = preg_split('/' . preg_quote($pre, '/') . '(.*?)' . preg_quote($post, '/') . '/su',
		$highlighted, -1, PREG_SPLIT_DELIM_CAPTURE);

	// Join matches with only spaces or punctuation between them
	for ($i = count($pieces) - 4; $i >= 1; $i -= 2)
	{
		if (preg_match('/^[\s\p{P}]{1,3}$/u', $pieces[$i + 1]))
		{
			array_splice($pieces, $i, 3, array($pieces[$i] . $pieces[$i + 1] . $pieces[$i + 2]));
		}
	}

	$flat = function ($s) {
		return preg_replace('/\s+/u', ' ', $s);
	};

	$hits = array();
	for ($i = 1; $i < count($pieces); $i += 2)
	{
		$before = $flat(implode('', array_slice($pieces, 0, $i)));
		$after  = $flat(implode('', array_slice($pieces, $i + 1)));

		$prefix = mb_substr($before, -$context);
		if (mb_strlen($before) > $context)
		{
			$prefix = preg_replace('/^\S*\s/u', '', $prefix);
		}
		$suffix = mb_substr($after, 0, $context);
		if (mb_strlen($after) > $context)
		{
			$suffix = preg_replace('/\s\S*$/u', '', $suffix);
		}

		$start = strlen(implode('', array_slice($pieces, 0, $i)));

		$hits[] = array(ltrim($prefix), $flat($pieces[$i]), rtrim($suffix), $start, $start + strlen($pieces[$i]));
	}

	return $hits;
}

//----------------------------------------------------------------------------------------
// The words of a text, for matching texts from different OCR: lower case, without
// accents, split at anything not a letter or digit, so "Leaf-" and "leaf" match. Each
// is [word, start, end], start and end being where it is in the text (in bytes).
function match_words($text)
{
	preg_match_all('/[\p{L}\p{N}]+/u', $text, $m, PREG_OFFSET_CAPTURE);

	$words = array();
	foreach ($m[0] as $w)
	{
		$folded = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize(mb_strtolower($w[0]), Normalizer::FORM_D));
		$words[] = array($folded, $w[1], $w[1] + strlen($w[0]));
	}
	return $words;
}

//----------------------------------------------------------------------------------------
// The OCR words of some pages, as PageID => list of boxes [x, y, w, h, text, line]
function pages_word_boxes($pageids)
{
	$result = array();
	if (count($pageids) == 0)
	{
		return $result;
	}

	$stmt = words_pdo()->prepare('SELECT PageID, words FROM page WHERE PageID IN ('
		. implode(',', array_fill(0, count($pageids), '?')) . ')');
	$stmt->execute(array_values($pageids));

	foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row)
	{
		$boxes = array();
		foreach (json_decode(gzdecode($row[1]))->lines as $l => $line)
		{
			foreach ($line as $word)
			{
				$word[] = $l;
				$boxes[] = $word;
			}
		}
		$result[$row[0]] = $boxes;
	}
	return $result;
}

//----------------------------------------------------------------------------------------
// Matches a page's text (Elasticsearch's copy, BHL's OCR) to its OCR word boxes (the
// Internet Archive's), which mostly have the same words, but not always. hit_boxes()
// gives the boxes of the words in a part of the text.
class TextBoxes
{
	var $text_words;  // match_words() of the text
	var $boxes;       // [x, y, w, h, text, line]
	var $box_words;   // [word, box index], match_words() of each box's text
	var $same;        // whether the text and the boxes have the same words

	function __construct($text, $boxes)
	{
		$this->text_words = match_words($text);
		$this->boxes = $boxes;
		$this->box_words = array();
		foreach ($boxes as $b => $box)
		{
			foreach (match_words($box[4]) as $w)
			{
				$this->box_words[] = array($w[0], $b);
			}
		}

		$this->same = count($this->text_words) == count($this->box_words);
		for ($i = 0; $this->same && $i < count($this->text_words); $i++)
		{
			$this->same = $this->text_words[$i][0] == $this->box_words[$i][0];
		}
	}

	// Where a run of words is in a list of words, as the indexes of its first words
	static function find($run, $words)
	{
		$found = array();
		$n = count($run);
		for ($i = 0; $i + $n <= count($words); $i++)
		{
			$j = 0;
			while ($j < $n && $words[$i + $j][0] == $run[$j])
			{
				$j++;
			}
			if ($j == $n)
			{
				$found[] = $i;
			}
		}
		return $found;
	}

	// The boxes of the words in the text from byte $start to $end, as boxes on the
	// canvas, [x, y, w, h], one for each line they are on; empty if not found
	function hit_boxes($start, $end)
	{
		// The hit's words in the text
		$first = -1;
		$last = -1;
		foreach ($this->text_words as $i => $w)
		{
			if ($w[2] > $start && $w[1] < $end)
			{
				if ($first < 0)
				{
					$first = $i;
				}
				$last = $i;
			}
		}
		if ($first < 0)
		{
			return array();
		}

		// The same words in the boxes: in the same place if the words are the same,
		// otherwise the same occurrence of them (the 2nd if it's the 2nd in the text),
		// or the nearest in proportion if they occur a different number of times
		$at = -1;
		if ($this->same)
		{
			$at = $first;
		}
		else
		{
			$run = array();
			for ($i = $first; $i <= $last; $i++)
			{
				$run[] = $this->text_words[$i][0];
			}
			$in_text  = self::find($run, $this->text_words);
			$in_boxes = self::find($run, $this->box_words);

			if (count($in_boxes) == count($in_text))
			{
				$at = $in_boxes[array_search($first, $in_text)];
			}
			elseif (count($in_boxes) > 0)
			{
				$where = $first / count($this->text_words);
				$best = PHP_INT_MAX;
				foreach ($in_boxes as $i)
				{
					$d = abs($i / count($this->box_words) - $where);
					if ($d < $best)
					{
						$best = $d;
						$at = $i;
					}
				}
			}
		}
		if ($at < 0)
		{
			return array();
		}

		// One box for each line, around its words
		$lines = array();
		for ($i = $at; $i <= $at + $last - $first; $i++)
		{
			$box = $this->boxes[$this->box_words[$i][1]];
			$l = $box[5];
			if (!isset($lines[$l]))
			{
				$lines[$l] = array($box[0], $box[1], $box[0] + $box[2], $box[1] + $box[3]);
			}
			else
			{
				$lines[$l] = array(min($lines[$l][0], $box[0]), min($lines[$l][1], $box[1]),
					max($lines[$l][2], $box[0] + $box[2]), max($lines[$l][3], $box[1] + $box[3]));
			}
		}

		$result = array();
		foreach ($lines as $r)
		{
			$result[] = array($r[0], $r[1], $r[2] - $r[0], $r[3] - $r[1]);
		}
		return $result;
	}
}

//----------------------------------------------------------------------------------------
// The hits for $q in the pages of an item or article ($scope 'item' or 'part'), in page
// order, as a IIIF Content Search 2.0 AnnotationPage: for each hit, a highlighting
// annotation of its words on the page (a box for each line it is on, or the whole
// canvas if its words can't be found among the page's OCR boxes) and, in
// "annotations", a contextualizing annotation quoting it with its prefix and suffix.
// $from counts pages with hits, SEARCH_PAGE_SIZE at a time; "next" gives the rest.
function search_inside($scope, $id, $q, $from = 0)
{
	global $config;

	$elastic = new ElasticSearch($config['elastic_options']);

	// Characters that are not in OCR text, to mark the matches
	$pre  = "\u{E000}";
	$post = "\u{E001}";

	$query = array(
		'from' => $from,
		'size' => SEARCH_PAGE_SIZE,
		'query' => array(
			'bool' => array(
				'filter' => array(
					array('term' => array('type' => 'page')),
					array('term' => array($scope == 'part' ? 'partid' : 'itemid' => $scope . '_' . (int)$id)),
				),
				// As search_text() in search.php
				'must' => array(
					'match' => array('text' => array('query' => $q, 'minimum_should_match' => '3<75%'))
				),
			)
		),
		'sort' => array(array('seq' => 'asc')),
		'_source' => array('seq'),
		'highlight' => array(
			'pre_tags' => array($pre),
			'post_tags' => array($post),
			'fields' => array('text' => array('number_of_fragments' => 0)),
		),
	);

	$resp = $elastic->send('POST', '_search', json_encode($query));

	if ($resp[0] != 200)
	{
		throw new Exception('Elasticsearch returned HTTP ' . $resp[0]);
	}

	$base = site_url('iiif-search.php/' . $scope . '/' . (int)$id . '?q=' . rawurlencode($q));

	$page = new stdclass;
	$page->{'@context'} = SEARCH_CONTEXT;
	$page->id    = $base . ($from > 0 ? '&from=' . (int)$from : '');
	$page->type  = 'AnnotationPage';
	$page->items = array();

	$quotes = array();

	$pageids = array();
	foreach ($resp[1]->hits->hits as $hit)
	{
		$pageids[] = id_number($hit->_id);
	}
	$word_boxes = pages_word_boxes($pageids);

	foreach ($resp[1]->hits->hits as $hit)
	{
		if (!isset($hit->highlight->text[0]))
		{
			continue;
		}
		$pageid = id_number($hit->_id);
		$canvas = page_canvas_id($pageid);

		$highlighted = $hit->highlight->text[0];
		$boxes = isset($word_boxes[$pageid])
			? new TextBoxes(str_replace(array($pre, $post), '', $highlighted), $word_boxes[$pageid])
			: null;

		foreach (text_hits($highlighted, $pre, $post) as $n => $quote)
		{
			$hit_id = site_url('iiif/search/' . $pageid . '/' . ($n + 1) . '/hit?q=' . rawurlencode($q));

			// Its words' boxes, if we can find them
			$target = array();
			foreach ($boxes ? $boxes->hit_boxes($quote[3], $quote[4]) : array() as $box)
			{
				$target[] = $canvas . '#xywh=' . implode(',', $box);
			}
			if (count($target) == 0)
			{
				$target = $canvas;
			}
			elseif (count($target) == 1)
			{
				$target = $target[0];
			}

			$page->items[] = (object)array(
				'id'         => $hit_id,
				'type'       => 'Annotation',
				'motivation' => 'highlighting',
				'target'     => $target,
			);

			$quotes[] = (object)array(
				'id'         => str_replace('/hit?', '/context?', $hit_id),
				'type'       => 'Annotation',
				'motivation' => 'contextualizing',
				'target'     => (object)array(
					'type'     => 'SpecificResource',
					'source'   => $hit_id,
					'selector' => array((object)array(
						'type'   => 'TextQuoteSelector',
						'prefix' => $quote[0],
						'exact'  => $quote[1],
						'suffix' => $quote[2],
					)),
				),
			);
		}
	}

	if (count($quotes) > 0)
	{
		$page->annotations = array((object)array('type' => 'AnnotationPage', 'items' => $quotes));
	}

	// Paging, if the hits are on more pages than one response holds
	$total = $resp[1]->hits->total->value;
	if ($total > SEARCH_PAGE_SIZE)
	{
		$last = (int)(($total - 1) / SEARCH_PAGE_SIZE) * SEARCH_PAGE_SIZE;
		$page->partOf = array((object)array(
			'id'    => $base,
			'type'  => 'AnnotationCollection',
			'first' => (object)array('id' => $base, 'type' => 'AnnotationPage'),
			'last'  => (object)array('id' => $base . '&from=' . $last, 'type' => 'AnnotationPage'),
		));
		if ($from + SEARCH_PAGE_SIZE < $total)
		{
			$page->next = (object)array('id' => $base . '&from=' . ($from + SEARCH_PAGE_SIZE), 'type' => 'AnnotationPage');
		}
		if ($from > 0)
		{
			$page->prev = (object)array('id' => $base . ($from > SEARCH_PAGE_SIZE ? '&from=' . ($from - SEARCH_PAGE_SIZE) : ''), 'type' => 'AnnotationPage');
		}
	}

	return $page;
}

?>
