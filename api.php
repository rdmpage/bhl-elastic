<?php

// Search API: the same results as the web page, as JSON-LD.
//
//   api.php?q=Flourensia+dentata
//   api.php?q=new+species+Borneo&from=20
//   api.php?q=Rhacophorus&pages=10     (matching pages listed per result, up to 100)
//   api.php?q=Dillon+1984+Flourensia&type=articles   (search article metadata)
//   api.php?type=titles&decade=1890&q=zoology   (browse titles, with counts; filters
//                                       are decade, kind, language, library, subject)
//   api.php?title=9243                  (a title and its items)
//   api.php?item=222763                 (an item, its articles and its pages)
//   api.php?part=431953                 (an article and its pages)
//   api.php?bbox=29,-26,32,-22         (pages with a latitude and longitude pair in their
//                                       text in an area, west,south,east,north, grouped
//                                       like a text search; add q to search them)
//   api.php?type=map&bbox=-180,-90,180,90&zoom=2   (how many such pages there are in
//                                       each part of the map, as GeoJSON; q as above)
//   api.php?part=431953&format=csl      (the article as CSL-JSON, for citations; also
//                                       sent for Accept: application/vnd.citationstyles.csl+json)

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/search.php');
require_once (dirname(__FILE__) . '/records.php');

//----------------------------------------------------------------------------------------
function send_json($status, $obj, $type = 'application/ld+json')
{
	http_response_code($status);
	header('Content-Type: ' . $type . '; charset=utf-8');
	header('Access-Control-Allow-Origin: *');
	header('Vary: Accept');
	echo json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

//----------------------------------------------------------------------------------------
// Whether to send CSL-JSON rather than JSON-LD: asked for with format=csl, or by content
// negotiation, as doi.org does
function wants_csl()
{
	if (isset($_GET['format']))
	{
		return $_GET['format'] == 'csl';
	}
	return isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/vnd.citationstyles.csl+json') !== false;
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

	if (isset($_GET['item']))
	{
		try
		{
			$item = get_item((int)$_GET['item']);
			$item ? send_json(200, $item) : send_json(404, array('error' => 'No such item'));
		}
		catch (Exception $e)
		{
			send_json(502, array('error' => $e->getMessage()));
		}
		return;
	}

	if (isset($_GET['part']))
	{
		try
		{
			$part = get_part((int)$_GET['part']);
			if (!$part)
			{
				send_json(404, array('error' => 'No such part'));
			}
			elseif (wants_csl())
			{
				send_json(200, jsonld_to_csl($part), 'application/vnd.citationstyles.csl+json');
			}
			else
			{
				send_json(200, $part);
			}
		}
		catch (Exception $e)
		{
			send_json(502, array('error' => $e->getMessage()));
		}
		return;
	}

	$bbox = isset($_GET['bbox']) ? parse_bbox($_GET['bbox']) : null;

	// The map: counts of pages with points, as GeoJSON
	if (isset($_GET['type']) && $_GET['type'] == 'map')
	{
		try
		{
			$zoom = isset($_GET['zoom']) ? (int)$_GET['zoom'] : 2;
			send_json(200, map_grid($q, $bbox, $zoom), 'application/geo+json');
		}
		catch (Exception $e)
		{
			send_json(502, array('error' => $e->getMessage()));
		}
		return;
	}

	// Pages with points in an area, with or without a query
	if ($bbox && $type == 'all')
	{
		try
		{
			send_json(200, search_text($q, $from, 20, $pages, $bbox));
		}
		catch (Exception $e)
		{
			send_json(502, array('error' => $e->getMessage()));
		}
		return;
	}

	// Titles can be browsed without a query
	if ($q == '' && $type != 'titles')
	{
		send_json(400, array('error' => 'Give a query as q'));
		return;
	}

	try
	{
		$size = $type == 'titles' ? 48 : 20;
		send_json(200, run_search($type, $q, $from, $size, $pages, title_filters($_GET)));
	}
	catch (Exception $e)
	{
		send_json(502, array('error' => $e->getMessage()));
	}
}

main();

?>
