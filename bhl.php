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
// Numeric part of a prefixed document id: "part_349927" -> 349927
function id_number($id)
{
	return (int)preg_replace('/^[a-z]+_/', '', $id);
}

//----------------------------------------------------------------------------------------
// First plausible four-digit year in a BHL date string ("1900", "1900-1902", "c1875").
function first_year($value)
{
	if ($value !== null && preg_match('/(1[5-9]\d\d|20\d\d)/', $value, $m))
	{
		return (int)$m[1];
	}
	return null;
}

//----------------------------------------------------------------------------------------
// Last year in a string such as "1898-1901", or null.
function last_year($value)
{
	if ($value !== null && preg_match_all('/(1[5-9]\d\d|20\d\d)/', $value, $m))
	{
		return (int)max($m[1]);
	}
	return null;
}

//----------------------------------------------------------------------------------------
// BHL's language codes (MARC, e.g. "ENG") as two-letter codes (BCP 47, as schema.org's
// inLanguage expects), with names to display. Codes not listed are kept in lower case;
// "und" (undetermined) and "mul" (multiple) are left out.
function bhl_languages()
{
	return array(
		'ENG' => array('en', 'English'),
		'FRE' => array('fr', 'French'),
		'GER' => array('de', 'German'),
		'LAT' => array('la', 'Latin'),
		'CHI' => array('zh', 'Chinese'),
		'SPA' => array('es', 'Spanish'),
		'ITA' => array('it', 'Italian'),
		'DUT' => array('nl', 'Dutch'),
		'POR' => array('pt', 'Portuguese'),
		'SWE' => array('sv', 'Swedish'),
		'JPN' => array('ja', 'Japanese'),
		'RUS' => array('ru', 'Russian'),
		'DAN' => array('da', 'Danish'),
		'HUN' => array('hu', 'Hungarian'),
		'NOR' => array('no', 'Norwegian'),
		'CZE' => array('cs', 'Czech'),
		'ARA' => array('ar', 'Arabic'),
		'POL' => array('pl', 'Polish'),
		'RUM' => array('ro', 'Romanian'),
		'CAT' => array('ca', 'Catalan'),
		'UKR' => array('uk', 'Ukrainian'),
	);
}

//----------------------------------------------------------------------------------------
function language_code($marc)
{
	$marc = strtoupper(trim((string)$marc));
	$languages = bhl_languages();

	if (isset($languages[$marc]))
	{
		return $languages[$marc][0];
	}
	if ($marc == '' || $marc == 'UND' || $marc == 'MUL')
	{
		return null;
	}
	return strtolower($marc);
}

//----------------------------------------------------------------------------------------
function language_name($code)
{
	foreach (bhl_languages() as $language)
	{
		if ($language[0] == $code)
		{
			return $language[1];
		}
	}
	return $code;
}

//----------------------------------------------------------------------------------------
// An item's copyright status (item.CopyrightStatus, nearly 300 different wordings) as
// one of a few categories. "Not in copyright" is tested before "in copyright".
function copyright_status($status)
{
	$status = trim((string)$status);

	if ($status == '' || preg_match('/^not provided/i', $status))
	{
		return 'Not provided';
	}
	if (preg_match('/no known copyright|unaware of any|knows of no copyright/i', $status))
	{
		return 'No known copyright restrictions';
	}
	if (preg_match('/not[ _-]*in[ _-]*copyright|^NIC$|public[ _-]*domain|not claimed or not renewed/i', $status))
	{
		return 'Public domain';
	}
	if (preg_match('/in[ _-]*copyright|permission|copyright|&copy;|©/i', $status))
	{
		return 'In copyright';
	}
	return 'Other';
}

//----------------------------------------------------------------------------------------
// An item's licence (item.LicenseType) as a Creative Commons URL in one form,
// e.g. "https://creativecommons.org/licenses/by-nc-sa/4.0/", or null if it is not one.
function normalise_license($license)
{
	$license = strtolower(trim(preg_replace('/[^\x21-\x7e]/', '', (string)$license)));

	if (preg_match('/creativecommons.org\/share-your-work\/public-domain\/cc0/', $license))
	{
		return 'https://creativecommons.org/publicdomain/zero/1.0/';
	}
	if (preg_match('/creativecommons.org\/(licenses|publicdomain)\/([a-z-]+)\/(\d\.\d)/', $license, $m))
	{
		return 'https://creativecommons.org/' . $m[1] . '/' . $m[2] . '/' . $m[3] . '/';
	}
	return null;
}

//----------------------------------------------------------------------------------------
// "CC BY-NC-SA 4.0" for a Creative Commons URL.
function license_label($url)
{
	if (preg_match('/\/licenses\/([a-z-]+)\/(\d\.\d)\//', $url, $m))
	{
		return 'CC ' . strtoupper($m[1]) . ' ' . $m[2];
	}
	if (preg_match('/\/publicdomain\/zero\//', $url))
	{
		return 'CC0';
	}
	if (preg_match('/\/publicdomain\/mark\//', $url))
	{
		return 'Public Domain Mark';
	}
	return $url;
}

//----------------------------------------------------------------------------------------
// The title identifiers worth keeping, from BHL's name to ours (lower case, used as a
// prefix, e.g. "oclc:1110975"). BHL's internal ones (MARC001, WonderFetch, OAI) are left
// out, as is Abbreviation, which is a name rather than an identifier.
function title_identifier_names()
{
	return array(
		'OCLC'         => 'oclc',
		'Wikidata'     => 'wikidata',
		'ISSN'         => 'issn',
		'eISSN'        => 'eissn',
		'Linking ISSN' => 'issn-l',
		'ISBN'         => 'isbn',
		'DLC'          => 'dlc',
		'NAL'          => 'nal',
		'NLM'          => 'nlm',
		'DDC'          => 'ddc',
		'GPO'          => 'gpo',
		'CODEN'        => 'coden',
		'TL2'          => 'tl2',
		'Soulsby'      => 'soulsby',
		'BPH'          => 'bph',
	);
}

//----------------------------------------------------------------------------------------
// Thumbnail URL for page $pageid of item $itemid, or null if bhl.db does not have that
// page in that item.
//
// For a normal item the page is in the item's own scan. For a virtual item it is a page
// of one of its parts, so the image is named by the part's barcode and the page's
// position in that part.
function item_page_thumbnail($pdo, $itemid, $pageid)
{
	static $stmts = null;

	if ($stmts === null)
	{
		$stmts = array(
			'item'     => $pdo->prepare('SELECT BarCode FROM item WHERE ItemID = ? LIMIT 1'),
			'page'     => $pdo->prepare('SELECT SequenceOrder FROM page WHERE PageID = ? AND ItemID = ? LIMIT 1'),
			'partpage' => $pdo->prepare('SELECT part.BarCode, pp.SequenceOrder FROM partpage pp JOIN part USING (PartID) WHERE pp.PageID = ? AND pp.ItemID = ? AND part.BarCode IS NOT NULL LIMIT 1'),
		);
	}

	if ($pageid === null)
	{
		return null;
	}

	$stmts['item']->execute(array($itemid));
	$item = $stmts['item']->fetch(PDO::FETCH_ASSOC);
	$stmts['item']->closeCursor();

	if (!$item)
	{
		return null;
	}

	if (is_virtual_barcode($item['BarCode']))
	{
		$stmts['partpage']->execute(array($pageid, $itemid));
		$row = $stmts['partpage']->fetch(PDO::FETCH_ASSOC);
		$stmts['partpage']->closeCursor();

		return $row ? page_thumbnail($row['BarCode'], $row['SequenceOrder']) : null;
	}

	$stmts['page']->execute(array($pageid, $itemid));
	$row = $stmts['page']->fetch(PDO::FETCH_ASSOC);
	$stmts['page']->closeCursor();

	return $row ? page_thumbnail($item['BarCode'], $row['SequenceOrder']) : null;
}

//----------------------------------------------------------------------------------------
// Thumbnail URL for an item: the thumbnail of its ThumbnailPageID, or null if that is
// not set (53 virtual items) or names a page bhl.db does not have (38 items).
function item_thumbnail($pdo, $itemid)
{
	static $stmt = null;

	if ($stmt === null)
	{
		$stmt = $pdo->prepare('SELECT ThumbnailPageID FROM item WHERE ItemID = ? LIMIT 1');
	}

	$stmt->execute(array($itemid));
	$pageid = $stmt->fetchColumn();
	$stmt->closeCursor();

	return $pageid ? item_page_thumbnail($pdo, $itemid, $pageid) : null;
}

?>
