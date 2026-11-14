<?php

// Search, returning results as JSON-LD (a schema.org ItemList). The API returns this
// as is and the web page renders it, so both always show the same thing.

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/elastic.php');
require_once (dirname(__FILE__) . '/bhl.php');

//----------------------------------------------------------------------------------------
function create_context()
{
	$context = new stdclass;
	$context->{'@vocab'} = 'http://schema.org/';

	// flatten id and type using aliases
	$context->id = '@id';
	$context->type = '@type';

	// Google's term for the relevance score of a search hit
	$context->resultScore = 'http://schema.googleapis.com/resultScore';

	return $context;
}

//----------------------------------------------------------------------------------------
// Documents by id, as array(id => _source), in one _mget. Ids not in the index are
// left out.
function fetch_docs($elastic, $ids, $fields)
{
	$docs = array();

	if (count($ids) == 0)
	{
		return $docs;
	}

	$request = array('ids' => array_values(array_unique($ids)));

	$resp = $elastic->send('POST', '_mget?_source=' . implode(',', $fields), json_encode($request));

	if ($resp[0] == 200)
	{
		foreach ($resp[1]->docs as $doc)
		{
			if ($doc->found)
			{
				$docs[$doc->_id] = $doc->_source;
			}
		}
	}

	return $docs;
}

//----------------------------------------------------------------------------------------
// A matching page: label, position in the item, thumbnail, and the highlighted text.
// The highlighter has HTML-escaped the OCR, so text is safe to show as HTML.
function page_to_jsonld($hit)
{
	$source = $hit->_source;

	$page = new stdclass;
	$page->id   = 'https://www.biodiversitylibrary.org/page/' . id_number($hit->_id);
	$page->type = 'CreativeWork';

	if (isset($source->name))      { $page->name = $source->name; }
	if (isset($source->seq))       { $page->position = $source->seq; }
	if (isset($source->thumbnail)) { $page->thumbnailUrl = $source->thumbnail; }

	if (isset($hit->highlight->text))
	{
		$page->text = $hit->highlight->text;
	}

	return $page;
}

//----------------------------------------------------------------------------------------
// The titles an item belongs to, e.g. a monograph and the series it is in.
function titles_to_jsonld($titleids, $docs)
{
	$titles = array();

	foreach ($titleids as $id)
	{
		$title = new stdclass;
		$title->id   = 'https://www.biodiversitylibrary.org/bibliography/' . id_number($id);
		$title->type = 'CreativeWork';

		if (isset($docs[$id]->name))
		{
			$title->name = $docs[$id]->name;
		}

		$titles[] = $title;
	}

	return $titles;
}

//----------------------------------------------------------------------------------------
// A title's identifiers as JSON-LD: those that resolve go in sameAs, ISSN and ISBN in
// their own properties (issn is only defined for serials), and the rest in identifier.
function title_identifiers_to_jsonld($work, $title)
{
	$same_as = array();

	if (isset($title->doi))
	{
		$same_as[] = 'https://doi.org/' . $title->doi;
	}

	if (isset($title->identifiers))
	{
		foreach ($title->identifiers as $identifier)
		{
			list($name, $value) = explode(':', $identifier, 2);

			if ($name == 'oclc')
			{
				$same_as[] = 'https://www.worldcat.org/oclc/' . $value;
			}
			elseif ($name == 'wikidata')
			{
				$same_as[] = 'http://www.wikidata.org/entity/' . $value;
			}
			elseif (in_array($name, array('issn', 'eissn', 'issn-l')) && $work->type == 'Periodical')
			{
				// the linking ISSN is often the same as the ISSN
				if (!isset($work->issn) || !in_array($value, $work->issn))
				{
					$work->issn[] = $value;
				}
			}
			elseif ($name == 'isbn' && $work->type == 'Book')
			{
				$work->isbn[] = $value;
			}
			else
			{
				$property = new stdclass;
				$property->type = 'PropertyValue';
				$property->propertyID = $name;
				$property->value = $value;
				$work->identifier[] = $property;
			}
		}
	}

	if (count($same_as) > 0)
	{
		$work->sameAs = $same_as;
	}
}

//----------------------------------------------------------------------------------------
// The fields of a title document that title_to_jsonld() uses.
function title_fields()
{
	return array('name', 'fullname', 'authors', 'tags', 'language', 'subjects', 'identifiers', 'doi', 'year', 'end_year', 'thumbnail');
}

//----------------------------------------------------------------------------------------
// A title document as JSON-LD, without its items: a Book or Periodical. A serial's years
// are startDate and endDate; schema.org has no such terms for a book, so a book has
// only datePublished, its first year.
function title_to_jsonld($id, $title)
{
	$work = new stdclass;
	$work->id   = 'https://www.biodiversitylibrary.org/bibliography/' . id_number($id);
	$work->type = isset($title->tags) ? $title->tags[0] : 'Book';

	if (isset($title->name))     { $work->name = $title->name; }
	if (isset($title->fullname)) { $work->alternateName = $title->fullname; }

	// BHL records whether an author is a person, organisation or meeting, but the index
	// keeps only the names, so authors are left untyped
	if (isset($title->authors))
	{
		$work->author = array();
		foreach ($title->authors as $name)
		{
			$work->author[] = (object)array('name' => $name);
		}
	}

	if ($work->type == 'Periodical')
	{
		if (isset($title->year))     { $work->startDate = (string)$title->year; }
		if (isset($title->end_year)) { $work->endDate = (string)$title->end_year; }
	}
	elseif (isset($title->year))
	{
		$work->datePublished = (string)$title->year;
	}

	if (isset($title->language))  { $work->inLanguage = $title->language; }
	if (isset($title->subjects))  { $work->keywords = $title->subjects; }
	if (isset($title->thumbnail)) { $work->thumbnailUrl = $title->thumbnail; }

	title_identifiers_to_jsonld($work, $title);

	return $work;
}

//----------------------------------------------------------------------------------------
// Which of an item's titles a part's journal name refers to. An item can be in a
// monograph and the series it belongs to, e.g. "A systematic study of Flourensia" and
// "Fieldiana", and the part's journal is "Fieldiana. Botany". Prefer a title with the
// same name, then one whose name starts the journal name (or vice versa), else the
// first.
function container_titleid($container, $titleids, $docs)
{
	if (count($titleids) == 0)
	{
		return null;
	}

	$normalise = function ($name) {
		return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)));
	};

	$container = $normalise($container);

	$prefix = null;
	foreach ($titleids as $id)
	{
		if (!isset($docs[$id]->name))
		{
			continue;
		}

		$name = $normalise($docs[$id]->name);
		if ($name == $container)
		{
			return $id;
		}
		if (!$prefix && $name != '' && (strpos($container, $name) === 0 || strpos($name, $container) === 0))
		{
			$prefix = $id;
		}
	}

	return $prefix ? $prefix : $titleids[0];
}

//----------------------------------------------------------------------------------------
// A part (article, chapter...) as JSON-LD. Volume and issue are nested as
// isPartOf: PublicationIssue -> PublicationVolume -> Periodical, leaving out levels
// we have no value for.
function part_to_jsonld($id, $part, $titleids, $docs)
{
	$work = new stdclass;
	$work->id   = 'https://www.biodiversitylibrary.org/part/' . id_number($id);
	$work->type = 'ScholarlyArticle';

	if ($part)
	{
		if (isset($part->tags) && $part->tags[0] == 'Chapter')
		{
			$work->type = 'Chapter';
		}
		elseif (isset($part->tags) && $part->tags[0] != 'Article')
		{
			$work->type = 'CreativeWork';
		}

		if (isset($part->name)) { $work->name = $part->name; }

		if (isset($part->authors))
		{
			$work->author = array();
			foreach ($part->authors as $name)
			{
				$author = new stdclass;
				$author->type = 'Person';
				$author->name = $name;
				$work->author[] = $author;
			}
		}

		if (isset($part->year))      { $work->datePublished = (string)$part->year; }
		if (isset($part->pages))     { $work->pagination = str_replace('--', '–', $part->pages); }
		if (isset($part->thumbnail)) { $work->thumbnailUrl = $part->thumbnail; }
		if (isset($part->doi))       { $work->sameAs = 'https://doi.org/' . $part->doi; }

		// Container: the journal named on the part, which is one of the BHL titles its
		// item belongs to, or else the item's titles
		if (isset($part->container))
		{
			$container = new stdclass;
			$titleid = container_titleid($part->container, $titleids, $docs);
			if ($titleid)
			{
				$container->id = 'https://www.biodiversitylibrary.org/bibliography/' . id_number($titleid);
			}
			$container->type = 'Periodical';
			$container->name = $part->container;
		}
		else
		{
			$container = titles_to_jsonld($titleids, $docs);
		}

		if (isset($part->volume))
		{
			$volume = new stdclass;
			$volume->type = 'PublicationVolume';
			$volume->volumeNumber = $part->volume;
			$volume->isPartOf = $container;
			$container = $volume;
		}

		if (isset($part->issue))
		{
			$issue = new stdclass;
			$issue->type = 'PublicationIssue';
			$issue->issueNumber = $part->issue;
			$issue->isPartOf = $container;
			$container = $issue;
		}

		$work->isPartOf = $container;
	}

	return $work;
}

//----------------------------------------------------------------------------------------
// An item (a scanned volume) as JSON-LD, named by its first title.
function item_to_jsonld($id, $item, $titleids, $docs)
{
	$work = new stdclass;
	$work->id   = 'https://www.biodiversitylibrary.org/item/' . id_number($id);
	$work->type = 'PublicationVolume';

	$titles = titles_to_jsonld($titleids, $docs);

	if (count($titles) > 0 && isset($titles[0]->name))
	{
		$work->name = $titles[0]->name;
	}

	if ($item)
	{
		if (isset($item->volume))    { $work->volumeNumber = $item->volume; }
		if (isset($item->year))      { $work->datePublished = (string)$item->year; }
		if (isset($item->thumbnail)) { $work->thumbnailUrl = $item->thumbnail; }
	}

	$work->isPartOf = $titles;

	return $work;
}

//----------------------------------------------------------------------------------------
// Search page text. A page matches if it has all the words of a query of up to three
// words (e.g. a binomial), or 75% of the words of a longer one (e.g. a citation, where
// OCR errors and line breaks lose some words). Pages with the words as a phrase, allowing
// up to three words' movement, rank first.
//
// Pages are collapsed into one result per article (or item, for pages not in an
// article), ranked by their best page.
//
// Returns an ItemList; numberOfItems is the (approximate) number of groups, and each
// ListItem's position counts from 1 across all pages of results.
//
// Each article or item is itself also an ItemList of its matching pages (as nested
// OfferCatalogs are in schema.org's examples): numberOfItems is how many of its pages
// matched, and itemListElement holds the best $pages of them, ranked, with scores. Up to
// 100 pages per result (Elasticsearch's index.max_inner_result_window).
function search_text($q, $from = 0, $size = 20, $pages_per_result = 3)
{
	global $config;

	$elastic = new ElasticSearch($config['elastic_options']);

	$pages_per_result = max(1, min(100, $pages_per_result));

	$query = array(
		'from' => $from,
		'size' => $size,
		'query' => array(
			'bool' => array(
				'filter' => array(
					'term' => array('type' => 'page')
				),
				'must' => array(
					'match' => array('text' => array('query' => $q, 'minimum_should_match' => '3<75%'))
				),
				'should' => array(
					'match_phrase' => array('text' => array('query' => $q, 'slop' => 3, 'boost' => 3))
				),
			)
		),
		'_source' => false,
		'collapse' => array(
			'field' => 'group',
			'inner_hits' => array(
				'name' => 'pages',
				'size' => $pages_per_result,
				'_source' => array('titleid', 'name', 'seq', 'thumbnail'),
				'highlight' => array(
					'encoder' => 'html',
					'pre_tags' => array('<em>'),
					'post_tags' => array('</em>'),
					'fields' => array('text' => array('fragment_size' => 150, 'number_of_fragments' => 2)),
				),
			),
		),
		'aggs' => array(
			'groups' => array('cardinality' => array('field' => 'group')),
		),
	);

	$resp = $elastic->send('POST', '_search', json_encode($query));

	if ($resp[0] != 200)
	{
		throw new Exception('Search failed (HTTP ' . $resp[0] . ')');
	}

	$hits = $resp[1]->hits->hits;

	// The part or item each group is, and the titles of the items
	$ids = array();
	foreach ($hits as $hit)
	{
		$ids[] = $hit->fields->group[0];
		$first = $hit->inner_hits->pages->hits->hits[0]->_source;
		if (isset($first->titleid))
		{
			$ids = array_merge($ids, $first->titleid);
		}
	}

	$docs = fetch_docs($elastic, $ids,
		array('name', 'authors', 'container', 'tags', 'volume', 'issue', 'year', 'pages', 'doi', 'thumbnail'));

	$list = new stdclass;
	$list->{'@context'} = create_context();
	$list->type = 'ItemList';
	$list->name = 'Search results for "' . $q . '"';
	$list->numberOfItems = $resp[1]->aggregations->groups->value;
	$list->itemListElement = array();

	foreach ($hits as $i => $hit)
	{
		$group    = $hit->fields->group[0];
		$doc      = isset($docs[$group]) ? $docs[$group] : null;
		$pages    = $hit->inner_hits->pages->hits->hits;
		$titleids = isset($pages[0]->_source->titleid) ? $pages[0]->_source->titleid : array();

		$work = (strpos($group, 'part_') === 0)
			? part_to_jsonld($group, $doc, $titleids, $docs)
			: item_to_jsonld($group, $doc, $titleids, $docs);

		$work->type = array($work->type, 'ItemList');
		$work->numberOfItems = $hit->inner_hits->pages->hits->total->value;
		$work->itemListElement = array();

		foreach ($pages as $j => $page)
		{
			$page_element = new stdclass;
			$page_element->type = 'ListItem';
			$page_element->position = $j + 1;
			$page_element->resultScore = $page->_score;
			$page_element->item = page_to_jsonld($page);

			$work->itemListElement[] = $page_element;
		}

		$element = new stdclass;
		$element->type = 'ListItem';
		$element->position = $from + $i + 1;
		$element->resultScore = $hit->_score;
		$element->item = $work;

		$list->itemListElement[] = $element;
	}

	return $list;
}

//----------------------------------------------------------------------------------------
// The query for article search, shared with reconciliation (which checks each hit, so
// can ask for fewer of the words to match).
function article_query($q, $minimum_should_match = '3<75%')
{
	return array(
		'bool' => array(
			'filter' => array(
				'term' => array('type' => 'part')
			),
			'must' => array(
				'match' => array('citation' => array('query' => $q, 'minimum_should_match' => $minimum_should_match))
			),
			'should' => array(
				'match_phrase' => array('name' => array('query' => $q, 'slop' => 3, 'boost' => 2))
			),
		)
	);
}

//----------------------------------------------------------------------------------------
// Search article metadata (not page text), e.g. to see whether a paper is in BHL. The
// query is matched against each part's citation string (authors, year, title, journal,
// volume, pages), with the same rule as text search: every word of a short query, 75%
// of a longer one, so a pasted citation still matches if it differs in a few words.
// Parts whose title contains the query as a phrase rank first.
//
// Returns an ItemList of articles (ScholarlyArticle, Chapter or CreativeWork).
function search_articles($q, $from = 0, $size = 20)
{
	global $config;

	$elastic = new ElasticSearch($config['elastic_options']);

	$query = array(
		'from' => $from,
		'size' => $size,
		'track_total_hits' => true,
		'query' => article_query($q),
		'_source' => array('titleid', 'name', 'authors', 'container', 'tags', 'volume', 'issue', 'year', 'pages', 'doi', 'thumbnail'),
	);

	$resp = $elastic->send('POST', '_search', json_encode($query));

	if ($resp[0] != 200)
	{
		throw new Exception('Search failed (HTTP ' . $resp[0] . ')');
	}

	$hits = $resp[1]->hits->hits;

	// Title names, for parts with no journal name of their own
	$ids = array();
	foreach ($hits as $hit)
	{
		if (isset($hit->_source->titleid))
		{
			$ids = array_merge($ids, $hit->_source->titleid);
		}
	}
	$docs = fetch_docs($elastic, $ids, array('name'));

	$list = new stdclass;
	$list->{'@context'} = create_context();
	$list->type = 'ItemList';
	$list->name = 'Articles matching "' . $q . '"';
	$list->numberOfItems = $resp[1]->hits->total->value;
	$list->itemListElement = array();

	foreach ($hits as $i => $hit)
	{
		$titleids = isset($hit->_source->titleid) ? $hit->_source->titleid : array();

		$element = new stdclass;
		$element->type = 'ListItem';
		$element->position = $from + $i + 1;
		$element->resultScore = $hit->_score;
		$element->item = part_to_jsonld($hit->_id, $hit->_source, $titleids, $docs);

		$list->itemListElement[] = $element;
	}

	return $list;
}

//----------------------------------------------------------------------------------------
// Search the catalogue: titles (books and journals) by name, full title, authors and
// subjects, all words of a short query or 75% of a longer one, across those fields
// together, so "Hampson Lepidoptera" finds a title by Hampson about Lepidoptera. Titles
// with the query as a phrase in their name rank first.
//
// A query of the form "issn:0374-5481" (or oclc:, isbn:, wikidata: and the other names
// in title_identifier_names()) finds titles by identifier instead.
//
// Returns an ItemList of titles (Book or Periodical).
function search_titles($q, $from = 0, $size = 20)
{
	global $config;

	$elastic = new ElasticSearch($config['elastic_options']);

	$filter = array(array('term' => array('type' => 'title')));

	if (preg_match('/^([a-z-]+):\s*(\S+)$/i', trim($q), $m)
		&& in_array(strtolower($m[1]), title_identifier_names()))
	{
		$filter[] = array('term' => array('identifiers' => strtolower($m[1]) . ':' . $m[2]));
		$query = array('bool' => array('filter' => $filter));
	}
	else
	{
		$query = array(
			'bool' => array(
				'filter' => $filter,
				'must' => array(
					'multi_match' => array(
						'query' => $q,
						'type' => 'cross_fields',
						'fields' => array('name^3', 'fullname^2', 'authors', 'subjects'),
						'minimum_should_match' => '3<75%',
					)
				),
				'should' => array(
					'match_phrase' => array('name' => array('query' => $q, 'slop' => 3, 'boost' => 2))
				),
			)
		);
	}

	$request = array(
		'from' => $from,
		'size' => $size,
		'track_total_hits' => true,
		'query' => $query,
		'_source' => title_fields(),
	);

	$resp = $elastic->send('POST', '_search', json_encode($request));

	if ($resp[0] != 200)
	{
		throw new Exception('Search failed (HTTP ' . $resp[0] . ')');
	}

	$list = new stdclass;
	$list->{'@context'} = create_context();
	$list->type = 'ItemList';
	$list->name = 'Titles matching "' . $q . '"';
	$list->numberOfItems = $resp[1]->hits->total->value;
	$list->itemListElement = array();

	foreach ($resp[1]->hits->hits as $i => $hit)
	{
		$element = new stdclass;
		$element->type = 'ListItem';
		$element->position = $from + $i + 1;
		$element->resultScore = $hit->_score;
		$element->item = title_to_jsonld($hit->_id, $hit->_source);

		$list->itemListElement[] = $element;
	}

	return $list;
}

//----------------------------------------------------------------------------------------
// The kinds of search, as tabs on the web page and type= in the API. The first is the
// default. "noun" describes what numberOfItems counts, and "one" a single result.
function search_types()
{
	return array(
		'all'      => array('label' => 'All',      'noun' => 'articles and volumes', 'one' => 'article or volume'),
		'articles' => array('label' => 'Articles', 'noun' => 'articles',             'one' => 'article'),
		'titles'   => array('label' => 'Titles',   'noun' => 'titles',               'one' => 'title'),
	);
}

//----------------------------------------------------------------------------------------
// Run a search of the given type, returning an ItemList.
function run_search($type, $q, $from = 0, $size = 20, $pages_per_result = 3)
{
	switch ($type)
	{
		case 'articles':
			return search_articles($q, $from, $size);

		case 'titles':
			return search_titles($q, $from, $size);

		case 'all':
		default:
			return search_text($q, $from, $size, $pages_per_result);
	}
}

?>
