<?php

// IIIF Presentation 3 manifests (built in manifest.php)
//
//   iiif.php?item=262715     (an item: every page, with its articles as ranges)
//   iiif.php?part=431953     (an article: just its pages)
//   iiif.php?page=57579635   (a page's text, line by line, as an AnnotationPage)
//   iiif.php?page=57579637&annotations=geo   (the latitude and longitude pairs in a
//                            page's text, as an AnnotationPage)
//
// text=word gives text word by word (in a manifest, its canvases link to their pages'
// words), text=page each page's text as indexed, without boxes (what a page with no
// OCR words gets anyway), and text=none leaves text out of a manifest.
//
// Open one in the viewer with viewer.html?manifest=iiif.php%3Fitem%3D262715

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/manifest.php');

//----------------------------------------------------------------------------------------
function send_manifest($status, $obj)
{
	// Pages of words are large but repetitive, so compress if the client allows it
	ob_start('ob_gzhandler');

	http_response_code($status);
	header('Content-Type: application/ld+json;profile="' . IIIF_CONTEXT . '"; charset=utf-8');
	header('Access-Control-Allow-Origin: *');
	echo json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

//----------------------------------------------------------------------------------------
function main()
{
	$text = isset($_GET['text']) && in_array($_GET['text'], array('word', 'page', 'none')) ? $_GET['text'] : 'line';

	try
	{
		if (isset($_GET['page']) && isset($_GET['annotations']) && $_GET['annotations'] == 'geo')
		{
			$page = geo_annotations((int)$_GET['page']);
			$page ? send_manifest(200, $page) : send_manifest(404, array('error' => 'No coordinates on page'));
		}
		elseif (isset($_GET['page']))
		{
			$page = page_annotations((int)$_GET['page'], $text == 'none' ? 'line' : $text);
			$page ? send_manifest(200, $page) : send_manifest(404, array('error' => 'No text for page'));
		}
		elseif (isset($_GET['item']))
		{
			$manifest = item_manifest((int)$_GET['item'], $text);
			$manifest ? send_manifest(200, $manifest) : send_manifest(404, array('error' => 'No such item'));
		}
		elseif (isset($_GET['part']))
		{
			$manifest = part_manifest((int)$_GET['part'], $text);
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
