<?php

// Search API: the same results as the web page, as JSON-LD.
//
//   api.php?q=Flourensia+dentata
//   api.php?q=new+species+Borneo&from=20

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/search.php');

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
	$q    = isset($_GET['q']) ? trim($_GET['q']) : '';
	$from = isset($_GET['from']) ? max(0, (int)$_GET['from']) : 0;

	if ($q == '')
	{
		send_json(400, array('error' => 'Give a query as q'));
		return;
	}

	try
	{
		send_json(200, search_text($q, $from));
	}
	catch (Exception $e)
	{
		send_json(502, array('error' => $e->getMessage()));
	}
}

main();

?>
