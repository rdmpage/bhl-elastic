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

?>
