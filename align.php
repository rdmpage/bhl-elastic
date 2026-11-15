<?php

// Matching a page's text to its OCR word boxes, to find where search hits are on the
// page. The text searched (Elasticsearch's copy of BHL's OCR) and the boxes (from the
// Internet Archive's OCR) mostly have the same words, but not always: one may have been
// OCRed again, or by an LLM, which gives no boxes. So the two lists of words are
// aligned, allowing for words missing, added, or misread in either (align_words()),
// and a hit's words are then looked up in the alignment (TextBoxes).

//----------------------------------------------------------------------------------------
// The words of a text, for matching texts from different OCR: lower case, without
// accents, split at anything not a letter or digit, so "Leaf-" and "leaf" match. Each
// is [word, start, end], start and end being where it is in the text (in bytes).
function match_words($text)
{
	preg_match_all('/[\p{L}\p{N}]+/u', $text, $m, PREG_OFFSET_CAPTURE);

	$words = array();
	foreach ($m[0] as $w)
	{
		$folded = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize(mb_strtolower($w[0]), Normalizer::FORM_D));
		$words[] = array($folded, $w[1], $w[1] + strlen($w[0]));
	}
	return $words;
}

//----------------------------------------------------------------------------------------
// Pairs of words, one from each of two texts, that are the same or nearly so, in the
// same order in both, as a map from indexes in $a to indexes in $b ($a and $b are
// lists of words, each [word, ...], as from match_words()).
//
// Like a diff ("patience diff"): words the same at the start and the end are paired;
// then words found just once in each are anchors, as many of them as are in the same
// order in both; then the same is done between each pair of anchors. What is left, in
// small gaps, is aligned word by word, pairing words that are the same or differ by a
// letter or two (OCR misreadings). Last, the words left unpaired in each are aligned
// in the same way, which pairs blocks of text that are in a different place in each
// (a caption or footnote, say).
function align_words($a, $b)
{
	$a_words = array();
	foreach ($a as $w)
	{
		$a_words[] = $w[0];
	}
	$b_words = array();
	foreach ($b as $w)
	{
		$b_words[] = $w[0];
	}

	$map = array();
	align_range($a_words, 0, count($a_words), $b_words, 0, count($b_words), $map);

	// The words left over, in order, and where they are
	$a_left = array_values(array_diff_key(array_keys($a_words), $map));
	$b_left = array_values(array_diff(array_keys($b_words), $map));
	if (count($a_left) > 0 && count($b_left) > 0)
	{
		$a_rest = array();
		foreach ($a_left as $i)
		{
			$a_rest[] = $a_words[$i];
		}
		$b_rest = array();
		foreach ($b_left as $j)
		{
			$b_rest[] = $b_words[$j];
		}
		$rest = array();
		align_range($a_rest, 0, count($a_rest), $b_rest, 0, count($b_rest), $rest);
		foreach ($rest as $i => $j)
		{
			$map[$a_left[$i]] = $b_left[$j];
		}
	}

	ksort($map);
	return $map;
}

//----------------------------------------------------------------------------------------
// Align $a[$a0..$a1) with $b[$b0..$b1), adding pairs to $map (see align_words())
function align_range($a, $a0, $a1, $b, $b0, $b1, &$map)
{
	while ($a0 < $a1 && $b0 < $b1 && $a[$a0] == $b[$b0])
	{
		$map[$a0++] = $b0++;
	}
	while ($a0 < $a1 && $b0 < $b1 && $a[$a1 - 1] == $b[$b1 - 1])
	{
		$map[--$a1] = --$b1;
	}
	if ($a0 == $a1 || $b0 == $b1)
	{
		return;
	}

	$anchors = unique_anchors($a, $a0, $a1, $b, $b0, $b1);

	if (count($anchors) > 0)
	{
		foreach ($anchors as $pair)
		{
			align_range($a, $a0, $pair[0], $b, $b0, $pair[1], $map);
			$map[$pair[0]] = $pair[1];
			$a0 = $pair[0] + 1;
			$b0 = $pair[1] + 1;
		}
		align_range($a, $a0, $a1, $b, $b0, $b1, $map);
	}
	elseif (($a1 - $a0) * ($b1 - $b0) <= 10000)
	{
		align_near($a, $a0, $a1, $b, $b0, $b1, $map);
	}
	// else a large stretch with nothing in common: leave it
}

//----------------------------------------------------------------------------------------
// Words found once in $a[$a0..$a1) and once in $b[$b0..$b1), as pairs of indexes
// [i, j], the most of them that are in the same order in both (the longest increasing
// subsequence of j, ordered by i)
function unique_anchors($a, $a0, $a1, $b, $b0, $b1)
{
	$in_a = array();
	for ($i = $a0; $i < $a1; $i++)
	{
		$in_a[$a[$i]] = isset($in_a[$a[$i]]) ? -1 : $i;
	}
	$in_b = array();
	for ($j = $b0; $j < $b1; $j++)
	{
		$in_b[$b[$j]] = isset($in_b[$b[$j]]) ? -1 : $j;
	}

	$pairs = array();
	foreach ($in_a as $word => $i)
	{
		if ($i >= 0 && isset($in_b[$word]) && $in_b[$word] >= 0)
		{
			$pairs[] = array($i, $in_b[$word]);
		}
	}
	if (count($pairs) == 0)
	{
		return $pairs;
	}
	sort($pairs);

	// Longest increasing subsequence of the j's: $tails[k] is the pair ending the best
	// run of length k + 1 found so far, $back the pair before each pair in its run
	$tails = array();
	$back  = array();
	foreach ($pairs as $p => $pair)
	{
		$lo = 0;
		$hi = count($tails);
		while ($lo < $hi)
		{
			$mid = ($lo + $hi) >> 1;
			if ($pairs[$tails[$mid]][1] < $pair[1])
			{
				$lo = $mid + 1;
			}
			else
			{
				$hi = $mid;
			}
		}
		$back[$p] = $lo > 0 ? $tails[$lo - 1] : -1;
		$tails[$lo] = $p;
	}

	$run = array();
	for ($p = $tails[count($tails) - 1]; $p >= 0; $p = $back[$p])
	{
		$run[] = $pairs[$p];
	}
	return array_reverse($run);
}

//----------------------------------------------------------------------------------------
// How alike two words are: 2 the same, 1 nearly (at least three letters, and at most
// about one letter in four different), 0 not
function word_likeness($x, $y)
{
	if ($x == $y)
	{
		return 2;
	}
	$n = max(strlen($x), strlen($y));
	if (min(strlen($x), strlen($y)) < 3 || abs(strlen($x) - strlen($y)) > $n / 4)
	{
		return 0;
	}
	return levenshtein($x, $y) <= max(1, (int)($n / 4)) ? 1 : 0;
}

//----------------------------------------------------------------------------------------
// Align a small stretch word by word (dynamic programming, as for a longest common
// subsequence, but scoring words by word_likeness())
function align_near($a, $a0, $a1, $b, $b0, $b1, &$map)
{
	$n = $a1 - $a0;
	$m = $b1 - $b0;

	$score = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
	$like  = array();
	for ($i = 1; $i <= $n; $i++)
	{
		for ($j = 1; $j <= $m; $j++)
		{
			$like[$i][$j] = word_likeness($a[$a0 + $i - 1], $b[$b0 + $j - 1]);
			$best = max($score[$i - 1][$j], $score[$i][$j - 1]);
			if ($like[$i][$j] > 0)
			{
				$best = max($best, $score[$i - 1][$j - 1] + $like[$i][$j]);
			}
			$score[$i][$j] = $best;
		}
	}

	for ($i = $n, $j = $m; $i > 0 && $j > 0; )
	{
		if ($like[$i][$j] > 0 && $score[$i][$j] == $score[$i - 1][$j - 1] + $like[$i][$j])
		{
			$map[$a0 + $i - 1] = $b0 + $j - 1;
			$i--;
			$j--;
		}
		elseif ($score[$i - 1][$j] >= $score[$i][$j - 1])
		{
			$i--;
		}
		else
		{
			$j--;
		}
	}
}

//----------------------------------------------------------------------------------------
// A page's text matched to its OCR word boxes. hit_boxes() gives the boxes of the words
// in a part of the text.
class TextBoxes
{
	var $text_words;  // match_words() of the text
	var $boxes;       // [x, y, w, h, text, line]
	var $box_words;   // [word, box index], match_words() of each box's text
	var $map = null;  // text word index => box word index, from align_words()

	function __construct($text, $boxes)
	{
		$this->text_words = match_words($text);
		$this->boxes = $boxes;
		$this->box_words = array();
		foreach ($boxes as $b => $box)
		{
			foreach (match_words($box[4]) as $w)
			{
				$this->box_words[] = array($w[0], $b);
			}
		}
	}

	// The boxes of the words in the text from byte $start to $end, as boxes on the
	// canvas, [x, y, w, h], one for each line they are on; empty if not found
	function hit_boxes($start, $end)
	{
		if ($this->map === null)
		{
			$this->map = align_words($this->text_words, $this->box_words);
		}

		// The hit's words in the text
		$first = -1;
		$last = -1;
		foreach ($this->text_words as $i => $w)
		{
			if ($w[2] > $start && $w[1] < $end)
			{
				if ($first < 0)
				{
					$first = $i;
				}
				$last = $i;
			}
		}
		if ($first < 0)
		{
			return array();
		}

		// Their words in the boxes
		$found = array();
		for ($i = $first; $i <= $last; $i++)
		{
			if (isset($this->map[$i]))
			{
				$found[] = $this->map[$i];
			}
		}

		// None paired (misread beyond recognition, or joined, as "leaflitter" for
		// "leaf-" "litter"): the box words between the boxes of the paired words either
		// side of the hit, if they are next to each other in the text too, and the gaps
		// between them in the text and in the boxes are small and about the same
		if (count($found) == 0)
		{
			for ($before = $first - 1; $before >= 0 && !isset($this->map[$before]); $before--);
			for ($after = $last + 1; $after < count($this->text_words) && !isset($this->map[$after]); $after++);

			if ($before >= 0 && $after < count($this->text_words))
			{
				$text_gap = $after - $before - 1;
				$box_gap  = $this->map[$after] - $this->map[$before] - 1;
				if ($box_gap > 0 && $text_gap <= 5 && abs($box_gap - $text_gap) <= 2)
				{
					$found = range($this->map[$before] + 1, $this->map[$after] - 1);
				}
			}
		}

		// One box for each line, around its words
		$lines = array();
		foreach ($found as $k)
		{
			$box = $this->boxes[$this->box_words[$k][1]];
			$l = $box[5];
			if (!isset($lines[$l]))
			{
				$lines[$l] = array($box[0], $box[1], $box[0] + $box[2], $box[1] + $box[3]);
			}
			else
			{
				$lines[$l] = array(min($lines[$l][0], $box[0]), min($lines[$l][1], $box[1]),
					max($lines[$l][2], $box[0] + $box[2]), max($lines[$l][3], $box[1] + $box[3]));
			}
		}

		$result = array();
		foreach ($lines as $r)
		{
			$result[] = array($r[0], $r[1], $r[2] - $r[0], $r[3] - $r[1]);
		}
		return $result;
	}
}

?>
