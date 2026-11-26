# BHL page layout detection: plan for test/training data and method comparison

## Goal

For BHL page images, get **layout boxes** and a **label for each box** (at minimum: text vs figure). High-quality OCR is out of scope. Finer classification (caption, plate label, species name, key, etc.) comes afterwards, using the text inside the boxes.

Constraints: free, runs on a local machine. Paid services are only used to build the test dataset, not in production.

## Background

- ABBYY and Tesseract output from Internet Archive (`_abbyy.gz` has `blockType` Text/Picture/Table; `_hocr.html` has block and line boxes) is free and already exists for many BHL items, but in our experience they are often confused by figures. Include them as baselines, but don't trust them.
- Pages differ from modern documents: 19th-century plates, stippled engravings, fold-outs and maps, two-column taxonomic text, bleed-through from the facing page.

## Methods to compare

| Method | Notes | Licence |
|---|---|---|
| IA ABBYY XML (`_abbyy.gz`) | Parse existing blocks; no compute | n/a |
| IA hOCR (Tesseract) | Parse existing blocks; image regions flagged less consistently | n/a |
| Docling layout model (IBM) | DocLayNet classes (Picture, Table, Caption, Section-header, Page-header/footer, Footnote, List-item, Formula, Text, Title) | MIT |
| PP-DocLayout (PaddleOCR) | Several model sizes; small ones are fast on CPU | Apache 2.0 |
| DocLayout-YOLO | Fast YOLO-based detector | AGPL (fine for internal use) |
| Surya layout | Good; also gives reading order | Weights restricted for commercial use |
| Kraken (`blla`) | Built for historical documents; oriented to text regions/baselines | Apache 2.0 |
| OpenCV baseline | Binarise + morphology + connected-component statistics; figures differ from text in ink density/texture | n/a |

## Building the test set

1. **Sample pages, stratified by type**, not at random (random pages are mostly plain text). About 50 pages per type:
   - full-page plates
   - text pages with figures in the text
   - tables
   - two-column taxonomic text
   - fold-outs and maps
   - title pages
   - plain text (control)
   - spread across dates, from the 1800s to the mid-1900s
2. **Draft labels with a paid service** (Datalab and/or Mistral OCR).
   - Datalab returns typed layout blocks with bboxes.
   - Mistral OCR seems to give boxes mainly for extracted images, not for every text block. **Check on a few pages before committing.**
   - **Bias warning:** Datalab is built on Surya, so uncorrected Datalab labels will flatter Surya. Ideally label with both services and review the pages where they disagree (these are the hard cases).
3. **Correct by hand** in Label Studio (import draft boxes as pre-annotations). Aim for a few hundred hand-checked pages. Without correction, the labels are only a "silver" standard.
4. **Keep test and training pages separate.** Silver labels on other pages can be used to fine-tune a detector (e.g. a YOLO-style model) later; never on test pages.

## Shared label set

Map every method's labels onto:

- `text`
- `figure`
- `table`
- `caption`
- `header_footer` (optional)

Keep a mapping table per method (e.g. DocLayNet `Picture` -> `figure`, ABBYY `Picture` -> `figure`, DocLayNet `Section-header`/`Title`/`List-item`/`Footnote` -> `text`).

## Common output format (one JSON file per page per method)

```json
{
  "page_id": 12345678,
  "method": "docling",
  "image": { "width": 2480, "height": 3508 },
  "boxes": [
    {
      "label": "figure",
      "source_label": "Picture",
      "bbox": [x0, y0, x1, y1],
      "confidence": 0.93
    }
  ]
}
```

- `bbox` is in pixel coordinates of the page image, top-left origin.
- `source_label` keeps the method's original label for debugging.
- Ground truth uses the same format with `"method": "gold"` (hand-corrected) or `"silver_<service>"`.

Each method then needs only a converter to this format; scoring is one script (PHP is fine).

## Metrics

- **Page-level figure detection:** does the page contain a figure (yes/no)? Precision/recall. Catches the worst failures.
- **Box-level precision and recall per class**, matching predicted to gold boxes at IoU >= 0.5. Full COCO mAP is not needed.
- **Merge/split errors**, counted separately:
  - merge: one predicted box covers more than one gold box (e.g. plate + caption)
  - split: one gold box covered by several predicted boxes (fragmented figure)
- Report results per stratum (page type), not just overall.

## Pipeline once a method is chosen

1. Python script runs the layout model over page images and writes the JSON above.
2. Everything downstream is PHP: read JSON, crop regions.
3. Tesseract only on `text`/`caption` boxes if their content is needed.
4. Refine classification using the text (caption vs body, "Plate XII", "Fig. 3", species names, keys, ...).

Small YOLO/RT-DETR-style detectors run at roughly a fraction of a second to a couple of seconds per page on CPU; overnight batches of thousands of pages are fine.

## First tasks for the repo

1. Script to pull a stratified page sample from the BHL API (page IDs, image URLs, item metadata, whether IA ABBYY/hOCR files exist).
2. Converters for IA ABBYY and hOCR into the common JSON (free baselines, no compute).
3. Script to send the sample to Datalab/Mistral and convert their output to the common JSON (silver labels).
4. Export to Label Studio for hand correction; import corrected labels back as `gold`.
5. PHP scoring script implementing the metrics above.
