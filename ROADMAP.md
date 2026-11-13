# Roadmap

Where things stand and what to think about next. Last updated 2026-11-13.

## Done so far

- `bhl.db` (SQLite) built from the BHL data dump (`import.php`, then `indexes-bhl.sql`).
- One Elasticsearch index (`bhl`, mapping in `index.json`) holding page, part, item and title documents, told apart by `type`.
- `load-ocr.php` loads page documents from the OCR archive; `load-metadata.php` loads part, item and title documents from `bhl.db`.
- Test set: BHL-Light (`testsets/bhl-light-items.txt`): 2,054,446 pages, 72,995 parts, 6,556 items, 550 titles.
- Text search (`search.php`): every word for queries of up to three words, 75% for longer ones; phrase matches (slop 3) rank first. Results are collapsed into articles, or volumes for pages not in an article, each with its three best pages.
- Results are JSON-LD (a schema.org `ItemList`): `api.php` returns them and `index.php` renders them.
- Article search (`search_articles()`): metadata only, matched against a `citation` string on each part (authors, year, title, journal, volume, pages) with the same 75% rule, so pasted citations match despite small differences.
- Kinds of search are tabs below the search bar (All, Articles), and `type=` in the API; they are listed in `search_types()` in `search.php`.
- Test searches in `testsets/queries.txt`, run with `php test-queries.php`; lines starting `articles:` are article searches.

## Next, in rough order

1. **Reconciliation API**, on top of article search, as in BioStor (~/Development/biostor-lite `api_reconciliation.php`, `reconciliation_api.php`, `lcs.php`). Run the citation as an article search, then accept a top-three hit only if the strings match closely by longest common subsequence (one almost a substring of the other, above 0.9, and covering over half the longer, above 0.5). The same check answers "is this article in BHL?".
2. **Titles search, for BHL staff and librarians.** Title metadata (name, fullname, authors, subjects, publisher), with counts down the side by subject, language, contributing library and date (`terms` aggregations). Title documents need more fields: subjects (`subject` table), language (`title.LanguageCode`), contributing libraries (`item.InstitutionName`, gathered over the title's items). Open question: how the counts appear in the JSON-LD, since schema.org has no term for a facet; perhaps alongside the `ItemList` rather than in it.
3. **Journal article lists, as in BioStor.** All parts of a title, filtered by year, sorted by year, volume and issue. Needs a numeric `volume_sort` field, because `volume` is a keyword and sorts "10" before "9". Use a `date_histogram` of articles per year for year navigation.
   - BioStor's version is in ~/Development/biostor-lite: `do_search()`, `do_issn()`, `do_issn_year()` and `page_sort_key()` in `index.php`, and `display_decade_list()` in `interface-shared.php`. It handles `issn:`, `oclc:` and `isbn:` prefixes in the search box, fetches up to 5,000 hits, sorts them in PHP on padded year-volume-issue-page keys (roman-numbered pages first), and shows a journal as collapsible decades of thumbnails. Journal descriptions come from `about/<ISSN>.json`.
   - Here: compute `volume_sort` and `spage_sort` (using the `page_sort_key()` logic) at load time and sort in Elasticsearch, so paging works for any size of journal. In BHL the ISSN and OCLC number belong to the title (`titleidentifier`), so add them to title documents as keywords; `issn:X` then finds the title and lists its parts by `titleid`. The journal page's JSON-LD comes from the title document.
4. **Document viewer.** Clicking an article or page runs the same query on just that document, with matching pages shown as ticks down the scrollbar (like Google Books). It can return the same nested `ItemList` of pages, listing every match; each page's own `position` is its sequence in the volume.

## Later; these depend on decisions about data

- **People** (a tab). Store BHL `CreatorID`s next to author names so there can be "all parts by this creator" pages. Disambiguating people is graph work, and probably belongs in the RDF version of BHL.
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
