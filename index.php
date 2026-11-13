<?php

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/search.php');


// php -S localhost:4000

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
		echo json_encode($entity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
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

	// The work is also a list of its matching pages, best first
	foreach ($work->itemListElement as $element)
	{
		display_result_page($element->item);
	}

	$more = $work->numberOfItems - count($work->itemListElement);
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
// Search results, rendered from the same JSON-LD that api.php returns, which is also
// embedded in the page.
function display_search($q, $from = 0)
{
	global $config;

	$size = 20;

	$error = '';
	$list  = null;

	try
	{
		// Round trip through JSON so the page renders exactly what the API returns
		$list = json_decode(json_encode(search_text($q, $from, $size)));
	}
	catch (Exception $e)
	{
		$error = $e->getMessage();
	}

	display_html_start($q . ' - ' . $config['site_name'], $list);
	display_navbar($q);
	display_main_start();

	echo '<div class="results">';

	if ($error != '')
	{
		echo '<div class="error">' . htmlspecialchars($error) . '</div>';
	}
	else
	{
		$count = count($list->itemListElement);

		echo '<p class="results-summary">' . number_format($list->numberOfItems) . ' articles and volumes';
		if ($count > 0)
		{
			echo ', showing ' . number_format($from + 1) . '–' . number_format($from + $count);
		}
		echo ' · <a href="api.php?' . htmlspecialchars(http_build_query(array('q' => $q, 'from' => $from))) . '">JSON-LD</a></p>';

		foreach ($list->itemListElement as $element)
		{
			display_result($element->item);
		}

		display_pager($q, $from, $size, $list->numberOfItems);
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