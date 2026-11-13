<?php

// Comparing two citation strings by their longest common subsequence, as in BioStor
// (~/Development/biostor-lite compare.php and lcs.php).

//----------------------------------------------------------------------------------------
// Lowercase letters, digits and single spaces only, accents removed.
function normalise_text($text)
{
	$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

	// "J.Wash." -> "J. Wash."
	$text = preg_replace('/\.(\p{L})/u', '. $1', $text);

	// remove accents, https://stackoverflow.com/a/2759179
	$text = preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml|caron);~i', '$1', htmlentities($text, ENT_QUOTES, 'UTF-8'));
	$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

	$text = preg_replace('/[^a-z0-9 ]/i', '', $text);
	$text = preg_replace('/\s+/', ' ', $text);

	return strtolower(trim($text));
}

//----------------------------------------------------------------------------------------
// Length of the longest common subsequence of two strings, keeping only two rows of
// the table.
function lcs_length($x, $y)
{
	$m = strlen($x);
	$n = strlen($y);

	$previous = array_fill(0, $n + 1, 0);

	for ($i = 1; $i <= $m; $i++)
	{
		$row = array(0);
		for ($j = 1; $j <= $n; $j++)
		{
			if ($x[$i - 1] == $y[$j - 1])
			{
				$row[$j] = $previous[$j - 1] + 1;
			}
			else
			{
				$row[$j] = max($row[$j - 1], $previous[$j]);
			}
		}
		$previous = $row;
	}

	return $previous[$n];
}

//----------------------------------------------------------------------------------------
// The common subsequence as a fraction of each string, smaller fraction first: [0] is
// how much of the longer string is covered, [1] how much of the shorter.
function compare_common_subsequence($text1, $text2)
{
	// long strings are slow to compare, and citations are short
	$text1 = substr(normalise_text($text1), 0, 500);
	$text2 = substr(normalise_text($text2), 0, 500);

	$length1 = strlen($text1);
	$length2 = strlen($text2);

	if ($length1 == 0 || $length2 == 0)
	{
		return array(0, 0);
	}

	$d = lcs_length($text1, $text2);

	return array($d / max($length1, $length2), $d / min($length1, $length2));
}

//----------------------------------------------------------------------------------------
// BioStor's test: one string is almost a subsequence of the other, and covers more than
// half of the longer one.
function is_match($normalised)
{
	return $normalised[1] > 0.9 && $normalised[0] > 0.5;
}

?>
