<?php

// Search API: the same results as the web page, as JSON-LD.
//
//   api.php?q=Flourensia+dentata
//   api.php?q=new+species+Borneo&from=20
//   api.php?q=Rhacophorus&pages=10     (matching pages listed per result, up to 100)
//   api.php?q=Dillon+1984+Flourensia&type=articles   (search article metadata)
//   api.php?title=9243                  (a title and its items)

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/search.php');
require_once (dirname(__FILE__) . '/records.php');

//----------------------------------------------------------------------------------------
function send_json($status, $obj)
{
	http_response_code($status);
	header('Content-Type: application/ld+json; charset=utf-8');
	header('Access-Control-Allow-Origin: *');
	echo json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

//----------------------------------------------------------------------------------------
function main()
{
	$q     = isset($_GET['q']) ? trim($_GET['q']) : '';
	$from  = isset($_GET['from']) ? max(0, (int)$_GET['from']) : 0;
	$pages = isset($_GET['pages']) ? (int)$_GET['pages'] : 3;
	$type  = isset($_GET['type']) && isset(search_types()[$_GET['type']]) ? $_GET['type'] : 'all';

	if (isset($_GET['title']))
	{
		try
		{
			$title = get_title((int)$_GET['title']);
			$title ? send_json(200, $title) : send_json(404, array('error' => 'No such title'));
		}
		catch (Exception $e)
		{
			send_json(502, array('error' => $e->getMessage()));
		}
		return;
	}

	if ($q == '')
	{
		send_json(400, array('error' => 'Give a query as q'));
		return;
	}

	try
	{
		send_json(200, run_search($type, $q, $from, 20, $pages));
	}
	catch (Exception $e)
	{
		send_json(502, array('error' => $e->getMessage()));
	}
}

main();

?>
