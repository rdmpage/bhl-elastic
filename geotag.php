<?php

// Latitude and longitude pairs in page text, e.g. "29°59'23"N, 102°58'52"W", as
// annotations: where the pair is in the text, quoted (prefix, exact, suffix, as for
// search hits, see text_hits() in manifest.php), and the point it gives.
//
// Based on geotag.php in bhl-elastic-test. Changes: more of the ways pairs are written
// (separators such as "S., ", "/", "-", "&" and "long."; latitude in whole degrees; a
// degree sign or a single mark for minutes or seconds, as OCR often gives); every
// match's values are checked (degrees within range, minutes and seconds under 60), so
// OCR errors such as "116°377E" are dropped rather than becoming wrong points; matches
// by more than one pattern are kept once; and positions are in bytes, like
// text_hits(), so a pair can be placed on the page image by TextBoxes::hit_boxes() in
// align.php.

//----------------------------------------------------------------------------------------
// The patterns, each a regex with named groups lat_deg, lat_min, lat_sec, lat_hem and
// lon_deg, lon_min, lon_sec, lon_hem (minutes and seconds may be missing). A minus sign
// before degrees is ignored, as the hemisphere follows ("-96°04’34.85”W").
function geo_patterns()
{
	static $patterns = null;

	if ($patterns)
	{
		return $patterns;
	}

	$DEG = '\s?[˚°º]';                         // degree sign, maybe after a space
	$MIN = '[\'’′´‘°"”]';                       // minutes (OCR often gives a degree sign or ")
	$SEC = '(?:"|\'\'|’’|”|″|′′|´´|“|[\'’′´])'; // seconds, sometimes a single mark
	$NUM = '\d++(?:[.,]\d++)?';                 // whole or decimal, with a point or a comma,
	                                            // never split in two (so "58" is not 5' 8")
	$S   = '\s{0,2}';                           // a space or two, or a line break

	// Degrees, then optional minutes, then optional seconds, each usually with its mark
	$dms = function ($p, $max_digits) use ($DEG, $MIN, $SEC, $NUM, $S) {
		return "-?(?<{$p}_deg>\d{1,$max_digits}+(?:[.,]\d++)?)$DEG"
			. "(?:$S(?<{$p}_min>$NUM)$S$MIN?"
			. "(?:$S(?<{$p}_sec>$NUM)$S$SEC?)?)?";
	};

	$lat = $dms('lat', 2);
	$lon = $dms('lon', 3);

	// Between latitude and longitude: a comma, semicolon, slash, ampersand, dash,
	// "and" (or Spanish "y", French "et", Portuguese "e"), or just space; then maybe
	// "long."
	$SEP = "$S(?:[,;\/&]|[-–—]|(?:and|y|et|e)\s)?$S(?:long(?:itude)?\.?$S)?";

	$patterns = array(
		// 29°59'23"N, 102°58'52"W; 33°35.5' N, 117°30.0' W; 1° 10’ S., 31° 30’ E;
		// 70° 36’ N., long. 32° 35’ E; 30°2’S/15°2’E; 4°19´44”N y 71°43´54.1”O
		// Not the tail of something longer, such as "51.8°N" in "24°28'°51.8°N", which
		// would be a wrong point
		"/(?<![\d.,˚°º\'’′´‘\"”])$lat$S(?<lat_hem>[NS])\.?$SEP$lon$S(?<lon_hem>[EWO])\b/u",

		// N25°59', E98°40'; N27.21234º, E098.69601º
		"/\b(?<lat_hem>[NS])$S$lat$SEP(?<lon_hem>[EWO])$S$lon/u",
	);

	return $patterns;
}

//----------------------------------------------------------------------------------------
// Decimal degrees from degrees, minutes and seconds (any may use a decimal comma), or
// null if they are out of range: minutes and seconds must be under 60, and the result
// no more than $max.
function geo_decimal($deg, $min, $sec, $hem, $max)
{
	$deg = (float)str_replace(',', '.', $deg);
	$min = $min === '' ? 0.0 : (float)str_replace(',', '.', $min);
	$sec = $sec === '' ? 0.0 : (float)str_replace(',', '.', $sec);

	if ($min >= 60 || $sec >= 60)
	{
		return null;
	}

	$value = $deg + $min / 60 + $sec / 3600;

	if ($value > $max)
	{
		return null;
	}

	// South, west, and Spanish "oeste" (O)
	if (in_array($hem, array('S', 'W', 'O')))
	{
		$value = -$value;
	}

	return round($value, 6);
}

//----------------------------------------------------------------------------------------
// The text around $start..$end (bytes) in $text, as [prefix, exact, suffix], with runs
// of white space as one space, prefix and suffix up to about $context characters and
// ending at a word, as in text_hits()
function text_quote($text, $start, $end, $context = 50)
{
	$flat = function ($s) {
		return preg_replace('/\s+/u', ' ', $s);
	};

	$before = $flat(substr($text, 0, $start));
	$after  = $flat(substr($text, $end));

	$prefix = mb_substr($before, -$context);
	if (mb_strlen($before) > $context)
	{
		$prefix = preg_replace('/^\S*\s/u', '', $prefix);
	}
	$suffix = mb_substr($after, 0, $context);
	if (mb_strlen($after) > $context)
	{
		$suffix = preg_replace('/\s\S*$/u', '', $suffix);
	}

	return array(ltrim($prefix), $flat(substr($text, $start, $end - $start)), rtrim($suffix));
}

//----------------------------------------------------------------------------------------
// The latitude and longitude pairs in a page's text, in the order they appear, each as
// an array with prefix, exact, suffix, start and end (bytes), lat and lon
function tag_geo($text)
{
	$found = array();

	foreach (geo_patterns() as $pattern)
	{
		if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE))
		{
			continue;
		}

		foreach ($matches as $m)
		{
			$get = function ($name) use ($m) {
				return isset($m[$name]) && $m[$name][1] >= 0 ? $m[$name][0] : '';
			};

			$lat = geo_decimal($get('lat_deg'), $get('lat_min'), $get('lat_sec'), $get('lat_hem'), 90);
			$lon = geo_decimal($get('lon_deg'), $get('lon_min'), $get('lon_sec'), $get('lon_hem'), 180);

			if ($lat === null || $lon === null)
			{
				continue;
			}

			$start = $m[0][1];
			$end   = $start + strlen($m[0][0]);

			// Keep the first of matches that overlap
			foreach ($found as $f)
			{
				if ($start < $f['end'] && $end > $f['start'])
				{
					continue 2;
				}
			}

			list($prefix, $exact, $suffix) = text_quote($text, $start, $end);

			$found[] = array(
				'prefix' => $prefix,
				'exact'  => $exact,
				'suffix' => $suffix,
				'start'  => $start,
				'end'    => $end,
				'lat'    => $lat,
				'lon'    => $lon,
			);
		}
	}

	usort($found, function ($a, $b) { return $a['start'] - $b['start']; });

	return $found;
}

?>
