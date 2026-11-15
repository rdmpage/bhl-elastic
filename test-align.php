<?php

// Tests align.php, which places search hits on page images by matching the text
// searched to the OCR word boxes, as if the text came from different OCR (an LLM, say).
// For random pages of the words database, text is made from the page's own OCR words,
// changed as other OCR might change it: the running head and page number left out,
// words split at line ends joined, some words misread, left out or added, and on some
// pages a block of lines moved. Then each word of that text should find its own box.
//
//   php test-align.php            (200 pages)
//   php test-align.php 1000

require_once (dirname(__FILE__) . '/manifest.php');

mt_srand(42);

$n = isset($argv[1]) ? (int)$argv[1] : 200;

$pageids = words_pdo()->query('SELECT PageID FROM page WHERE ItemID IN (SELECT ItemID FROM item WHERE status = \'ok\' ORDER BY random() LIMIT 50) ORDER BY random() LIMIT ' . $n)->fetchAll(PDO::FETCH_COLUMN);

// One letter changed, as a misreading (or, if it has none of the letters often misread,
// one added)
function misread($word)
{
	preg_match_all('/[a-z]/', $word, $m, PREG_OFFSET_CAPTURE);
	if (count($m[0]) == 0)
	{
		return $word;
	}
	$i = $m[0][mt_rand(0, count($m[0]) - 1)][1];
	$swap = array('e' => 'c', 'c' => 'e', 'l' => '1', 'i' => 'l', 'n' => 'u', 'u' => 'n', 'o' => '0', 'm' => 'rn', 'a' => 'o', 's' => 'g');
	return substr($word, 0, $i) . (isset($swap[$word[$i]]) ? $swap[$word[$i]] : $word[$i] . 'x') . substr($word, $i + 1);
}

$stats = array('words' => 0, 'right' => 0, 'wrong' => 0, 'none' => 0, 'pages' => 0, 'time' => 0);

foreach (array_chunk($pageids, 100) as $chunk)
foreach (pages_word_boxes($chunk) as $pageid => $boxes)
{
	// The page's lines, each a list of [word, box index]
	$lines = array();
	foreach ($boxes as $b => $box)
	{
		if (preg_match('/[\p{L}\p{N}]/u', $box[4]))
		{
			$lines[$box[5]][] = array($box[4], $b);
		}
	}
	$lines = array_values($lines);
	if (count($lines) < 5)
	{
		continue;
	}

	// No running head or page number
	array_shift($lines);
	array_pop($lines);

	// A block of lines moved to the end (a caption or footnote placed elsewhere)
	if (count($lines) >= 4 && mt_rand(0, 2) == 0)
	{
		$at = mt_rand(0, count($lines) - 4);
		$block = array_splice($lines, $at, 3);
		$lines = array_merge($lines, $block);
	}

	// The text, with which box each of its words came from (-1 if from none)
	$text = '';
	$source = array();
	foreach ($lines as $line)
	{
		foreach ($line as $k => $word)
		{
			list($w, $b) = $word;
			$r = mt_rand(0, 99);
			if ($r < 2)
			{
				continue;  // left out
			}
			if ($r < 4)
			{
				$source[] = array(strlen($text), -1);
				$text .= 'zq' . mt_rand(10, 99) . ' ';  // added
			}
			// Hyphenated at the end of a line: joined to the next line's first word
			if ($k == count($line) - 1 && preg_match('/^(\p{L}+)-$/u', $w, $m))
			{
				$source[] = array(strlen($text), $b);
				$text .= $m[1];
				continue;
			}
			if ($r < 8 && strlen($w) > 3)
			{
				$w = misread($w);
			}
			$source[] = array(strlen($text), $b);
			$text .= $w . ' ';
		}
		$text .= "\n";
	}

	$t = microtime(true);
	$tb = new TextBoxes($text, $boxes);
	$tb->hit_boxes(0, 1);   // aligns
	$stats['time'] += microtime(true) - $t;
	$stats['pages']++;

	// Each word that came from a box should find that box
	foreach ($tb->text_words as $i => $w)
	{
		$from = -1;
		foreach ($source as $s)
		{
			if ($s[0] <= $w[1])
			{
				$from = $s[1];
			}
		}
		if ($from < 0)
		{
			continue;
		}
		$stats['words']++;
		$got = $tb->hit_boxes($w[1], $w[2]);
		$want = array_slice($boxes[$from], 0, 4);
		if (count($got) == 0)
		{
			$stats['none']++;
		}
		else
		{
			// Right if one of its boxes holds the word's box (a joined word gets the
			// boxes of both its halves)
			$ok = false;
			foreach ($got as $g)
			{
				$ok = $ok || ($g[0] <= $want[0] && $g[1] <= $want[1]
					&& $g[0] + $g[2] >= $want[0] + $want[2] && $g[1] + $g[3] >= $want[1] + $want[3]);
			}
			$stats[$ok ? 'right' : 'wrong']++;
		}
	}
}

printf("%d pages, %d words: %.1f%% right, %.1f%% wrong, %.1f%% not found; aligning took %.1f ms a page\n",
	$stats['pages'], $stats['words'],
	100 * $stats['right'] / $stats['words'], 100 * $stats['wrong'] / $stats['words'], 100 * $stats['none'] / $stats['words'],
	1000 * $stats['time'] / $stats['pages']);

?>
