<?php

// What we find in a page's text, as fields of its Elasticsearch document: latitude and
// longitude pairs (geotag.php) and scientific names (taxonfinder-php,
// https://github.com/rdmpage/taxonfinder-php). Used by load-ocr.php as pages are
// loaded, and by load-annotations.php for pages already in the index.
//
// Fields (each left out if there is nothing for it):
//
//   locations    distinct points, geo_point, to search and map
//   names        distinct names (as interpreted: "P. saltator" after "Pomatomus" is
//                "Pomatomus saltator"), keyword, to search and to say what a page is
//                about
//   new_names    names followed by a statement that they are new (sp. nov., gen. nov.,
//                comb. nov., nom. nov., ...; not syn. nov. or stat. nov., which
//                publish no name, nor "G. aus sp. nov.", which is mentioned again; see
//                is_new_name()), keyword; where a name was described is usually among
//                them, though they also include mentions in titles and reviews
//   annotations  each pair and each name where it is in the text, stored but not
//                indexed, for the viewer:
//                  type   "geo" or "name"
//                  exact  the text as it is
//                  start, end  bytes in the page's text
//                  value  the point ({lat, lon}) or the name
//                a geo annotation also has prefix and suffix (see geotag.php); a name
//                does not, as there are many and they can be read from the text. A
//                name followed by a nomenclatural statement has statements: verbatim
//                (as printed), terms (normalised: "sp. nov.", "gen. nov.", "syn.",
//                ...), and its start and end; new, true, if it is published as new
//                here (see is_new_name()); and uncertainty, taxonfinder's list of
//                doubts the author marks, each {qualifier ("gen. inc." for "(?)"
//                after the genus), applies_to, verbatim, start, end}.

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/geotag.php');

//----------------------------------------------------------------------------------------
// One taxonfinder, as loading its dictionaries takes a moment and 100 MB
function name_finder()
{
	global $config;

	static $finder = null;

	if (!$finder)
	{
		$autoload = rtrim($config['taxonfinder'], '/') . '/autoload.php';
		if (!file_exists($autoload))
		{
			throw new Exception('taxonfinder-php not found at ' . $config['taxonfinder'] . ' (set TAXONFINDER in env.php)');
		}
		require_once ($autoload);

		// No quoted context: annotations keep offsets, and the text is beside them
		$finder = new Taxonfinder\Finder(null, 0);
	}

	return $finder;
}

//----------------------------------------------------------------------------------------
// Is a name, printed as $exact and followed by these statement terms, being published
// as new here? Yes if a term publishes something (sp. nov., gen. nov., comb. nov.,
// nom. nov., ...), but not a judgment about names already published (syn. nov. sinks
// one name into another, stat. nov. moves one to another rank; taxonfinder reports its
// judgment forms among the terms). And not if the genus is abbreviated ("L. aus sp.
// nov.", "Ch. aus n. sp.", or "L aus" where OCR lost the full stop): that is a new name
// mentioned again, in an abstract, key or caption, and it is written in full where it
// is described.
function is_new_name($exact, $terms)
{
	if (preg_match('/^(?:\p{Lu}\.?|\p{Lu}\p{Ll}{1,2}\.)\s/u', $exact))
	{
		return false;
	}

	foreach ($terms as $term)
	{
		if (substr($term, -4) == 'nov.' && !in_array($term, array('syn. nov.', 'stat. nov.')))
		{
			return true;
		}
	}

	return false;
}

//----------------------------------------------------------------------------------------
// A page's names as [names, new_names, annotations]
function page_names($text)
{
	$names       = array();
	$new_names   = array();
	$annotations = array();

	foreach (name_finder()->findWithByteOffsets($text) as $found)
	{
		$name     = $found['body']['value'];
		$position = $found['target']['selector'][1];

		$names[$name] = true;

		$annotation = array(
			'type'  => 'name',
			'exact' => $found['target']['selector'][0]['exact'],
			'start' => $position['start'],
			'end'   => $position['end'],
			'value' => $name,
		);

		// Doubt the author expresses, e.g. "(?)" after the genus: qualifier "gen. inc."
		if (isset($found['uncertainty']))
		{
			$annotation['uncertainty'] = $found['uncertainty'];
		}

		if (isset($found['statements']))
		{
			$annotation['statements'] = $found['statements'];

			if (is_new_name($annotation['exact'], $found['statements']['terms']))
			{
				$annotation['new'] = true;
				$new_names[$name] = true;
			}
		}

		$annotations[] = $annotation;
	}

	return array(array_keys($names), array_keys($new_names), $annotations);
}

//----------------------------------------------------------------------------------------
// The fields for a page with this text (see above), as an array with every field, those
// with nothing being null, so it can be used as a partial update that also clears them
function page_fields($text)
{
	$fields = array('locations' => null, 'names' => null, 'new_names' => null, 'annotations' => null);
	$annotations = array();

	$geo = page_geo($text);
	if ($geo)
	{
		$fields['locations'] = $geo['locations'];
		$annotations = $geo['annotations'];
	}

	list($names, $new_names, $name_annotations) = page_names($text);
	if (count($names) > 0)     { $fields['names'] = $names; }
	if (count($new_names) > 0) { $fields['new_names'] = $new_names; }

	// In the order they appear
	$annotations = array_merge($annotations, $name_annotations);
	usort($annotations, function ($a, $b) { return $a['start'] - $b['start']; });
	if (count($annotations) > 0)
	{
		$fields['annotations'] = $annotations;
	}

	return $fields;
}

?>
