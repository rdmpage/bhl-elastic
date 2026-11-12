<?php

// Run the test searches in testsets/queries.txt and print the top results of each, so
// the effect of a change to the query can be compared before and after.
//
//   php test-queries.php [testsets/queries.txt] [N]

require_once (dirname(__FILE__) . '/search.php');

$file = isset($argv[1]) ? $argv[1] : dirname(__FILE__) . '/testsets/queries.txt';
$n    = isset($argv[2]) ? (int)$argv[2] : 5;

foreach (file($file, FILE_IGNORE_NEW_LINES) as $line)
{
	$q = trim($line);

	if ($q == '' || $q[0] == '#')
	{
		continue;
	}

	$list = search_text($q, 0, $n);

	echo $q . ' (' . number_format($list->numberOfItems) . ")\n";

	foreach ($list->itemListElement as $element)
	{
		$work = $element->item;
		printf("  %6.1f  %s [%s] %s\n",
			$element->resultScore,
			mb_strimwidth(isset($work->name) ? $work->name : $work->id, 0, 70, '…'),
			isset($work->datePublished) ? $work->datePublished : '',
			$work->id);
	}

	echo "\n";
}

?>
