<?php

// Tests for geotag.php.
//
//   php test-geotag.php              examples with known answers
//   php test-geotag.php --scan N     tag the first N pages with text in the index and
//                                    report how many pages have coordinates, with
//                                    examples; --old FILE also runs bhl-elastic-test's
//                                    geotag.php and lists what only one of them finds

if (PHP_SAPI != 'cli')
{
	exit(1);
}

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/config.inc.php');
require_once (dirname(__FILE__) . '/elastic.php');
require_once (dirname(__FILE__) . '/geotag.php');

//----------------------------------------------------------------------------------------
// Text, and the points expected in it as [lat, lon]
function examples()
{
	return array(
		array('at 29°59\'23"N, 102°58\'52"W in', array(array(29.989722, -102.981111))),
		array('33°35.5\' N, 117°30.0\' W', array(array(33.591667, -117.5))),
		array('52°02\'N, 147°58\' E', array(array(52.033333, 147.966667))),
		array('20° 41’S 143° 08’E', array(array(-20.683333, 143.133333))),
		array('0°55\'S, 78°55\'W', array(array(-0.916667, -78.916667))),
		array('29.6° N, 101.8° E', array(array(29.6, 101.8))),
		array('N27.21234º, E098.69601º', array(array(27.21234, 98.69601))),
		array('N25°59\', E98°40\'', array(array(25.983333, 98.666667))),
		array('4°19´44”N y 71°43´54.1”O', array(array(4.328889, -71.731694))),
		array('12°30\'S; 45°10\'W and 13°S, 46°W', array(array(-12.5, -45.166667), array(-13, -46))),

		// Forms the old code missed
		array('1° 10’ S., 31° 30’ E', array(array(-1.166667, 31.5))),
		array('70° 36’ N., long. 32° 35’ E', array(array(70.6, 32.583333))),
		array('30°2’S/15°2’E', array(array(-30.033333, 15.033333))),
		array('08°44’S, 146°43°E', array(array(-8.733333, 146.716667))),
		array('12°43\'S-48°15\'E', array(array(-12.716667, 48.25))),
		array('0°12,6\' S-117°53,5\' E', array(array(-0.21, 117.891667))),
		array('47°38\'3 N — 8°06\'3 W', array(array(47.634167, -8.100833))),
		array('39°16’25”N 41°14’18’E', array(array(39.273611, 41.238333))),
		array('53°N 104°E', array(array(53, 104))),
		array('12°45\' S & 45°18\' E', array(array(-12.75, 45.3))),
		array('22°N and 73.5°E', array(array(22, 73.5))),
		array('24°22\'N. 110°58"W', array(array(24.366667, -110.966667))),
		array('49°39.16"N, 9°47 18.58”E', array(array(49.652667, 9.788494))),
		array('17°28’45.86”N, -96°04’34.85”W', array(array(17.479406, -96.076347))),

		// OCR errors that would give wrong points
		array('8°34’S 116°377E', array()),
		array('24°28\'°51.8°N 92°34’38.3"E', array()),
		array('95°10\'N, 20°00\'E', array()),
		array('10°20\'N, 190°00\'E', array()),

		// Not coordinates
		array('heated to 20°C for 30 minutes', array()),
		array('an angle of 45° to the axis', array()),
	);
}

//----------------------------------------------------------------------------------------
function run_examples()
{
	$failed = 0;

	foreach (examples() as $example)
	{
		list($text, $expected) = $example;

		$got = array();
		foreach (tag_geo($text) as $a)
		{
			$got[] = array($a['lat'], $a['lon']);
		}

		$ok = count($got) == count($expected);
		for ($i = 0; $ok && $i < count($got); $i++)
		{
			$ok = abs($got[$i][0] - $expected[$i][0]) < 1e-5 && abs($got[$i][1] - $expected[$i][1]) < 1e-5;
		}

		if (!$ok)
		{
			$failed++;
		}

		echo ($ok ? 'ok    ' : 'FAIL  ') . $text . ' => ' . json_encode($got) . ($ok ? '' : ', expected ' . json_encode($expected)) . "\n";
	}

	echo "\n" . (count(examples()) - $failed) . ' of ' . count(examples()) . " examples right\n";

	return $failed == 0;
}

//----------------------------------------------------------------------------------------
// Tag the first $limit pages with text
function run_scan($limit, $old)
{
	global $config;

	// The old code, with its tag_geo() renamed so both can be loaded
	if ($old)
	{
		require_once (dirname($old) . '/annotate.php');
		$code = file_get_contents($old);
		$code = preg_replace('/^<\?php|\?>\s*$|require_once.*?;/', '', $code);
		eval(str_replace('function tag_geo(', 'function tag_geo_old(', $code));
	}

	$elastic = new ElasticSearch($config['elastic_options']);

	$after    = null;
	$pages    = 0;
	$tagged   = 0;
	$found    = 0;
	$seconds  = 0;
	$examples = array();
	$only_new = array();
	$only_old = array();

	while ($pages < $limit)
	{
		$query = array(
			'size'    => min(5000, $limit - $pages),
			'_source' => array('text'),
			'sort'    => array(array('_doc' => 'asc')),
			'query'   => array('bool' => array('filter' => array(
				array('term' => array('type' => 'page')),
				array('exists' => array('field' => 'text')),
			))),
		);
		if ($after)
		{
			$query['search_after'] = $after;
		}

		$resp = $elastic->send('POST', '_search', json_encode($query));
		$hits = $resp[1]->hits->hits;
		if (count($hits) == 0)
		{
			break;
		}

		foreach ($hits as $hit)
		{
			$pages++;
			$text = $hit->_source->text;

			$start = microtime(true);
			$annotations = tag_geo($text);
			$seconds += microtime(true) - $start;

			if (count($annotations) > 0)
			{
				$tagged++;
				$found += count($annotations);
				if (count($examples) < 10)
				{
					$a = $annotations[0];
					$examples[] = $hit->_id . "\t" . $a['exact'] . ' => ' . $a['lat'] . ', ' . $a['lon'];
				}
			}

			if ($old)
			{
				$new_exact = array();
				foreach ($annotations as $a)
				{
					$new_exact[] = $a['exact'];
				}
				$old_exact = array();
				foreach (tag_geo_old($text) as $a)
				{
					$old_exact[] = preg_replace('/\s+/u', ' ', $a->text);
				}
				foreach (array_diff($new_exact, $old_exact) as $e) { $only_new[] = $hit->_id . "\t" . $e; }
				foreach (array_diff($old_exact, $new_exact) as $e) { $only_old[] = $hit->_id . "\t" . $e; }
			}
		}

		$after = end($hits)->sort;
	}

	printf("%d pages, %d with coordinates (%.2f%%), %d pairs, %.3f ms per page\n\n",
		$pages, $tagged, 100 * $tagged / max(1, $pages), $found, 1000 * $seconds / max(1, $pages));
	echo implode("\n", $examples) . "\n";

	if ($old)
	{
		echo "\nOnly found here (" . count($only_new) . "):\n" . implode("\n", array_slice($only_new, 0, 30)) . "\n";
		echo "\nOnly found by the old code (" . count($only_old) . "):\n" . implode("\n", array_slice($only_old, 0, 30)) . "\n";
	}
}

//----------------------------------------------------------------------------------------

$options = getopt('', array('scan:', 'old:'));

if (isset($options['scan']))
{
	run_scan((int)$options['scan'], isset($options['old']) ? $options['old'] : null);
}
else
{
	exit(run_examples() ? 0 : 1);
}

?>
