<?php

// Helpers for BHL metadata in bhl.db that more than one kind of document needs.

require_once (dirname(__FILE__) . '/config.inc.php');

//----------------------------------------------------------------------------------------
// A virtual item (barcode vi<TitleID>v..., e.g. vi165999v98202220240512010215) is
// assembled from articles and has no scan of its own; its pages are listed only in
// partpage and its images live under each part's barcode. A plain "vi" prefix is not
// enough: vitamines00funk is an ordinary scan.
function is_virtual_barcode($barcode)
{
	return (bool)preg_match('/^vi\d+v/', $barcode);
}

//----------------------------------------------------------------------------------------
// Thumbnail URL for page $seq of the scan with this barcode.
function page_thumbnail($barcode, $seq)
{
	global $config;

	$name = $barcode . '_' . str_pad($seq, 4, '0', STR_PAD_LEFT) . '_thumb.webp';

	return $config['bhl_images'] . '/web/' . $barcode . '/' . $name;
}

//----------------------------------------------------------------------------------------
// Thumbnail URL for an item: the thumbnail of its ThumbnailPageID, or null if that is
// not set (53 virtual items) or names a page bhl.db does not have (38 items).
//
// For a normal item the page is in the item's own scan. For a virtual item it is a page
// of one of its parts, so the image is named by the part's barcode and the page's
// position in that part.
function item_thumbnail($pdo, $itemid)
{
	static $stmts = null;

	if ($stmts === null)
	{
		$stmts = array(
			'item'     => $pdo->prepare('SELECT BarCode, ThumbnailPageID FROM item WHERE ItemID = ? LIMIT 1'),
			'page'     => $pdo->prepare('SELECT SequenceOrder FROM page WHERE PageID = ? AND ItemID = ? LIMIT 1'),
			'partpage' => $pdo->prepare('SELECT part.BarCode, pp.SequenceOrder FROM partpage pp JOIN part USING (PartID) WHERE pp.PageID = ? AND pp.ItemID = ? AND part.BarCode IS NOT NULL LIMIT 1'),
		);
	}

	$stmts['item']->execute(array($itemid));
	$item = $stmts['item']->fetch(PDO::FETCH_ASSOC);
	$stmts['item']->closeCursor();

	if (!$item || $item['ThumbnailPageID'] === null)
	{
		return null;
	}

	if (is_virtual_barcode($item['BarCode']))
	{
		$stmts['partpage']->execute(array($item['ThumbnailPageID'], $itemid));
		$row = $stmts['partpage']->fetch(PDO::FETCH_ASSOC);
		$stmts['partpage']->closeCursor();

		return $row ? page_thumbnail($row['BarCode'], $row['SequenceOrder']) : null;
	}

	$stmts['page']->execute(array($item['ThumbnailPageID'], $itemid));
	$row = $stmts['page']->fetch(PDO::FETCH_ASSOC);
	$stmts['page']->closeCursor();

	return $row ? page_thumbnail($item['BarCode'], $row['SequenceOrder']) : null;
}

?>
