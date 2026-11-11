<?php

// extract-items.php
//
// Copy the OCR for a list of items out of the BHL export into a small archive of their
// own, so experiments do not each need a pass through 45 GB of bzip2.
//
//   php extract-items.php --archive /Volumes/LaCie/bhl/ocr/bhl-ocr-20260514.tar.bz2 \
//       --items testsets/bhl-light-items.txt --out bhlocr/bhl-light-20260514.tar.zst
//
// Entries are copied byte for byte, headers included, so the result reads exactly like
// the original (load-ocr.php --archive works on it). --out ending in .zst is compressed
// with zstd, otherwise a plain .tar is written. part/ entries are not copied.

require_once (dirname(__FILE__) . '/ocr.php');

$options = getopt('', array('archive:', 'items:', 'out:'));

if (!isset($options['archive']) || !isset($options['items']) || !isset($options['out']))
{
	fwrite(STDERR, "Usage: php extract-items.php --archive FILE --items FILE --out FILE[.zst]\n");
	exit(1);
}

$items = read_item_list($options['items']);
$out   = $options['out'];

$output = preg_match('/\.zst$/', $out)
	? popen('zstd -q -T0 -o ' . escapeshellarg($out) . ' -', 'w')
	: fopen($out, 'wb');

$want = function ($path) use ($items)
{
	$parsed = ocr_parse_path($path);
	return $parsed && $parsed[0] == 'item' && isset($items[$parsed[1]]);
};

$seen    = array();
$files   = 0;
$bytes   = 0;
$current = null;
$started = microtime(true);

foreach (tar_entries(ocr_open($options['archive']), $want) as $entry)
{
	list($path, $size, $data, $header) = $entry;

	$parsed = ocr_parse_path($path);

	if (!$parsed || $parsed[0] != 'item')
	{
		continue;
	}

	// Stop once the archive has moved past the last listed item.
	if ($parsed[1] !== $current)
	{
		if ($current !== null && isset($seen[$current]) && count($seen) == count($items))
		{
			break;
		}
		$current = $parsed[1];
	}

	if ($data === null)
	{
		continue;
	}

	$seen[$parsed[1]] = true;

	fwrite($output, $header . $data . str_repeat("\0", (512 - $size % 512) % 512));
	$files++;
	$bytes += $size;

	if ($files % 100000 == 0)
	{
		fwrite(STDERR, sprintf("  %s files from %s items, %.1f min\n",
			number_format($files), number_format(count($seen)), (microtime(true) - $started) / 60));
	}
}

// End-of-archive marker: two zero blocks.
fwrite($output, str_repeat("\0", 1024));

preg_match('/\.zst$/', $out) ? pclose($output) : fclose($output);

$missing = array_diff_key($items, $seen);

fwrite(STDERR, sprintf("%s files, %s MB of text, from %s of %s items in %.1f min\n",
	number_format($files), number_format($bytes / 1048576), number_format(count($seen)),
	number_format(count($items)), (microtime(true) - $started) / 60));

if (count($missing) > 0)
{
	fwrite(STDERR, "Not in the archive: " . join(' ', array_slice(array_keys($missing), 0, 50))
		. (count($missing) > 50 ? ' ...' : '') . "\n");
}

?>
