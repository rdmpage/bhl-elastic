<?php

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/elastic.php');
require_once (dirname(__FILE__) . '/bhl.php');


// php -S localhost:4000 index.php

//----------------------------------------------------------------------------------------
// Start of HTML document
function display_html_start($title = '', $entity = null)
{
	global $config;

	echo '<!DOCTYPE html>
<head>';

	echo '<meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';

	echo '<!-- base -->
    	<base href="' . $config['web_root'] . '" /><!--[if IE]></base><![endif]-->' . "\n";
    

    if ($title == '')
    {
    	$title = $config['site_name'];
    }
    echo '<title>' . htmlentities($title, ENT_HTML5). '</title>';
	
	echo '<style>';	
	require_once (dirname(__FILE__) . '/root.css.inc.php');
	require_once (dirname(__FILE__) . '/body.css.inc.php');
	require_once (dirname(__FILE__) . '/nav.css.inc.php');
	require_once (dirname(__FILE__) . '/results.css.inc.php');
	echo '</style>' . "\n";		
	
	if ($entity)
	{
		echo "<!-- JSON-LD -->\n";
		echo '<script type="application/ld+json">' . "\n";	
		echo json_encode($entity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		echo '</script>' . "\n";	
	}

	echo '</head>';
	echo '<body>';
}

//----------------------------------------------------------------------------------------
// End of HTML document
function display_html_end()
{
	global $config;
	
	echo 
'<script>
<!-- any end of document script goes here -->
</script>';

	echo '</body>';
	echo '</html>';
}

//----------------------------------------------------------------------------------------
function display_main_start()
{
	echo '<main>';
}

//----------------------------------------------------------------------------------------
function display_main_end()
{
	echo '</main>';
}

//----------------------------------------------------------------------------------------
function display_navbar($q)
{
	global $config;

	echo '<nav>
	<ul>
		<li><a href=".">Home</a></li>
		<li class="search-item">
			<form method="get" action="index.php">
				<input class="search" id="search" name="q" type="text" placeholder="Search for name..." value="' . htmlspecialchars($q) . '">
				<input type="submit" value="Search">
			</form>
		</li>
	</ul>
	</nav>';

}

//----------------------------------------------------------------------------------------
// Home page, or badness happened
function default_display($error_msg = '')
{
	global $config;

	$title = $config['site_name'];

	display_html_start($title);
	display_navbar('');
	display_main_start();

	if ($error_msg != '')
	{
		echo '<div><strong>Error!</strong> ' . $error_msg . '</div>';
	}
	else
	{
		echo '<h1>Hi!</h1>';
	}

	display_main_end();
	display_html_end();
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
// "v. 16" for a bare number; item.VolumeInfo is already free text such as
// "v.20 (1910-1911)"
function format_volume($volume)
{
	return (preg_match('/^\d/', $volume) ? 'v. ' : '') . $volume;
}

//----------------------------------------------------------------------------------------
// One matching page within a group: small thumbnail, page label, highlighted text.
function display_group_page($hit)
{
	$source = $hit->_source;
	$url    = 'https://www.biodiversitylibrary.org/page/' . id_number($hit->_id);

	echo '<div class="page-hit">';

	echo '<a class="page-thumbnail" href="' . htmlspecialchars($url) . '">';
	if (isset($source->thumbnail))
	{
		echo '<img loading="lazy" src="' . htmlspecialchars($source->thumbnail) . '" alt="">';
	}
	echo '</a>';

	echo '<div class="page-body">';
	echo '<a class="page-name" href="' . htmlspecialchars($url) . '">'
		. htmlspecialchars(isset($source->name) ? $source->name : $hit->_id) . '</a>';

	// The highlighter's html encoder has already escaped the OCR text
	if (isset($hit->highlight->text))
	{
		echo '<p class="hit-snippets">… ' . implode(' … ', $hit->highlight->text) . ' …</p>';
	}

	echo '</div>';
	echo '</div>';
}

//----------------------------------------------------------------------------------------
// One group of matching pages: the article they are in (or the item, if they are not
// in an article), then its best pages.
//
// $docs holds the part, item and title documents the groups on this page need.
function display_group($hit, $docs)
{
	$pages = $hit->inner_hits->pages->hits;
	$first = $pages->hits[0]->_source;

	$group = isset($docs[$hit->fields->group[0]]) ? $docs[$hit->fields->group[0]] : null;
	$is_part = (strpos($hit->fields->group[0], 'part_') === 0);

	// Names of the item's titles, e.g. the monograph and the series it is in
	$titles = array();
	if (isset($first->titleid))
	{
		foreach ($first->titleid as $id)
		{
			if (isset($docs[$id]->name))
			{
				$titles[] = $docs[$id]->name;
			}
		}
	}

	$meta = array();

	if ($is_part)
	{
		$heading     = $group && isset($group->name) ? $group->name : $hit->fields->group[0];
		$heading_url = 'https://www.biodiversitylibrary.org/part/' . id_number($hit->fields->group[0]);

		if ($group && isset($group->container))
		{
			$meta[] = $group->container;
		}
		else
		{
			$meta = $titles;
		}
		if ($group && isset($group->volume)) { $meta[] = format_volume($group->volume); }
		if ($group && isset($group->issue))  { $meta[] = 'no. ' . $group->issue; }
		if ($group && isset($group->year))   { $meta[] = $group->year; }
		if ($group && isset($group->pages))  { $meta[] = 'pp. ' . str_replace('--', '–', $group->pages); }
	}
	else
	{
		$heading     = count($titles) > 0 ? array_shift($titles) : $hit->fields->group[0];
		$heading_url = 'https://www.biodiversitylibrary.org/item/' . id_number($hit->fields->group[0]);

		$meta = $titles;
		if ($group && isset($group->volume)) { $meta[] = format_volume($group->volume); }
		if ($group && isset($group->year))   { $meta[] = $group->year; }
	}

	// A monograph catalogued as a part often has the same name as its title
	$meta = array_values(array_diff($meta, array($heading)));

	echo '<div class="group">';

	echo '<h2><a href="' . htmlspecialchars($heading_url) . '">' . htmlspecialchars($heading) . '</a></h2>';

	if ($is_part && $group && isset($group->authors))
	{
		echo '<p class="group-authors">' . htmlspecialchars(implode('; ', $group->authors)) . '</p>';
	}

	echo '<p class="hit-meta">' . htmlspecialchars(implode(' · ', $meta)) . '</p>';

	foreach ($pages->hits as $page)
	{
		display_group_page($page);
	}

	$more = $pages->total->value - count($pages->hits);
	if ($more > 0)
	{
		echo '<p class="group-more">+ ' . number_format($more) . ' more matching page' . ($more == 1 ? '' : 's') . '</p>';
	}

	echo '</div>';
}

//----------------------------------------------------------------------------------------
// Previous/next links
function display_pager($q, $from, $size, $total)
{
	echo '<div class="pager">';

	echo '<span>';
	if ($from > 0)
	{
		echo '<a href="?' . htmlspecialchars(http_build_query(array('q' => $q, 'from' => max(0, $from - $size)))) . '">← Previous</a>';
	}
	echo '</span>';

	echo '<span>';
	if ($from + $size < $total)
	{
		echo '<a href="?' . htmlspecialchars(http_build_query(array('q' => $q, 'from' => $from + $size))) . '">Next →</a>';
	}
	echo '</span>';

	echo '</div>';
}

//----------------------------------------------------------------------------------------
function display_search($q, $from = 0)
{
	global $config;

	$size = 20;

	$title = $q . ' - ' . $config['site_name'];

	display_html_start($title);
	display_navbar($q);
	display_main_start();

	// do search
	$elastic = new ElasticSearch($config['elastic_options']);

	// Pages that have all the words, with pages that have them as a phrase first,
	// collapsed into one result per article (or item, for pages not in an article).
	// Groups are ranked by their best page; each brings its three best pages.
	$query = array(
		'from' => $from,
		'size' => $size,
		'track_total_hits' => true,
		'query' => array(
			'bool' => array(
				'filter' => array(
					'term' => array('type' => 'page')
				),
				'must' => array(
					'match' => array('text' => array('query' => $q, 'operator' => 'and'))
				),
				'should' => array(
					'match_phrase' => array('text' => array('query' => $q, 'boost' => 2))
				),
			)
		),
		'_source' => false,
		'collapse' => array(
			'field' => 'group',
			'inner_hits' => array(
				'name' => 'pages',
				'size' => 3,
				'_source' => array('titleid', 'name', 'thumbnail'),
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

	echo '<div class="results">';

	if ($resp[0] != 200)
	{
		echo '<div class="error">Search failed (HTTP ' . $resp[0] . ')</div>';
	}
	else
	{
		$hits   = $resp[1]->hits->hits;
		$total  = $resp[1]->hits->total->value;
		$groups = $resp[1]->aggregations->groups->value; // approximate above a few thousand

		echo '<p class="results-summary">' . number_format($total) . ' pages in '
			. number_format($groups) . ' articles and volumes';
		if ($groups > 0)
		{
			echo ', showing ' . number_format($from + 1) . '–' . number_format($from + count($hits));
		}
		echo ' (' . $resp[1]->took . ' ms)</p>';

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

		$docs = fetch_docs($elastic, $ids, array('name', 'authors', 'container', 'volume', 'issue', 'year', 'pages'));

		foreach ($hits as $hit)
		{
			display_group($hit, $docs);
		}

		display_pager($q, $from, $size, $groups);
	}

	echo '</div>';

	display_main_end();
	display_html_end();
}


//----------------------------------------------------------------------------------------
function main()
{
	$query = '';
		
	// If no query parameters 
	if (count($_GET) == 0)
	{
		default_display();
		exit(0);
	}
		
	// Error message
	if (isset($_GET['error']))
	{	
		$error_msg = $_GET['error'];		
		default_display($error_msg);
		exit(0);			
	}
	
	// Show search
	if (isset($_GET['q']))
	{	
		$query = $_GET['q'];
		$from  = isset($_GET['from']) ? max(0, (int)$_GET['from']) : 0;

		display_search($query, $from);
		
		exit(0);
	}

}

main();