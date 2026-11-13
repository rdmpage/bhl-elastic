<?php

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/search.php');
require_once (dirname(__FILE__) . '/html.php');


// php -S localhost:4000

//----------------------------------------------------------------------------------------
// Tabs for the kinds of search; each re-runs the same query.
function display_tabs($q, $type)
{
	echo '<ul class="tabs">';
	foreach (search_types() as $key => $search_type)
	{
		$params = array('q' => $q);
		if ($key != 'all')
		{
			$params['type'] = $key;
		}

		echo '<li' . ($key == $type ? ' class="active"' : '') . '>';
		echo '<a href="?' . htmlspecialchars(http_build_query($params)) . '">' . htmlspecialchars($search_type['label']) . '</a>';
		echo '</li>';
	}
	echo '</ul>';
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
// "v. 16" for a bare number; item.VolumeInfo is already free text such as
// "v.20 (1910-1911)"
function format_volume($volume)
{
	return (preg_match('/^\d/', $volume) ? 'v. ' : '') . $volume;
}

//----------------------------------------------------------------------------------------
// Names of a work's container(s), with volume and issue, walking up isPartOf, e.g.
// array('Fieldiana. Botany', 'v. 16'). A container may be one object or a list.
function container_labels($work)
{
	$labels = array();

	if (!isset($work->isPartOf))
	{
		return $labels;
	}

	$containers = is_array($work->isPartOf) ? $work->isPartOf : array($work->isPartOf);

	foreach ($containers as $container)
	{
		// Outer containers first: journal, then volume, then issue
		$labels = array_merge($labels, container_labels($container));

		if (isset($container->name))         { $labels[] = $container->name; }
		if (isset($container->volumeNumber)) { $labels[] = format_volume($container->volumeNumber); }
		if (isset($container->issueNumber))  { $labels[] = 'no. ' . $container->issueNumber; }
	}

	return $labels;
}

//----------------------------------------------------------------------------------------
// One matching page within a result: small thumbnail, page label, highlighted text.
function display_result_page($page)
{
	echo '<div class="page-hit">';

	echo '<a class="page-thumbnail" href="' . htmlspecialchars($page->id) . '">';
	if (isset($page->thumbnailUrl))
	{
		echo '<img loading="lazy" src="' . htmlspecialchars($page->thumbnailUrl) . '" alt="">';
	}
	echo '</a>';

	echo '<div class="page-body">';
	echo '<a class="page-name" href="' . htmlspecialchars($page->id) . '">'
		. htmlspecialchars(isset($page->name) ? $page->name : $page->id) . '</a>';

	// The highlighter has already HTML-escaped the OCR text (see page_to_jsonld() in search.php)
	if (isset($page->text))
	{
		echo '<p class="hit-snippets">… ' . implode(' … ', $page->text) . ' …</p>';
	}

	echo '</div>';
	echo '</div>';
}

//----------------------------------------------------------------------------------------
// One result: an article (or a volume, for pages not in an article), then its best
// pages.
function display_result($work)
{
	$heading = isset($work->name) ? $work->name : $work->id;

	$meta = container_labels($work);
	if (isset($work->volumeNumber))  { $meta[] = format_volume($work->volumeNumber); }
	if (isset($work->datePublished)) { $meta[] = $work->datePublished; }
	if (isset($work->pagination))    { $meta[] = 'pp. ' . $work->pagination; }

	// A monograph catalogued as a part often has the same name as its title
	$meta = array_values(array_diff($meta, array($heading)));

	echo '<div class="group">';

	echo '<h2><a href="' . htmlspecialchars($work->id) . '">' . htmlspecialchars($heading) . '</a></h2>';

	if (isset($work->author))
	{
		$names = array();
		foreach ($work->author as $author)
		{
			$names[] = $author->name;
		}
		echo '<p class="group-authors">' . htmlspecialchars(implode('; ', $names)) . '</p>';
	}

	echo '<p class="hit-meta">' . htmlspecialchars(implode(' · ', $meta)) . '</p>';

	if (isset($work->sameAs) && preg_match('/^https:\/\/doi.org\/(.*)$/', $work->sameAs, $m))
	{
		echo '<p class="hit-meta">doi:<a href="' . htmlspecialchars($work->sameAs) . '">' . htmlspecialchars($m[1]) . '</a></p>';
	}

	// From a text search the work is also a list of its matching pages, best first
	if (isset($work->itemListElement))
	{
		foreach ($work->itemListElement as $element)
		{
			display_result_page($element->item);
		}

		$more = $work->numberOfItems - count($work->itemListElement);
		if ($more > 0)
		{
			echo '<p class="group-more">+ ' . number_format($more) . ' more matching page' . ($more == 1 ? '' : 's') . '</p>';
		}
	}

	echo '</div>';
}

//----------------------------------------------------------------------------------------
// Previous/next links
function display_pager($q, $type, $from, $size, $total)
{
	$params = array('q' => $q);
	if ($type != 'all')
	{
		$params['type'] = $type;
	}

	echo '<div class="pager">';

	echo '<span>';
	if ($from > 0)
	{
		echo '<a href="?' . htmlspecialchars(http_build_query($params + array('from' => max(0, $from - $size)))) . '">← Previous</a>';
	}
	echo '</span>';

	echo '<span>';
	if ($from + $size < $total)
	{
		echo '<a href="?' . htmlspecialchars(http_build_query($params + array('from' => $from + $size))) . '">Next →</a>';
	}
	echo '</span>';

	echo '</div>';
}

//----------------------------------------------------------------------------------------
// Search results, rendered from the same JSON-LD that api.php returns, which is also
// embedded in the page.
function display_search($q, $type = 'all', $from = 0)
{
	global $config;

	$size = 20;

	$error = '';
	$list  = null;

	try
	{
		// Round trip through JSON so the page renders exactly what the API returns
		$list = json_decode(json_encode(run_search($type, $q, $from, $size)));
	}
	catch (Exception $e)
	{
		$error = $e->getMessage();
	}

	display_html_start($q . ' - ' . $config['site_name'], $list);
	display_navbar($q, $type);
	display_main_start();

	echo '<div class="results">';

	display_tabs($q, $type);

	if ($error != '')
	{
		echo '<div class="error">' . htmlspecialchars($error) . '</div>';
	}
	else
	{
		$count = count($list->itemListElement);

		$types = search_types();
		echo '<p class="results-summary">' . number_format($list->numberOfItems) . ' ' . $types[$type]['noun'];
		if ($count > 0)
		{
			echo ', showing ' . number_format($from + 1) . '–' . number_format($from + $count);
		}
		echo ' · <a href="api.php?' . htmlspecialchars(http_build_query(array('q' => $q, 'type' => $type, 'from' => $from))) . '">JSON-LD</a></p>';

		foreach ($list->itemListElement as $element)
		{
			display_result($element->item);
		}

		display_pager($q, $type, $from, $size, $list->numberOfItems);
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
		$type  = isset($_GET['type']) && isset(search_types()[$_GET['type']]) ? $_GET['type'] : 'all';

		display_search($query, $type, $from);
		
		exit(0);
	}

}

main();