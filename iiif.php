<?php

// IIIF Presentation 3 manifests (built in manifest.php)
//
//   iiif.php?item=262715     (an item: every page, with its articles as ranges)
//   iiif.php?part=431953     (an article: just its pages)
//
// Open one in the viewer with viewer.html?manifest=iiif.php%3Fitem%3D262715

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/manifest.php');

//----------------------------------------------------------------------------------------
function send_manifest($status, $obj)
{
	http_response_code($status);
	header('Content-Type: application/ld+json;profile="' . IIIF_CONTEXT . '"; charset=utf-8');
	header('Access-Control-Allow-Origin: *');
	echo json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

//----------------------------------------------------------------------------------------
function main()
{
	try
	{
		if (isset($_GET['item']))
		{
			$manifest = item_manifest((int)$_GET['item']);
			$manifest ? send_manifest(200, $manifest) : send_manifest(404, array('error' => 'No such item'));
		}
		elseif (isset($_GET['part']))
		{
			$manifest = part_manifest((int)$_GET['part']);
			$manifest ? send_manifest(200, $manifest) : send_manifest(404, array('error' => 'No such part'));
		}
		else
		{
			send_manifest(400, array('error' => 'Give an item or part, e.g. iiif.php?item=262715'));
		}
	}
	catch (NoManifest $e)
	{
		send_manifest(404, array('error' => $e->getMessage()));
	}
	catch (Exception $e)
	{
		send_manifest(502, array('error' => $e->getMessage()));
	}
}

main();

?>
