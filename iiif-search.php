<?php

// Search inside an item or article (IIIF Content Search 2.0; built by search_inside() in
// manifest.php). Manifests link to it as a SearchService2.
//
//   iiif-search.php/item/262715?q=Gulella
//   iiif-search.php/part/431953?q=Gulella&from=50   (the next pages of hits)

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/manifest.php');

//----------------------------------------------------------------------------------------
function send_search($status, $obj)
{
	ob_start('ob_gzhandler');

	http_response_code($status);
	header('Content-Type: application/ld+json;profile="' . SEARCH_CONTEXT . '"; charset=utf-8');
	header('Access-Control-Allow-Origin: *');
	echo json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

//----------------------------------------------------------------------------------------
function main()
{
	$path = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
	$q    = isset($_GET['q']) ? trim($_GET['q']) : '';
	$from = isset($_GET['from']) ? max(0, (int)$_GET['from']) : 0;

	if (!preg_match('/^\/(item|part)\/(\d+)$/', $path, $m))
	{
		send_search(400, array('error' => 'Give an item or part, e.g. iiif-search.php/item/262715?q=Gulella'));
		return;
	}

	try
	{
		$page = $q == ''
			? (object)array('@context' => SEARCH_CONTEXT, 'type' => 'AnnotationPage', 'items' => array())
			: search_inside($m[1], (int)$m[2], $q, $from);

		// We only search the text, so other parameters are ignored, as the API requires
		// us to say
		$ignored = array_values(array_intersect(array('motivation', 'date', 'user'), array_keys($_GET)));
		if (count($ignored) > 0)
		{
			$page->ignored = $ignored;
		}

		send_search(200, $page);
	}
	catch (Exception $e)
	{
		send_search(502, array('error' => $e->getMessage()));
	}
}

main();

?>
