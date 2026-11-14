# bhl-light-words.db

Word coordinates for the pages in BHL-Light: where each OCR word sits on a BHL page image, for a viewer that highlights search hits in place on the page.

It was made by `export.php` in [bhl-ocr-store](https://github.com/rdmpage/bhl-ocr-store) (`~/Development/bhl-ocr-store`) on 2026-11-14, from the Internet Archive OCR in that store (DjVu XML, or hOCR where IA has no DjVu XML), for the 6,556 items in bhl-elastic's `testsets/bhl-light-items.txt`. It is a standalone, read-only SQLite file (9.0 GB). It is derived data, so keep it out of git.

## Contents

| Item status | Items | Pages |
|---|---|---|
| `ok` (DjVu XML) | 6,477 | 2,025,206 |
| `ok` (hOCR) | 6 | 1,532 |
| `page-count-mismatch` | 40 | none |
| `no-ocr` | 34 | none |

That is 2,026,738 of BHL-Light's 2,054,446 pages (98.7%). The `words` blobs total 7.8 GB, about 3.9 KB per page.

## Schema

```sql
CREATE TABLE item (
    ItemID    INTEGER PRIMARY KEY,  -- BHL ItemID
    BarCode   TEXT,     -- IA identifier, also the folder of BHL's page images on AWS
    format    TEXT,     -- 'djvu' or 'hocr': the OCR file the words came from
    ocr_pages INTEGER,  -- pages in the OCR
    bhl_pages INTEGER,  -- pages BHL lists for the item
    status    TEXT      -- 'ok', 'no-ocr' or 'page-count-mismatch'
);

CREATE TABLE page (
    PageID        INTEGER PRIMARY KEY,  -- BHL PageID
    ItemID        INTEGER,  -- BHL ItemID
    SequenceOrder INTEGER,  -- BHL's order within the item, 1, 2, 3...; the image's <seq>
    width         INTEGER,  -- page width in pixels
    height        INTEGER,  -- page height in pixels
    words         BLOB      -- gzipped JSON, see below
);
CREATE INDEX page_item ON page(ItemID, SequenceOrder);
```

Every item in the test set has a row in `item`. Only items whose status is `ok` have rows in `page`, and for those every BHL page of the item has a row. `PageID`, `ItemID` and `SequenceOrder` are BHL's, the same as in `bhl.db`'s `page` table, so the two databases join on `PageID`.

## The `words` blob

`words` is JSON compressed with gzip (PHP's `gzencode`, so `gzdecode` reads it):

```json
{
  "width": 1853,
  "height": 3075,
  "lines": [
    [[279, 391, 129, 43, "Carpels"], [425, 393, 31, 29, "5."], ...],
    ...
  ]
}
```

- `width` and `height` repeat the page's columns, so the blob can be used on its own.
- `lines` lists the lines of text in reading order as the OCR gives them. Each line is a list of words, and each word is `[x, y, w, h, text]`.
- `x, y` is the top-left corner of the word's box and `w, h` its width and height, in pixels, with the origin at the top left of the page.
- A page with no text (a blank page or a plate) has `"lines": []`.
- Words are as the OCR read them, punctuation included, so `"5."`, `"Labill."`, `"oblong-"`. A word hyphenated at the end of a line is two words on two lines.

Because the blob is already gzipped, a server can send it unchanged with `Content-Encoding: gzip` and the browser decompresses it.

## Lining boxes up with BHL's images

The coordinates are in the frame of the page as IA scanned it. For DjVu items that is exactly the pixel size of BHL's full-size page image on AWS. This was checked on several pages, including drawing a page's boxes onto the image. hOCR items (6) have not been checked.

BHL's images for a page are at:

```
https://bhl-open-data.s3.amazonaws.com/web/<BarCode>/<BarCode>_<seq>_<size>.webp
```

`<seq>` is `SequenceOrder` padded to four digits (`0100`). `<size>` is `full`, `large`, `medium`, `small` or `thumb`. At `full` the coordinates are used as they are. For any other size, or for an image drawn at any size on screen, multiply every coordinate by `displayed width / width`. The images have the page's shape, so the same factor works for heights.

## How OCR pages were matched to BHL pages

IA's OCR has one page for each leaf in the scan's access formats, in the same order as BHL's pages. So the nth OCR page was taken to be the BHL page with the nth `SequenceOrder`. That was only trusted when the item has the same number of pages in both, which was true for all but 40 items. Those 40 are marked `page-count-mismatch` and have no pages. 33 of them are hOCR, whose OCR has more pages than BHL, e.g. a placeholder first page. They could be matched later using the IA scandata, which is in bhl-ocr-store.

## Example queries

Page sizes for a whole item, without decompressing anything, e.g. to lay out a scrolling viewer before images load:

```sql
SELECT PageID, SequenceOrder, width, height
FROM page WHERE ItemID = ? ORDER BY SequenceOrder;
```

The barcode, for image URLs:

```sql
SELECT BarCode FROM item WHERE ItemID = ?;
```

One page's words, in PHP:

```php
$pdo = new PDO('sqlite:bhl-light-words.db', null, null,
	array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
$stmt = $pdo->prepare('SELECT words FROM page WHERE PageID = ?');
$stmt->execute(array($pageid));
$blob = $stmt->fetchColumn();

// Send as is...
header('Content-Type: application/json');
header('Content-Encoding: gzip');
echo $blob;

// ...or read it
$page = json_decode(gzdecode($blob));
```

## Matching search hits to words

The Elasticsearch index holds BHL's page text, not this OCR. For DjVu items BHL's text normally comes from the same OCR, but the two can differ in spacing, punctuation and line-end hyphens. To highlight a hit, compare normalised tokens: lower-case, and strip punctuation from both the query terms and the words. For phrases, walk the words in order across lines.
