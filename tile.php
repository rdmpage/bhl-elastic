<?php

// Vector tiles of the latitude and longitude pairs found in page text (see geotag.php),
// for the map (index.php?map=1), from Elasticsearch's vector tile search.
//
//   tile.php?z=2&x=2&y=2             a Mapbox Vector Tile
//   tile.php?z=2&x=2&y=2&q=Gulella   only pages with these words
//
// The tile has one layer that matters, "aggs": the tile divided into a 256 x 256 grid
// (geotile_grid, eight zoom levels finer than the tile, the finest Elasticsearch
// allows), with a point for each cell that has any, at the middle of its points (not of
// the cell, which would line the points up in rows), and _count, how many pages have a
// point in it. A tile is always the same, so points do not move as the map pans, and
// every point is shown (individual pages, the "hits" layer, would stop at 10,000 a tile).

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/search.php');

// The deepest tile; maps zoom further by enlarging it
define('TILE_MAX_ZOOM', 14);

//----------------------------------------------------------------------------------------
function main()
{
	global $config;

	$z = isset($_GET['z']) ? (int)$_GET['z'] : -1;
	$x = isset($_GET['x']) ? (int)$_GET['x'] : -1;
	$y = isset($_GET['y']) ? (int)$_GET['y'] : -1;
	$q = isset($_GET['q']) ? trim($_GET['q']) : '';

	$n = 1 << max(0, $z);
	if ($z < 0 || $z > TILE_MAX_ZOOM || $x < 0 || $x >= $n || $y < 0 || $y >= $n)
	{
		http_response_code(400);
		header('Content-Type: text/plain');
		echo 'Give a tile as z, x and y, with z from 0 to ' . TILE_MAX_ZOOM;
		return;
	}

	$body = array(
		'size'             => 0,
		'grid_agg'         => 'geotile',
		'grid_precision'   => 8,
		'grid_type'        => 'centroid',
		'track_total_hits' => false,
		'query'            => text_query($q),
	);

	try
	{
		$elastic = new ElasticSearch($config['elastic_options']);
		list($code, $tile) = $elastic->send_raw('POST', '_mvt/locations/' . $z . '/' . $x . '/' . $y, json_encode($body));
	}
	catch (Exception $e)
	{
		$code = 502;
	}

	if ($code != 200)
	{
		http_response_code(502);
		header('Content-Type: text/plain');
		echo 'No tile (Elasticsearch returned HTTP ' . $code . ')';
		return;
	}

	header('Content-Type: application/vnd.mapbox-vector-tile');
	header('Access-Control-Allow-Origin: *');
	// The index changes only when it is reloaded
	header('Cache-Control: public, max-age=3600');
	echo $tile;
}

main();

?>
