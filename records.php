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
				: array('seq', 'name', 'tags', 'thumbnail', 'partid', 'locations', 'names', 'new_names'),
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
// Points from the index (locations, a list of {lat, lon}) as schema.org Places
function places_jsonld($locations)
{
	$places = array();
	foreach ($locations as $point)
	{
		$places[] = (object)array(
			'type' => 'Place',
			'geo'  => (object)array('type' => 'GeoCoordinates', 'latitude' => $point->lat, 'longitude' => $point->lon),
		);
	}
	return $places;
}

//----------------------------------------------------------------------------------------
// Give $work the scientific names in $source (a page, part or item document; see
// tagging.php) as Taxa: mentions, every name in its text, and about, the names it
// publishes as new (sp. nov., comb. nov., nom. nov., ...), which are what a work that
// describes a taxon is about. Names are as taxonfinder reads them, not checked against a
// list of names.
function add_taxa_jsonld($work, $source)
{
	$taxa = function ($names) {
		$list = array();
		foreach ($names as $name)
		{
			$list[] = (object)array('type' => 'Taxon', 'name' => $name);
		}
		return $list;
	};

	if (isset($source->new_names)) { $work->about    = $taxa($source->new_names); }
	if (isset($source->names))     { $work->mentions = $taxa($source->names); }
}

//----------------------------------------------------------------------------------------
// A page search hit as a ListItem of an item or article: the page is a CreativeWork
// named by its printed page label, its genre the page types BHL gives it, and isPartOf
// the articles it is in, if any, and spatialCoverage the points (latitude and longitude
// pairs) in its text, if any (see geotag.php), and mentions and about the names in it
// (see add_taxa_jsonld()). Its position is the page's sequence
// number in the item, or $position if it has none.
function page_list_element($hit, $position)
{
	$page = new stdclass;
	$page->id   = 'https://www.biodiversitylibrary.org/page/' . id_number($hit->_id);
	$page->type = 'CreativeWork';

	if (isset($hit->_source->name))      { $page->name = $hit->_source->name; }
	if (isset($hit->_source->thumbnail)) { $page->thumbnailUrl = $hit->_source->thumbnail; }
	if (isset($hit->_source->tags))      { $page->genre = $hit->_source->tags; }
	if (isset($hit->_source->locations)) { $page->spatialCoverage = places_jsonld($hit->_source->locations); }
	add_taxa_jsonld($page, $hit->_source);

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
// in scan order, as for an item. spatialCoverage is the points (latitude and longitude
// pairs) in its pages' text, as are each page's; mentions is the names in its pages'
// text, and about those it publishes as new (see add_taxa_jsonld()). All three are
// merged from its pages.
//
// Room for more: works it cites (citation), works citing it.
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
		'_source' => array('seq', 'name', 'tags', 'thumbnail', 'partid', 'locations', 'names', 'new_names'),
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

	// The points and names in its pages' text, merged here rather than read from the
	// part's own fields (filled in by load-annotations.php --rollup, for searching
	// articles), so the page is right even if the rollup has not been run since the
	// pages were tagged
	$merged = array('locations' => array(), 'names' => array(), 'new_names' => array());

	foreach ($resp[1]->hits->hits as $hit)
	{
		$work->itemListElement[] = page_list_element($hit, count($work->itemListElement) + 1);

		foreach (array_keys($merged) as $field)
		{
			foreach (isset($hit->_source->{$field}) ? $hit->_source->{$field} : array() as $value)
			{
				$merged[$field][is_object($value) ? $value->lat . ',' . $value->lon : $value] = $value;
			}
		}
	}

	$source = new stdclass;
	foreach ($merged as $field => $values)
	{
		if (count($values) > 0)
		{
			$source->{$field} = array_values($values);
		}
	}
	if (isset($source->locations))
	{
		$work->spatialCoverage = places_jsonld($source->locations);
	}
	add_taxa_jsonld($work, $source);

	return $work;
}

//----------------------------------------------------------------------------------------
// A person's name as BHL gives it, as a CSL name. BHL names are mostly "Family, Given",
// sometimes with life dates and a fuller form of the given name, e.g.
// "Benoist, R. (Raymond), 1881-1970" -> family "Benoist", given "R."; "Richardson, John,
// Sir, 1787-1865" -> family "Richardson", given "John". A single word is a family name;
// anything else without a comma is kept whole as a literal.
function csl_name($name)
{
	$name = preg_replace('/\s*\([^)]*\)/u', '', $name);
	$name = preg_replace('/[,\s]*\d{4}\??-?(\d{4}\??)?\.?$/u', '', $name);
	$name = trim($name, " \t,");

	$parts = array_map('trim', explode(',', $name));

	if (count($parts) > 1 && $parts[0] != '' && $parts[1] != '')
	{
		return array('family' => $parts[0], 'given' => $parts[1]);
	}
	if (!preg_match('/\s/u', $name))
	{
		return array('family' => $name);
	}
	return array('literal' => $name);
}

//----------------------------------------------------------------------------------------
// A work's JSON-LD (from get_part()) as CSL-JSON, for formatting citations (with
// citation.js in the part page, or any CSL processor). Returns an array of one item, as
// CSL-JSON is a list.
//
// Where it was published is read up isPartOf: an issue, a volume, then the journal or
// book. The scanned item that also appears in isPartOf (a /item/ URL) is skipped, as it
// is BHL's volume, not a bibliographic one.
function jsonld_to_csl($work)
{
	$csl = new stdclass;
	$csl->id = 'bhl-' . preg_replace('/^.*\/(\w+)\/(\d+)$/', '$1-$2', $work->id);

	$types = is_array($work->type) ? $work->type : array($work->type);
	$map   = array('ScholarlyArticle' => 'article-journal', 'Chapter' => 'chapter');
	$csl->type = isset($map[$types[0]]) ? $map[$types[0]] : 'document';

	if (isset($work->name)) { $csl->title = $work->name; }

	if (isset($work->author))
	{
		$csl->author = array();
		foreach ($work->author as $author)
		{
			$csl->author[] = (object)csl_name($author->name);
		}
	}

	if (isset($work->datePublished) && preg_match('/^(\d{4})/', $work->datePublished, $m))
	{
		$csl->issued = (object)array('date-parts' => array(array((int)$m[1])));
	}

	// Where it was published
	$containers = isset($work->isPartOf) ? (is_array($work->isPartOf) ? $work->isPartOf : array($work->isPartOf)) : array();
	$container = null;
	foreach ($containers as $c)
	{
		if (!(isset($c->id) && preg_match('/\/item\/\d+$/', $c->id)))
		{
			$container = $c;
			break;
		}
	}
	while ($container)
	{
		if (is_array($container))
		{
			$container = count($container) > 0 ? $container[0] : null;
			continue;
		}
		if (isset($container->issueNumber))  { $csl->issue = $container->issueNumber; }
		if (isset($container->volumeNumber)) { $csl->volume = $container->volumeNumber; }
		if (isset($container->name) && !isset($csl->{'container-title'}))
		{
			$csl->{'container-title'} = $container->name;
		}
		$container = isset($container->isPartOf) ? $container->isPartOf : null;
	}

	if (isset($work->pagination)) { $csl->page = str_replace('–', '-', $work->pagination); }

	if (isset($work->sameAs) && preg_match('/^https:\/\/doi.org\/(.*)$/', $work->sameAs, $m))
	{
		$csl->DOI = $m[1];
	}

	$csl->URL = $work->id;
	$csl->source = 'Biodiversity Heritage Library';

	return array($csl);
}

?>
