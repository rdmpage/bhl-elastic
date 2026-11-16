<?php

// Single records as JSON-LD, built from Elasticsearch alone. The API returns these as
// is and the web pages render them.

require_once (dirname(__FILE__) . '/search.php');

//----------------------------------------------------------------------------------------
// A title (a book or journal) and all its items, as JSON-LD, or null if there is no such
// title. $titleid is BHL's number, e.g. 9243.
//
// The title is a Book or Periodical. Its items are in hasPart, each a PublicationVolume
// whose provider is the library that contributed it, ordered by year then volume. An
// item's copyrightNotice is its copyright status (one of a few categories, see
// copyright_status() in bhl.php), license its Creative Commons licence, if any, and
// copyrightHolder the rights holder BHL names.
// The title itself is built by title_to_jsonld() in search.php.
function get_title($titleid)
{
	global $config;

	$elastic = new ElasticSearch($config['elastic_options']);

	$id = 'title_' . (int)$titleid;

	$docs = fetch_docs($elastic, array($id), title_fields());

	if (!isset($docs[$id]))
	{
		return null;
	}

	$work = title_to_jsonld($id, $docs[$id]);
	$work = (object)array_merge(array('@context' => create_context()), (array)$work);

	// All its items; no title has more than about 1,300, well under Elasticsearch's
	// limit of 10,000 hits
	$query = array(
		'size' => 10000,
		'query' => array(
			'bool' => array(
				'filter' => array(
					array('term' => array('type' => 'item')),
					array('term' => array('titleid' => $id)),
				)
			)
		),
		'_source' => array('volume', 'year', 'thumbnail', 'institutions', 'copyright', 'license', 'rights_holder'),
	);

	$resp = $elastic->send('POST', '_search', json_encode($query));

	if ($resp[0] != 200)
	{
		throw new Exception('Search failed (HTTP ' . $resp[0] . ')');
	}

	$hits = $resp[1]->hits->hits;

	usort($hits, function ($a, $b) {
		$year_a = isset($a->_source->year) ? $a->_source->year : 9999;
		$year_b = isset($b->_source->year) ? $b->_source->year : 9999;
		if ($year_a != $year_b)
		{
			return $year_a - $year_b;
		}

		$volume_a = isset($a->_source->volume) ? $a->_source->volume : '';
		$volume_b = isset($b->_source->volume) ? $b->_source->volume : '';
		if ($volume_a != $volume_b)
		{
			return strnatcasecmp($volume_a, $volume_b);
		}

		return id_number($a->_id) - id_number($b->_id);
	});

	$work->hasPart = array();

	foreach ($hits as $hit)
	{
		$item = new stdclass;
		$item->id   = 'https://www.biodiversitylibrary.org/item/' . id_number($hit->_id);
		$item->type = 'PublicationVolume';

		if (isset($hit->_source->volume))    { $item->volumeNumber = $hit->_source->volume; }
		if (isset($hit->_source->year))      { $item->datePublished = (string)$hit->_source->year; }
		if (isset($hit->_source->thumbnail)) { $item->thumbnailUrl = $hit->_source->thumbnail; }

		if (isset($hit->_source->institutions))
		{
			$item->provider = (object)array('type' => 'Organization', 'name' => $hit->_source->institutions[0]);
		}

		if (isset($hit->_source->copyright)) { $item->copyrightNotice = $hit->_source->copyright; }
		if (isset($hit->_source->license))   { $item->license = $hit->_source->license; }

		if (isset($hit->_source->rights_holder))
		{
			$item->copyrightHolder = (object)array('name' => $hit->_source->rights_holder);
		}

		$work->hasPart[] = $item;
	}

	return $work;
}

//----------------------------------------------------------------------------------------
// An item (a scanned volume), its articles and all its pages, as JSON-LD, or null if
// there is no such item. $itemid is BHL's number, e.g. 222763.
//
// The item is a PublicationVolume (see item_to_jsonld() in search.php) whose articles
// are in hasPart, in their order in the item. It is also an ItemList of its pages, in
// scan order (position is the page's sequence number). Each page is a CreativeWork
// named by its printed page label, its genre the page types BHL gives it ("Text",
// "Illustration", ...), and isPartOf the articles it is in, if any, so pages can be
// mapped onto articles to show coverage.
function get_item($itemid)
{
	global $config;

	$elastic = new ElasticSearch($config['elastic_options']);

	$id = 'item_' . (int)$itemid;

	$docs = fetch_docs($elastic, array($id), array('titleid', 'volume', 'year', 'thumbnail', 'institutions', 'copyright', 'license', 'rights_holder'));

	if (!isset($docs[$id]))
	{
		return null;
	}

	$item     = $docs[$id];
	$titleids = isset($item->titleid) ? $item->titleid : array();
	$titles   = fetch_docs($elastic, $titleids, array('name'));

	$work = item_to_jsonld($id, $item, $titleids, $titles);
	$work = (object)array_merge(array('@context' => create_context()), (array)$work);

	if (isset($item->institutions))
	{
		$work->provider = (object)array('type' => 'Organization', 'name' => $item->institutions[0]);
	}
	if (isset($item->copyright))     { $work->copyrightNotice = $item->copyright; }
	if (isset($item->license))       { $work->license = $item->license; }
	if (isset($item->rights_holder)) { $work->copyrightHolder = (object)array('name' => $item->rights_holder); }

	// Articles and pages. No item has more than a few thousand pages, well under
	// Elasticsearch's limit of 10,000 hits.
	$hits = array();
	foreach (array('part', 'page') as $type)
	{
		$query = array(
			'size' => 10000,
			'query' => array(
				'bool' => array(
					'filter' => array(
						array('term' => array('type' => $type)),
						array('term' => array('itemid' => $id)),
					)
				)
			),
			'_source' => $type == 'part'
				? array('seq', 'name', 'authors', 'container', 'tags', 'volume', 'issue', 'year', 'pages', 'doi', 'thumbnail')
				: array('seq', 'name', 'tags', 'thumbnail', 'partid'),
			'sort' => array(array('seq' => array('order' => 'asc', 'missing' => '_last'))),
		);

		$resp = $elastic->send('POST', '_search', json_encode($query));

		if ($resp[0] != 200)
		{
			throw new Exception('Search failed (HTTP ' . $resp[0] . ')');
		}

		$hits[$type] = $resp[1]->hits->hits;
	}

	// Articles, in the order of their first page (their own sequence numbers are not
	// always set); their container is this item, so isPartOf is left out
	$first = array();
	foreach ($hits['page'] as $i => $hit)
	{
		if (isset($hit->_source->partid))
		{
			foreach ($hit->_source->partid as $partid)
			{
				if (!isset($first[$partid]))
				{
					$first[$partid] = $i;
				}
			}
		}
	}

	usort($hits['part'], function ($a, $b) use ($first) {
		$first_a = isset($first[$a->_id]) ? $first[$a->_id] : PHP_INT_MAX;
		$first_b = isset($first[$b->_id]) ? $first[$b->_id] : PHP_INT_MAX;
		if ($first_a != $first_b)
		{
			return $first_a < $first_b ? -1 : 1;
		}
		return id_number($a->_id) - id_number($b->_id);
	});

	$work->hasPart = array();
	foreach ($hits['part'] as $hit)
	{
		$part = part_to_jsonld($hit->_id, $hit->_source, $titleids, $titles);
		unset($part->isPartOf);
		$work->hasPart[] = $part;
	}

	// Pages
	$work->type = array($work->type, 'ItemList');
	$work->numberOfItems = count($hits['page']);
	$work->itemListElement = array();

	foreach ($hits['page'] as $hit)
	{
		$work->itemListElement[] = page_list_element($hit, count($work->itemListElement) + 1);
	}

	return $work;
}

//----------------------------------------------------------------------------------------
// A page search hit as a ListItem of an item or article: the page is a CreativeWork
// named by its printed page label, its genre the page types BHL gives it, and isPartOf
// the articles it is in, if any. Its position is the page's sequence number in the
// item, or $position if it has none.
function page_list_element($hit, $position)
{
	$page = new stdclass;
	$page->id   = 'https://www.biodiversitylibrary.org/page/' . id_number($hit->_id);
	$page->type = 'CreativeWork';

	if (isset($hit->_source->name))      { $page->name = $hit->_source->name; }
	if (isset($hit->_source->thumbnail)) { $page->thumbnailUrl = $hit->_source->thumbnail; }
	if (isset($hit->_source->tags))      { $page->genre = $hit->_source->tags; }

	if (isset($hit->_source->partid))
	{
		$page->isPartOf = array();
		foreach ($hit->_source->partid as $partid)
		{
			$page->isPartOf[] = (object)array('id' => 'https://www.biodiversitylibrary.org/part/' . id_number($partid));
		}
	}

	$element = new stdclass;
	$element->type = 'ListItem';
	$element->position = isset($hit->_source->seq) ? $hit->_source->seq : $position;
	$element->item = $page;

	return $element;
}

//----------------------------------------------------------------------------------------
// A part (an article, chapter...) and its pages, as JSON-LD, or null if there is no such
// part. $partid is BHL's number, e.g. 431953.
//
// The part is built by part_to_jsonld() in search.php: a ScholarlyArticle (or Chapter,
// or CreativeWork) whose isPartOf is the journal, volume and issue it was published in.
// The scanned item (PublicationVolume) that holds it is added to isPartOf, so the
// article is also in the item's hasPart (see get_item()). Its pages are an ItemList,
// in scan order, as for an item.
//
// Room for more: works it cites (citation), works citing it, places and taxa it
// mentions.
function get_part($partid)
{
	global $config;

	$elastic = new ElasticSearch($config['elastic_options']);

	$id = 'part_' . (int)$partid;

	$docs = fetch_docs($elastic, array($id), array('itemid', 'titleid', 'seq', 'name', 'authors', 'container', 'tags', 'volume', 'issue', 'year', 'pages', 'doi', 'thumbnail'));

	if (!isset($docs[$id]))
	{
		return null;
	}

	$part     = $docs[$id];
	$titleids = isset($part->titleid) ? $part->titleid : array();
	$itemid   = isset($part->itemid) ? $part->itemid : null;

	// The item and the titles, for names
	$others = fetch_docs($elastic, array_merge($titleids, $itemid ? array($itemid) : array()), array('titleid', 'volume', 'year', 'name'));

	$work = part_to_jsonld($id, $part, $titleids, $others);
	$work = (object)array_merge(array('@context' => create_context()), (array)$work);

	if ($itemid && isset($others[$itemid]))
	{
		$item = $others[$itemid];
		$item_titleids = isset($item->titleid) ? $item->titleid : $titleids;
		$work->isPartOf = array($work->isPartOf, item_to_jsonld($itemid, $item, $item_titleids, $others));
	}

	// Pages, in scan order
	$query = array(
		'size' => 10000,
		'query' => array(
			'bool' => array(
				'filter' => array(
					array('term' => array('type' => 'page')),
					array('term' => array('partid' => $id)),
				)
			)
		),
		'_source' => array('seq', 'name', 'tags', 'thumbnail', 'partid'),
		'sort' => array(array('seq' => array('order' => 'asc', 'missing' => '_last'))),
	);

	$resp = $elastic->send('POST', '_search', json_encode($query));

	if ($resp[0] != 200)
	{
		throw new Exception('Search failed (HTTP ' . $resp[0] . ')');
	}

	$work->type = array($work->type, 'ItemList');
	$work->numberOfItems = count($resp[1]->hits->hits);
	$work->itemListElement = array();

	foreach ($resp[1]->hits->hits as $hit)
	{
		$work->itemListElement[] = page_list_element($hit, count($work->itemListElement) + 1);
	}

	return $work;
}

?>
