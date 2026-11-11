<?php

// Reading the BHL OCR export (bhl-ocr-YYYYMMDD.tar.bz2) as a stream.
//
// PHP has no tar reader that works on a pipe (PharData wants a seekable file), and the
// archive is 45 GB of bzip2, so this reads tar headers directly from the decompressor's
// output. Nothing is extracted to disk.

//----------------------------------------------------------------------------------------
// Read exactly $length bytes. fread() on a pipe returns whatever is available, which is
// often less than asked for.
function read_exactly($handle, $length)
{
	$data = '';

	while (strlen($data) < $length)
	{
		$chunk = fread($handle, $length - strlen($data));

		if ($chunk === false || $chunk === '')
		{
			if (feof($handle))
			{
				break;
			}
			continue;
		}

		$data .= $chunk;
	}

	return $data;
}

//----------------------------------------------------------------------------------------
// Generator over the files in a tar stream: yields array(path, size, contents, header),
// or array(path, size, null, header) when $want returns false for the path, so data that
// is not needed (e.g. on resume) is skipped without building strings. The raw 512-byte
// header lets a caller copy an entry into another archive unchanged.
//
// Handles ustar, GNU long names ('L') and pax headers ('x'), although the BHL archive's
// paths are short enough not to need either.
function tar_entries($handle, $want = null)
{
	$long_name = null;

	while (true)
	{
		$header = read_exactly($handle, 512);

		if (strlen($header) < 512)
		{
			return; // truncated stream; the caller sees no more entries
		}

		// Two zero blocks end the archive; one is enough to stop.
		if (trim($header, "\0") === '')
		{
			return;
		}

		$name   = rtrim(substr($header, 0, 100), "\0");
		$size   = octdec(trim(substr($header, 124, 12), " \0"));
		$type   = substr($header, 156, 1);
		$prefix = rtrim(substr($header, 345, 155), "\0");

		if (substr($header, 257, 5) == 'ustar' && $prefix != '')
		{
			$name = $prefix . '/' . $name;
		}

		if ($long_name !== null)
		{
			$name      = $long_name;
			$long_name = null;
		}

		$padded = (int)(ceil($size / 512) * 512);

		// Metadata entries describe the next entry rather than being files.
		if ($type == 'L' || $type == 'x')
		{
			$data = substr(read_exactly($handle, $padded), 0, $size);

			if ($type == 'L')
			{
				$long_name = rtrim($data, "\0");
			}
			elseif (preg_match('/^\d+ path=(.*)$/m', $data, $m))
			{
				$long_name = $m[1];
			}
			continue;
		}

		$is_file = ($type == '0' || $type == "\0" || $type == '');

		if ($is_file && ($want === null || $want($name)))
		{
			$data = substr(read_exactly($handle, $padded), 0, $size);
			yield array($name, $size, $data, $header);
		}
		else
		{
			// Skip in chunks rather than holding a large unwanted entry in memory.
			$remaining = $padded;
			while ($remaining > 0)
			{
				$skipped = strlen(read_exactly($handle, min($remaining, 1 << 20)));
				if ($skipped == 0)
				{
					return;
				}
				$remaining -= $skipped;
			}

			if ($is_file)
			{
				yield array($name, $size, null, $header);
			}
		}
	}
}

//----------------------------------------------------------------------------------------
// Open an OCR archive for streaming, by extension: .tar.bz2 (the BHL export), .tar.zst
// (a local working set) or plain .tar. 7-Zip decompresses bzip2 on several cores, bzip2
// on one, so 7zz is used if it is installed.
function ocr_open($path)
{
	if (!file_exists($path))
	{
		throw new Exception('No such archive: ' . $path);
	}

	if (preg_match('/\.bz2$/', $path))
	{
		$command = trim(shell_exec('command -v 7zz'))
			? '7zz e -so -tbzip2 ' . escapeshellarg($path) . ' 2>/dev/null'
			: 'bzip2 -dc ' . escapeshellarg($path);
	}
	elseif (preg_match('/\.zst$/', $path))
	{
		$command = 'zstd -dc ' . escapeshellarg($path);
	}
	else
	{
		return fopen($path, 'rb');
	}

	fwrite(STDERR, "# reading via: $command\n");
	return popen($command, 'r');
}

//----------------------------------------------------------------------------------------
// ItemIDs from a file, one per line (anything after the first column is ignored), as a
// set: array(ItemID => true). Blank lines and lines starting with # are skipped.
function read_item_list($path)
{
	$items = array();

	foreach (file($path, FILE_IGNORE_NEW_LINES) as $line)
	{
		if (preg_match('/^\s*(\d+)/', $line, $m))
		{
			$items[(int)$m[1]] = true;
		}
	}

	return $items;
}

//----------------------------------------------------------------------------------------
// bhl-ocr-20260514/item/item-00/item-000007/item-000007-00600321-0008.txt
//   -> array('item', 7, 600321, 8)
//
// Kind and id come from the directory, PageID and sequence from the end of the filename,
// because the data dictionary shows filenames both with and without the "item-" prefix.
function ocr_parse_path($path)
{
	if (preg_match('/\/(item|part)-(\d+)\/[^\/]*?(\d+)-(\d+)\.txt$/', $path, $m))
	{
		return array($m[1], (int)$m[2], (int)$m[3], (int)$m[4]);
	}
	return null;
}

//----------------------------------------------------------------------------------------
// Clean BHL OCR for indexing.
//
// The text can carry transcription mark-up (see the data dictionary). [[strike]] wraps
// text the transcriber deleted, so it must not be searchable. Other [[tag]]s are removed
// and their contents kept, otherwise "footnote", "unclear" and so on would become terms
// on every marked-up page. [text addition] brackets often hold the accepted species
// name, so the brackets go and the text stays.
function ocr_clean($text)
{
	$text = str_replace("\r\n", "\n", $text);

	$text = preg_replace('/\[\[strike\]\].*?\[\[\/?strike\]\]/is', ' ', $text);

	$text = preg_replace('/\[\[\/?[a-z]+\]\]/i', ' ', $text);
	$text = preg_replace('/^\s*\|[\s\-|:]+\|\s*$/m', ' ', $text); // table header rules
	$text = preg_replace('/\[([^\[\]]{1,80})\]/', '$1', $text);
	$text = preg_replace('/[ \t]+/', ' ', $text);
	$text = preg_replace('/ ?\n ?/', "\n", $text);

	return trim($text);
}

?>
