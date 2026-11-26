# Layout sample

Page images for training, validating and testing page layout detection (see `bhl-layout-detection-plan.md`), made by `layout-sample.php` (seed 1, 150 pages a kind) on 2026-11-23.

1,507 pages from 1,183 items: train 910, validation 273, test 324. Each item is in only one split.

`pages.tsv` lists them, one page a line:

| Column | |
|---|---|
| `page_id`, `item_id` | BHL's PageID and ItemID |
| `split` | `train`, `validation` or `test`, by a hash of the ItemID |
| `stratum` | the kind of page it was chosen as (below) |
| `era` | `pre1850`, `1850-1899`, `1900-1949` or `1950on` |
| `year` | the item's year |
| `bhl_types` | BHL's page types, which are often wrong (a blank page typed Chart, a text page with a figure typed Text) |
| `page_label` | BHL's page label, e.g. "Page 301" |
| `barcode`, `seq` | the Internet Archive identifier and the page's sequence number in the item |
| `ocr_width`, `ocr_height` | the page size in the OCR, the full resolution of the scan |
| `image_url` | BHL's JPEG on AWS |
| `image` | the local copy, `images/<split>/<page_id>.jpg` |
| `bhl_url` | the page at BHL |

Images are BHL's JPEGs from its open data (`https://bhl-open-data.s3.amazonaws.com/jpg/...`), about 1,000 pixels wide, half the scan's resolution; scale boxes by `ocr_width` / image width to compare them with the OCR. They are not in git (1.3 GB); `php layout-sample.php --images` downloads them again.

| Stratum | Pages | What it is |
|---|---|---|
| `foldout` | 150 | BHL type Foldout |
| `map` | 150 | Map |
| `title` | 150 | Title Page or Cover |
| `chart` | 150 | Chart: tables and graphs |
| `list` | 150 | Index, Table of Contents, List of Illustrations |
| `plate` | 150 | Illustration without Text |
| `text_figure` | 150 | Illustration and Text: figures in text, or plates with captions |
| `rotated` | 67 | Text only, but printed sideways (words taller than wide in the OCR): mostly turned tables |
| `two_column` | 90 | Text only, in columns (from where the OCR's lines fall): mostly lists, indexes, references and gazetteers, some narrow tables |
| `text` | 150 | Text only, one column: the control |
| `blank` | 150 | Blank, often with show-through |

Within a kind, pages are spread over the four periods where there are enough (maps before 1850, and the two derived kinds, are short), with no more than one page of a kind from an item. `rotated` and `two_column` are found by looking at the OCR of 5,000 random text pages a period, so a larger sample of them needs a larger search, not more pages in BHL.
