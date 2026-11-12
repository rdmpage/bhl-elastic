<?php

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/elastic.php');


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
// Numeric part of a prefixed id: "part_349927" -> 349927
function id_number($id)
{
	return (int)preg_replace('/^[a-z]+_/', '', $id);
}

//----------------------------------------------------------------------------------------
// Names of the parts and titles that a set of page hits belong to, as
// array(id => name), e.g. 'part_349927' => 'A systematic study of Flourensia ...'.
// One _mget for the whole page of results.
function hit_names($elastic, $hits)
{
	$ids = array();

	foreach ($hits as $hit)
	{
		foreach (array('partid', 'titleid') as $field)
		{
			if (isset($hit->_source->{$field}))
			{
				foreach ($hit->_source->{$field} as $id)
				{
					$ids[$id] = true;
				}
			}
		}
	}

	$names = array();

	if (count($ids) == 0)
	{
		return $names;
	}

	$request = array('ids' => array_keys($ids));

	$resp = $elastic->send('POST', '_mget?_source=name', json_encode($request));

	if ($resp[0] == 200)
	{
		foreach ($resp[1]->docs as $doc)
		{
			if ($doc->found && isset($doc->_source->name))
			{
				$names[$doc->_id] = $doc->_source->name;
			}
		}
	}

	return $names;
}

//----------------------------------------------------------------------------------------
// One page hit: thumbnail, the article (or else the title) it is in, where it is in the
// item, and the highlighted text.
function display_page_hit($hit, $names)
{
	$source = $hit->_source;
	$pageid = id_number($hit->_id);
	$url    = 'https://www.biodiversitylibrary.org/page/' . $pageid;

	// Heading: the article if the page is in one, otherwise the title(s) of the item
	$heading = '';
	$heading_url = $url;

	if (isset($source->partid) && isset($names[$source->partid[0]]))
	{
		$heading = $names[$source->partid[0]];
		$heading_url = 'https://www.biodiversitylibrary.org/part/' . id_number($source->partid[0]);
	}

	$titles = array();
	if (isset($source->titleid))
	{
		foreach ($source->titleid as $id)
		{
			if (isset($names[$id]))
			{
				$titles[] = $names[$id];
			}
		}
	}

	if ($heading == '')
	{
		$heading = count($titles) > 0 ? array_shift($titles) : $hit->_id;
		$heading_url = 'https://www.biodiversitylibrary.org/item/' . id_number($source->itemid);
	}

	// Meta line: title(s) not used as the heading, volume, year, page. A monograph
	// catalogued as a part often has the same name as its title.
	$meta = array_values(array_diff($titles, array($heading)));
	// page.Volume is a bare number, item.VolumeInfo is free text such as "v.20 (1910-1911)"
	if (isset($source->volume))
	{
		$meta[] = (preg_match('/^\d/', $source->volume) ? 'v. ' : '') . $source->volume;
	}
	if (isset($source->issue))  { $meta[] = 'no. ' . $source->issue; }
	if (isset($source->year))   { $meta[] = $source->year; }
	if (isset($source->name))   { $meta[] = $source->name; }

	echo '<div class="hit">';

	echo '<div class="hit-thumbnail">';
	echo '<a href="' . htmlspecialchars($url) . '">';
	if (isset($source->thumbnail))
	{
		echo '<img loading="lazy" src="' . htmlspecialchars($source->thumbnail) . '" alt="">';
	}
	echo '</a>';
	echo '</div>';

	echo '<div class="hit-body">';
	echo '<h2><a href="' . htmlspecialchars($heading_url) . '">' . htmlspecialchars($heading) . '</a></h2>';
	echo '<p class="hit-meta">' . htmlspecialchars(implode(' · ', $meta)) . '</p>';

	// The highlighter's html encoder has already escaped the OCR text
	if (isset($hit->highlight->text))
	{
		echo '<p class="hit-snippets">… ' . implode(' … ', $hit->highlight->text) . ' …</p>';
	}

	echo '</div>';
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

	// Pages that have all the words, with pages that have them as a phrase first
	$query = array(
		'from' => $from,
		'size' => $size,
		'track_total_hits' => true,
		'query' => array(
			'bool' => array(
				'must' => array(
					'match' => array('text' => array('query' => $q, 'operator' => 'and'))
				),
				'should' => array(
					'match_phrase' => array('text' => array('query' => $q, 'boost' => 2))
				),
			)
		),
		'_source' => array('itemid', 'titleid', 'partid', 'name', 'year', 'volume', 'issue', 'thumbnail'),
		'highlight' => array(
			'encoder' => 'html',
			'pre_tags' => array('<em>'),
			'post_tags' => array('</em>'),
			'fields' => array('text' => array('fragment_size' => 150, 'number_of_fragments' => 3)),
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
		$hits  = $resp[1]->hits->hits;
		$total = $resp[1]->hits->total->value;

		echo '<p class="results-summary">' . number_format($total) . ' pages';
		if ($total > 0)
		{
			echo ', showing ' . number_format($from + 1) . '–' . number_format($from + count($hits));
		}
		echo ' (' . $resp[1]->took . ' ms)</p>';

		$names = hit_names($elastic, $hits);

		foreach ($hits as $hit)
		{
			display_page_hit($hit, $names);
		}

		display_pager($q, $from, $size, $total);
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