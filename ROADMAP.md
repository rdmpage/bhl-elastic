# Roadmap

Where things stand and what to think about next. Last updated 2026-11-12.

## Done so far

- `bhl.db` (SQLite) built from the BHL data dump (`import.php`, then `indexes-bhl.sql`).
- One Elasticsearch index (`bhl`, mapping in `index.json`) holding page, part, item and title documents, told apart by `type`.
- `load-ocr.php` loads page documents from the OCR archive; `load-metadata.php` loads part, item and title documents from `bhl.db`.
- Test set: BHL-Light (`testsets/bhl-light-items.txt`): 2,054,446 pages, 72,995 parts, 6,556 items, 550 titles.
- Text search (`search.php`): every word for queries of up to three words, 75% for longer ones; phrase matches (slop 3) rank first. Results are collapsed into articles, or volumes for pages not in an article, each with its three best pages.
- Results are JSON-LD (a schema.org `ItemList`): `api.php` returns them and `index.php` renders them.
- Test searches in `testsets/queries.txt`, run with `php test-queries.php`.

## Next, in rough order

1. **Catalogue search.** Search the metadata rather than the OCR: `name` (weighted highest), `authors`, `container` and `fullname` on part and title documents. Return the same `ItemList`, without `hasPart` pages. The data is already loaded.
2. **Journal article lists, as in BioStor.** All parts of a title, filtered by year, sorted by year, volume and issue. Needs a numeric `volume_sort` field, because `volume` is a keyword and sorts "10" before "9". Use a `date_histogram` of articles per year for year navigation.
3. **Document viewer.** Clicking an article or page runs the same query on just that document, with matching pages shown as ticks down the scrollbar (like Google Books). `hasPart[].position` gives the page sequence.

## Later; these depend on decisions about data

- **People.** Store BHL `CreatorID`s next to author names so there can be "all parts by this creator" pages. Disambiguating people is graph work, and probably belongs in the RDF version of BHL.
- **Geospatial search.** `locations` (`geo_point`) is already in the mapping. The question is where the points come from (see the experiments in bhl-elastic-test). Once pages have points, map search is a geo filter on the same query, and the JSON-LD could carry `contentLocation`.
- **Link scores.** `links` (`rank_feature`) is in the mapping. Fill it with counts of external links to each page; a link to a two-page spread scores 0.5 per page. Boost with `rank_feature` + `saturation`.
- **Virtual items.** About 457,000 pages of articles that BHL assembled into virtual items: OCR in the archive's `part/` tree, pages only in `partpage`. None are in BHL-Light.
- **Incremental updates.** New and changed BHL content via RSS or OAI-PMH, and an update API secured with a custom HTTP header.
- **Full load.** All of BHL is about 64M pages, roughly 170 GB of index at the BHL-Light rate.

## Probably not

- **Facets** in the shopping-site sense. Two aggregations are worth having as context for a text search: occurrences over time, and which journals.

## Elasticsearch or RDF

Use Elasticsearch for anything ranked, full-text or a fast filtered list. Use RDF for relationships: people, identifiers, citations, "same as". The JSON-LD uses BHL URIs as `@id`, so results from both can refer to the same things.

## Things to know

- The index has `dynamic: strict`, so add new fields to `index.json` and to the live index (`PUT bhl/_mapping`) before loading them.
- Bulk loads: set `refresh_interval` to `-1` first, then put it back to `30s` and force-merge to one segment.
- Metadata never goes in the `text` field, so it doesn't affect how page text is scored.
- Run the dev server as `php -S localhost:4000`, without a router script, so `api.php` is reachable.
