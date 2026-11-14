<?php

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/search.php');
require_once (dirname(__FILE__) . '/records.php');
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
// Our page for a BHL title URI, or null for anything else.
function title_page_url($id)
{
	if (preg_match('/^https:\/\/www.biodiversitylibrary.org\/bibliography\/(\d+)$/', $id, $m))
	{
		return '?title=' . $m[1];
	}
	return null;
}

//----------------------------------------------------------------------------------------
// Labels as HTML, joined by " · ". Each label is array(text, link or null).
function display_labels($labels)
{
	$html = array();
	foreach ($labels as $label)
	{
		$text = htmlspecialchars($label[0]);
		$html[] = $label[1] ? '<a href="' . htmlspecialchars($label[1]) . '">' . $text . '</a>' : $text;
	}
	return implode(' · ', $html);
}

//----------------------------------------------------------------------------------------
// Names of a work's container(s), with volume and issue, walking up isPartOf, e.g.
// array(array('Fieldiana. Botany', null), array('v. 16', null)); BHL titles link to
// their title page. A container may be one object or a list.
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

		if (isset($container->name))
		{
			$labels[] = array($container->name, isset($container->id) ? title_page_url($container->id) : null);
		}
		if (isset($container->volumeNumber)) { $labels[] = array(format_volume($container->volumeNumber), null); }
		if (isset($container->issueNumber))  { $labels[] = array('no. ' . $container->issueNumber, null); }
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

	// Titles link to our title page, everything else to BHL
	$href = title_page_url($work->id) ? title_page_url($work->id) : $work->id;

	$meta = container_labels($work);
	if ($work->type == 'Periodical' || $work->type == 'Book')
	{
		$meta[] = array($work->type == 'Periodical' ? 'Journal or serial' : 'Book', null);
	}
	if (isset($work->startDate))
	{
		$end = isset($work->endDate) ? $work->endDate : $work->startDate;
		$meta[] = array($work->startDate == $end ? $work->startDate : $work->startDate . '–' . $end, null);
	}
	if (isset($work->volumeNumber))  { $meta[] = array(format_volume($work->volumeNumber), null); }
	if (isset($work->datePublished)) { $meta[] = array($work->datePublished, null); }
	if (isset($work->pagination))    { $meta[] = array('pp. ' . $work->pagination, null); }
	if (isset($work->inLanguage))    { $meta[] = array(language_name($work->inLanguage), null); }

	// A monograph catalogued as a part often has the same name as its title
	$meta = array_filter($meta, function ($label) use ($heading) { return $label[0] != $heading; });

	// Without matching pages (article search) the work's own thumbnail goes beside it
	$thumbnail = !isset($work->itemListElement) && isset($work->thumbnailUrl);

	echo '<div class="group' . ($thumbnail ? ' group-with-thumbnail' : '') . '">';

	if ($thumbnail)
	{
		echo '<a class="page-thumbnail" href="' . htmlspecialchars($href) . '">';
		echo '<img loading="lazy" src="' . htmlspecialchars($work->thumbnailUrl) . '" alt="">';
		echo '</a>';
		echo '<div class="page-body">';
	}

	echo '<h2><a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($heading) . '</a></h2>';

	if (isset($work->author))
	{
		$names = array();
		foreach ($work->author as $author)
		{
			$names[] = $author->name;
		}
		echo '<p class="group-authors">' . htmlspecialchars(implode('; ', $names)) . '</p>';
	}

	echo '<p class="hit-meta">' . display_labels($meta) . '</p>';

	// sameAs is one URL for an article, a list for a title
	$same_as = isset($work->sameAs) ? (array)$work->sameAs : array();
	foreach ($same_as as $url)
	{
		if (preg_match('/^https:\/\/doi.org\/(.*)$/', $url, $m))
		{
			echo '<p class="hit-meta">doi:<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($m[1]) . '</a></p>';
		}
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

	if ($thumbnail)
	{
		echo '</div>';
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
		echo '<p class="results-summary">' . number_format($list->numberOfItems) . ' ' . $types[$type][$list->numberOfItems == 1 ? 'one' : 'noun'];
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
// One row of the summary table, if there is a value; $html is already HTML.
function display_summary_row($label, $html)
{
	if ($html != '')
	{
		echo '<dt>' . htmlspecialchars($label) . '</dt><dd>' . $html . '</dd>';
	}
}

//----------------------------------------------------------------------------------------
// How often each value occurs, most frequent first, with bars.
function display_counts($heading, $values)
{
	if (count($values) == 0)
	{
		return;
	}

	$counts = array_count_values($values);
	arsort($counts);
	$most = max($counts);

	echo '<h2>' . htmlspecialchars($heading) . '</h2>';
	echo '<table class="counts">';
	foreach ($counts as $value => $count)
	{
		echo '<tr><td>' . htmlspecialchars($value) . '</td>';
		echo '<td class="count">' . number_format($count) . '</td>';
		echo '<td class="bar-cell"><span class="bar" style="width:' . round(100 * $count / $most) . '%"></span></td></tr>';
	}
	echo '</table>';
}

//----------------------------------------------------------------------------------------
// Items per year (each counted in its first year), as bars from the first year to the
// last, empty years included so gaps in a run show. Hovering a bar gives its count.
function display_year_histogram($items)
{
	$counts = array();
	foreach ($items as $item)
	{
		if (isset($item->datePublished))
		{
			$year = (int)$item->datePublished;
			$counts[$year] = isset($counts[$year]) ? $counts[$year] + 1 : 1;
		}
	}

	if (count($counts) == 0)
	{
		return;
	}

	$first = min(array_keys($counts));
	$last  = max(array_keys($counts));
	$most  = max($counts);
	$span  = $last - $first + 1;

	// Axis labels every decade, or every 50 years for long runs; just the ends for a
	// run within one decade
	$step = $span > 100 ? 50 : 10;

	echo '<h2>Items by year</h2>';
	echo '<div class="histogram" role="img" aria-label="Items per year, ' . $first . ' to ' . $last . '">';
	echo '<div class="histogram-bars">';
	for ($year = $first; $year <= $last; $year++)
	{
		$count = isset($counts[$year]) ? $counts[$year] : 0;
		$label = $year . ': ' . $count . ' item' . ($count == 1 ? '' : 's');
		echo '<div class="histogram-year" data-label="' . $label . '" title="' . $label . '">';
		if ($count > 0)
		{
			echo '<span class="histogram-bar" style="height:' . round(100 * $count / $most) . '%"></span>';
		}
		echo '</div>';
	}
	echo '</div>';

	echo '<div class="histogram-axis">';
	$labels = array();
	for ($year = $first; $year <= $last; $year++)
	{
		if ($year % $step == 0)
		{
			$labels[] = $year;
		}
	}
	if (count($labels) == 0)
	{
		$labels = array_unique(array($first, $last));
	}
	foreach ($labels as $year)
	{
		// centred under its bar
		$left = 100 * (($year - $first) + 0.5) / $span;
		echo '<span style="left:' . round($left, 2) . '%">' . $year . '</span>';
	}
	echo '</div>';
	echo '</div>';

	echo '<p class="hit-meta">Most in one year: ' . $most . '</p>';
}

//----------------------------------------------------------------------------------------
// A title (book or journal), like OpenAlex's page for a source: a summary, the
// libraries that contributed its items, and the items, as a list or a grid ($view).
// Rendered from get_title()'s JSON-LD.
function display_title($titleid, $view = 'grid')
{
	global $config;

	$title = get_title($titleid);

	if (!$title)
	{
		default_display('No title ' . htmlspecialchars($titleid) . ' in this index');
		return;
	}

	$name = isset($title->name) ? $title->name : $title->id;

	display_html_start($name . ' - ' . $config['site_name'], $title);
	display_navbar('');
	display_main_start();

	echo '<div class="results">';

	echo '<div class="title-header">';
	if (isset($title->thumbnailUrl))
	{
		echo '<img class="title-thumbnail" src="' . htmlspecialchars($title->thumbnailUrl) . '" alt="">';
	}
	echo '<div>';
	echo '<h1>' . htmlspecialchars($name) . '</h1>';
	if (isset($title->alternateName))
	{
		echo '<p class="hit-meta">' . htmlspecialchars($title->alternateName) . '</p>';
	}
	if (isset($title->author))
	{
		$names = array();
		foreach ($title->author as $author)
		{
			$names[] = $author->name;
		}
		echo '<p class="group-authors">' . htmlspecialchars(implode('; ', $names)) . '</p>';
	}
	echo '</div>';
	echo '</div>';

	// Years: a serial's own, or for a book the span of its items
	$years = array();
	if (isset($title->startDate))
	{
		$years = array($title->startDate, isset($title->endDate) ? $title->endDate : $title->startDate);
	}
	else
	{
		foreach ($title->hasPart as $item)
		{
			if (isset($item->datePublished))
			{
				$years[] = $item->datePublished;
			}
		}
		if (count($years) > 0)
		{
			$years = array(min($years), max($years));
		}
	}

	// Identifiers, with links where they resolve
	$identifiers = array();
	if (isset($title->sameAs))
	{
		foreach ($title->sameAs as $url)
		{
			$label = $url;
			if (preg_match('/doi.org\/(.*)$/', $url, $m))           { $label = 'DOI ' . $m[1]; }
			elseif (preg_match('/worldcat.org\/oclc\/(.*)$/', $url, $m)) { $label = 'OCLC ' . $m[1]; }
			elseif (preg_match('/wikidata.org\/entity\/(.*)$/', $url, $m)) { $label = 'Wikidata ' . $m[1]; }
			$identifiers[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($label) . '</a>';
		}
	}
	foreach (array('issn' => 'ISSN', 'isbn' => 'ISBN') as $property => $label)
	{
		if (isset($title->{$property}))
		{
			foreach ($title->{$property} as $value)
			{
				$identifiers[] = $label . ' ' . htmlspecialchars($value);
			}
		}
	}
	if (isset($title->identifier))
	{
		foreach ($title->identifier as $identifier)
		{
			$identifiers[] = htmlspecialchars(strtoupper($identifier->propertyID) . ' ' . $identifier->value);
		}
	}

	echo '<dl class="title-summary">';
	display_summary_row('Type', $title->type == 'Periodical' ? 'Journal or serial' : 'Book');
	display_summary_row('Years', count($years) > 0 ? htmlspecialchars($years[0] == $years[1] ? $years[0] : $years[0] . '–' . $years[1]) : '');
	display_summary_row('Language', isset($title->inLanguage) ? htmlspecialchars(language_name($title->inLanguage)) : '');
	display_summary_row('Subjects', isset($title->keywords) ? htmlspecialchars(implode('; ', $title->keywords)) : '');
	display_summary_row('Identifiers', implode('<br>', $identifiers));
	display_summary_row('BHL', '<a href="' . htmlspecialchars($title->id) . '">' . htmlspecialchars($title->id) . '</a>');
	echo '</dl>';

	// Counts over the items (all of them are listed)
	$libraries = array();
	$copyright = array();
	$licenses  = array();
	$holders   = array();
	foreach ($title->hasPart as $item)
	{
		if (isset($item->provider->name))   { $libraries[] = $item->provider->name; }
		if (isset($item->copyrightNotice))  { $copyright[] = $item->copyrightNotice; }
		$licenses[] = isset($item->license) ? license_label($item->license) : 'None';
		if (isset($item->copyrightHolder->name)) { $holders[] = $item->copyrightHolder->name; }
	}

	display_year_histogram($title->hasPart);
	display_counts('Contributing libraries', $libraries);
	display_counts('Copyright', $copyright);
	display_counts('Licence', $licenses);
	display_counts('Rights holder', $holders);

	$n = count($title->hasPart);
	echo '<div class="items-header">';
	echo '<h2>' . number_format($n) . ' item' . ($n == 1 ? '' : 's') . '</h2>';

	// List or grid of items; a link, so the view can be bookmarked
	echo '<ul class="view-toggle">';
	foreach (array('grid' => 'Grid', 'list' => 'List') as $key => $label)
	{
		$params = array('title' => (int)$titleid);
		if ($key != 'grid')
		{
			$params['view'] = $key;
		}
		echo '<li' . ($key == $view ? ' class="active"' : '') . '>';
		echo '<a href="?' . htmlspecialchars(http_build_query($params)) . '">' . $label . '</a>';
		echo '</li>';
	}
	echo '</ul>';
	echo '</div>';

	echo '<div class="items items-' . $view . '">';

	foreach ($title->hasPart as $item)
	{
		$label = isset($item->volumeNumber) ? format_volume($item->volumeNumber) : $item->id;

		$meta = array();
		if (isset($item->datePublished))  { $meta[] = $item->datePublished; }
		if (isset($item->provider->name)) { $meta[] = $item->provider->name; }
		if (isset($item->copyrightNotice)) { $meta[] = $item->copyrightNotice; }
		if (isset($item->license))         { $meta[] = license_label($item->license); }

		echo '<div class="page-hit">';
		echo '<a class="page-thumbnail" href="' . htmlspecialchars($item->id) . '">';
		if (isset($item->thumbnailUrl))
		{
			echo '<img loading="lazy" src="' . htmlspecialchars($item->thumbnailUrl) . '" alt="">';
		}
		echo '</a>';
		echo '<div class="page-body">';
		echo '<a class="page-name" href="' . htmlspecialchars($item->id) . '">' . htmlspecialchars($label) . '</a>';
		echo '<p class="hit-meta">' . htmlspecialchars(implode(' · ', $meta)) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	echo '</div>';

	echo '<p class="results-summary"><a href="api.php?title=' . (int)$titleid . '">JSON-LD</a></p>';

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
	
	// Show a title
	if (isset($_GET['title']))
	{
		$view = isset($_GET['view']) && $_GET['view'] == 'list' ? 'list' : 'grid';
		display_title((int)$_GET['title'], $view);
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