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

require_once (dirname(__FILE__) . '/search.php');
require_once (dirname(__FILE__) . '/records.php');

define('IIIF_CONTEXT', 'http://iiif.io/api/presentation/3/context.json');

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
// page URL is given
function viewer_url($manifest, $page_url = '')
{
	return 'viewer.html?manifest=' . rawurlencode($manifest)
		. ($page_url != '' ? '#canvas=' . rawurlencode(page_canvas_id(url_number($page_url))) : '');
}

//----------------------------------------------------------------------------------------
// The manifest for a BHL part or item URL, relative to this site
function manifest_path($url)
{
	return 'iiif.php?' . (preg_match('/\/part\//', $url) ? 'part' : 'item') . '=' . url_number($url);
}

//----------------------------------------------------------------------------------------
// One page of an item's JSON-LD as a canvas. $seq is its place in the item.
function page_canvas($page, $seq, $sizes)
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

	return $canvas;
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
function item_manifest($itemid)
{
	$item = get_item($itemid);
	return $item ? item_manifest_from($item, $itemid) : null;
}

//----------------------------------------------------------------------------------------
// A manifest from an item's JSON-LD (from get_item())
function item_manifest_from($item, $itemid)
{
	$sizes = item_page_sizes($itemid);
	if (!$sizes)
	{
		throw new NoManifest('No page sizes for item ' . (int)$itemid);
	}

	$label = $item->name . (isset($item->volumeNumber) ? ' ' . $item->volumeNumber : '');

	$manifest = new stdclass;
	$manifest->{'@context'} = IIIF_CONTEXT;
	$manifest->id       = site_url('iiif.php?item=' . (int)$itemid);
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
		$canvas = page_canvas($page, $element->position, $sizes);
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
function part_manifest($partid)
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

	$item_manifest = item_manifest_from($item, $itemid);

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
	$manifest->{'@context'} = IIIF_CONTEXT;
	$manifest->id       = site_url('iiif.php?part=' . (int)$partid);
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

?>
