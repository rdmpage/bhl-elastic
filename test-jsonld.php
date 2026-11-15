<?php

// Check the JSON-LD we generate, in two steps:
//
// 1. It is valid JSON-LD: it converts to RDF with a JSON-LD processor (ml/json-ld)
//    without errors, and every IRI is absolute (none is relative to the document).
//
// 2. It uses schema.org properly, checked against schema.org's own vocabulary
//    (testsets/schemaorg-current-http.jsonld). Because our context's @vocab is
//    schema.org, a misspelt key does not fail; it becomes a schema.org term that does
//    not exist. So: every type must be a schema.org class and every predicate a
//    schema.org property (errors); terms schema.org has superseded, and properties used
//    outside their domainIncludes or with a value outside their rangeIncludes, are
//    warnings, as schema.org only says what is expected.
//
// Each case is one of the outputs of the API. Messages are counted rather than
// repeated, as an item with 500 pages would otherwise give 500 of each.
//
//   php test-jsonld.php     (exits with 1 if any case has errors)

// Command line only
if (PHP_SAPI != 'cli')
{
	exit(1);
}

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/vendor/autoload.php');
require_once (dirname(__FILE__) . '/search.php');
require_once (dirname(__FILE__) . '/records.php');

use ML\JsonLD\JsonLD;
use ML\JsonLD\TypedValue;
use ML\JsonLD\LanguageTaggedString;

define('SCHEMA', 'http://schema.org/');
define('RELATIVE_BASE', 'http://relative.invalid/');
define('RDF_TYPE', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type');

// Predicates from outside schema.org that we use on purpose
$allowed_predicates = array(
	'http://schema.googleapis.com/resultScore',
);

//----------------------------------------------------------------------------------------
// schema.org's classes (with their superclasses), properties (with domainIncludes and
// rangeIncludes), data types, and superseded terms, by local name ("Book").
function load_vocabulary($filename)
{
	$data = json_decode(file_get_contents($filename));

	$ids = function ($value) {
		$list = array();
		if ($value !== null)
		{
			foreach (is_array($value) ? $value : array($value) as $node)
			{
				$list[] = preg_replace('/^schema:/', '', $node->{'@id'});
			}
		}
		return $list;
	};

	$vocab = (object)array(
		'classes'    => array(),
		'properties' => array(),
		'superseded' => array(),
	);

	foreach ($data->{'@graph'} as $node)
	{
		if (strpos($node->{'@id'}, 'schema:') !== 0)
		{
			continue;
		}
		$name  = substr($node->{'@id'}, 7);
		$types = (array)$node->{'@type'};

		if (in_array('rdfs:Class', $types) || in_array('schema:DataType', $types))
		{
			$vocab->classes[$name] = $ids(isset($node->{'rdfs:subClassOf'}) ? $node->{'rdfs:subClassOf'} : null);
			if (in_array('schema:DataType', $types))
			{
				$vocab->classes[$name][] = 'DataType';
			}
		}
		if (in_array('rdf:Property', $types))
		{
			$vocab->properties[$name] = (object)array(
				'domain' => $ids(isset($node->{'schema:domainIncludes'}) ? $node->{'schema:domainIncludes'} : null),
				'range'  => $ids(isset($node->{'schema:rangeIncludes'}) ? $node->{'schema:rangeIncludes'} : null),
			);
		}
		if (isset($node->{'schema:supersededBy'}))
		{
			$vocab->superseded[$name] = implode(', ', $ids($node->{'schema:supersededBy'}));
		}
	}

	return $vocab;
}

//----------------------------------------------------------------------------------------
// A class and all its superclasses.
function superclasses($vocab, $class)
{
	$all = array();
	$todo = array($class);
	while (count($todo) > 0)
	{
		$c = array_pop($todo);
		if (!isset($all[$c]))
		{
			$all[$c] = true;
			if (isset($vocab->classes[$c]))
			{
				$todo = array_merge($todo, $vocab->classes[$c]);
			}
		}
	}
	return array_keys($all);
}

//----------------------------------------------------------------------------------------
function local_name($iri)
{
	return strpos($iri, SCHEMA) === 0 ? substr($iri, strlen(SCHEMA)) : null;
}

//----------------------------------------------------------------------------------------
// Check one JSON-LD document. Returns array('errors' => array(message => count),
// 'warnings' => ..., 'triples' => n).
function check_jsonld($obj, $vocab, $allowed_predicates)
{
	$result = array('errors' => array(), 'warnings' => array(), 'triples' => 0);

	$note = function ($level, $message) use (&$result) {
		$result[$level][$message] = isset($result[$level][$message]) ? $result[$level][$message] + 1 : 1;
	};

	// 1. JSON-LD to RDF
	try
	{
		// Relative IRIs resolve against this base, so they can be found below
		$quads = JsonLD::toRdf(json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), array('base' => RELATIVE_BASE));
	}
	catch (Exception $e)
	{
		$note('errors', 'Not valid JSON-LD: ' . $e->getMessage());
		return $result;
	}

	$result['triples'] = count($quads);

	// Types of each subject, with their superclasses
	$types = array();
	foreach ($quads as $quad)
	{
		if ((string)$quad->getProperty() == RDF_TYPE)
		{
			$s = (string)$quad->getSubject();
			$class = local_name((string)$quad->getObject());
			if ($class !== null)
			{
				$types[$s] = array_merge(isset($types[$s]) ? $types[$s] : array(), superclasses($vocab, $class));
			}
		}
	}

	foreach ($quads as $quad)
	{
		$s = (string)$quad->getSubject();
		$p = (string)$quad->getProperty();
		$o = $quad->getObject();

		$iris = array($s, $p);
		if (!($o instanceof TypedValue) && !($o instanceof LanguageTaggedString))
		{
			$iris[] = (string)$o;
		}

		foreach ($iris as $iri)
		{
			if (strpos($iri, RELATIVE_BASE) === 0)
			{
				$note('errors', 'Not an absolute IRI: ' . substr($iri, strlen(RELATIVE_BASE)));
			}
		}

		// Types
		if ($p == RDF_TYPE)
		{
			$class = local_name((string)$o);
			if ($class === null)
			{
				$note('warnings', 'Type not from schema.org: ' . $o);
			}
			elseif (!isset($vocab->classes[$class]))
			{
				$note('errors', 'Not a schema.org class: ' . $class);
			}
			elseif (isset($vocab->superseded[$class]))
			{
				$note('warnings', $class . ' is superseded by ' . $vocab->superseded[$class]);
			}
			continue;
		}

		// Predicates
		$property = local_name($p);
		if ($property === null)
		{
			if (!in_array($p, $allowed_predicates))
			{
				$note('warnings', 'Property not from schema.org: ' . $p);
			}
			continue;
		}
		if (!isset($vocab->properties[$property]))
		{
			$note('errors', 'Not a schema.org property: ' . $property);
			continue;
		}
		if (isset($vocab->superseded[$property]))
		{
			$note('warnings', $property . ' is superseded by ' . $vocab->superseded[$property]);
		}

		$definition = $vocab->properties[$property];

		// Domain, if we know the subject's type
		if (isset($types[$s]) && count($definition->domain) > 0 && count(array_intersect($types[$s], $definition->domain)) == 0)
		{
			$note('warnings', $property . ' on ' . local_types($types[$s], $vocab) . ' (expected on ' . implode(', ', $definition->domain) . ')');
		}

		// Range: a literal needs a data type in the range; a node with a type needs one
		// of its types in the range. Nodes we have not typed are not checked.
		if (count($definition->range) > 0)
		{
			$literal = ($o instanceof TypedValue) || ($o instanceof LanguageTaggedString);
			if ($literal)
			{
				$ok = false;
				foreach ($definition->range as $range)
				{
					if (in_array('DataType', superclasses($vocab, $range)))
					{
						$ok = true;
					}
				}
				if (!$ok)
				{
					$note('warnings', $property . ' has a text value (expected ' . implode(', ', $definition->range) . ')');
				}
			}
			elseif (isset($types[(string)$o]) && count(array_intersect($types[(string)$o], $definition->range)) == 0
				&& !in_array('URL', $definition->range))
			{
				$note('warnings', $property . ' has a ' . local_types($types[(string)$o], $vocab) . ' (expected ' . implode(', ', $definition->range) . ')');
			}
		}
	}

	return $result;
}

//----------------------------------------------------------------------------------------
// The most specific types in a list of types and their superclasses, e.g. "Book".
function local_types($types, $vocab)
{
	$specific = array();
	foreach (array_unique($types) as $type)
	{
		$is_super = false;
		foreach ($types as $other)
		{
			if ($other != $type && in_array($type, superclasses($vocab, $other)))
			{
				$is_super = true;
			}
		}
		if (!$is_super)
		{
			$specific[] = $type;
		}
	}
	return implode('/', $specific);
}

//----------------------------------------------------------------------------------------

$vocab = load_vocabulary(dirname(__FILE__) . '/testsets/schemaorg-current-http.jsonld');

// One of each kind of output
$cases = array(
	'search all: Rhacophorus'           => function () { return run_search('all', 'Rhacophorus', 0, 20, 3); },
	'search articles: new reptiles Borneo' => function () { return run_search('articles', 'new reptiles Borneo', 0, 20); },
	'search titles: Hampson Lepidoptera' => function () { return run_search('titles', 'Hampson Lepidoptera', 0, 20); },
	'search titles: issn:0374-5481'     => function () { return run_search('titles', 'issn:0374-5481', 0, 20); },
	'browse titles: 1890s, zoolog'      => function () { return run_search('titles', 'zoolog', 0, 48, 3, array('decade' => 1890)); },
	'title 9243'                        => function () { return get_title(9243); },
	'item 19398'                        => function () { return get_item(19398); },
	'item 222763'                       => function () { return get_item(222763); },
);

$failed = 0;

foreach ($cases as $label => $make)
{
	$check = check_jsonld($make(), $vocab, $allowed_predicates);

	printf("%-38s %6s triples  %d error%s, %d warning%s\n",
		$label,
		number_format($check['triples']),
		count($check['errors']), count($check['errors']) == 1 ? '' : 's',
		count($check['warnings']), count($check['warnings']) == 1 ? '' : 's');

	foreach (array('errors' => 'ERROR', 'warnings' => 'warning') as $level => $tag)
	{
		foreach ($check[$level] as $message => $count)
		{
			echo '    ' . $tag . ': ' . $message . ($count > 1 ? ' (x' . $count . ')' : '') . "\n";
		}
	}

	if (count($check['errors']) > 0)
	{
		$failed++;
	}
}

echo "\n" . ($failed == 0 ? 'All valid' : $failed . ' case' . ($failed == 1 ? '' : 's') . ' with errors') . "\n";

exit($failed == 0 ? 0 : 1);

?>
