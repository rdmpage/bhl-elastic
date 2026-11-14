# Roadmap

Where things stand and what to think about next. Last updated 2026-11-14.

## Done so far

- `bhl.db` (SQLite) built from the BHL data dump (`import.php`, then `indexes-bhl.sql`).
- One Elasticsearch index (`bhl`, mapping in `index.json`) holding page, part, item and title documents, told apart by `type`.
- `load-ocr.php` loads page documents from the OCR archive; `load-metadata.php` loads part, item and title documents from `bhl.db`.
- Test set: BHL-Light (`testsets/bhl-light-items.txt`): 2,054,446 pages, 72,995 parts, 6,556 items, 550 titles.
- Text search (`search.php`): every word for queries of up to three words, 75% for longer ones; phrase matches (slop 3) rank first. Results are collapsed into articles, or volumes for pages not in an article, each with its three best pages.
- Results are JSON-LD (a schema.org `ItemList`): `api.php` returns them and `index.php` renders them.
- Article search (`search_articles()`): metadata only, matched against a `citation` string on each part (authors, year, title, journal, volume, pages) with the same 75% rule, so pasted citations match despite small differences.
- Kinds of search are tabs below the search bar (All, Articles), and `type=` in the API (also `titles`, which has the title browser instead of a tab); they are listed in `search_types()` in `search.php`.
- Reconciliation (`reconcile.php`), in the form OpenRefine uses, as in BioStor: each citation is run as an article search with a looser rule (50% of words), and a top-three hit matches only if the strings agree by longest common subsequence (`compare.php`): one almost a subsequence of the other (over 0.9) and covering over half the longer (over 0.5). Also compares without the authors, for citations that leave them out. `match.php` is a page for pasting citations, like BioStor's `match.html`.
- Title pages (`index.php?title=9243`, `api.php?title=9243`), like OpenAlex's page for a source: summary, contributing libraries with counts, and the items. JSON-LD from `get_title()` in `records.php`: a `Book` or `Periodical` (from the catalogue record), resolvable identifiers (DOI, OCLC, Wikidata) in `sameAs`, ISSN/ISBN in `issn`/`isbn`, others as `PropertyValue`s in `identifier`, subjects in `keywords`, and items in `hasPart` as `PublicationVolume`s whose `provider` is the contributing library, `copyrightNotice` the copyright status, `license` the Creative Commons licence and `copyrightHolder` the rights holder; the page counts libraries, copyright, licences and rights holders over the items. BHL's copyright statements (nearly 300 wordings) are reduced to five categories by `copyright_status()` in `bhl.php` (public domain, no known restrictions, in copyright, not provided, other), and licences to one form of Creative Commons URL by `normalise_license()`. Title documents also carry the set of copyright categories and licences of their items, ready for counts in titles search. If a standard vocabulary is wanted for copyright, rightsstatements.org URIs (InC, NoC, NKC, CNE) map onto the categories. Only serials get `startDate`/`endDate`. Title names in search results link to title pages.
- Title browser (Titles in the navigation bar, `index.php?type=titles`): all titles in order of name (ignoring case and accents, via a `name.sort` keyword with a normaliser), 48 at a time with a "Load more" tile, and down the side a text filter and counts by decade, kind, language, contributing library and subject (`search_titles()` with `terms` aggregations, over all matching titles). Facet values are links, so views can be bookmarked; the text filter updates the list as you type (the last word matches as a prefix). Title documents have `decades` (every decade a title's items span, so the 1890s finds titles running through it) and `item_count` (not used yet; ready for sorting by size). JSON-LD: the list, each facet (`identifier` is its URL parameter) and each value's subset (`name`, `numberOfItems`, `url`) are `ItemList`s and also `Collection`s, as schema.org only allows `hasPart` on CreativeWorks; facets hang off the list by `hasPart`.
- Titles search (`type=titles`, used by the title browser's text filter): name, full title, authors and subjects searched together (`cross_fields`), with the same 75% rule, and phrase matches in the name first; `issn:`, `oclc:`, `wikidata:` etc. find titles by identifier. Results link to title pages. A book's JSON-LD has `datePublished` (its first year); a serial's has `startDate`/`endDate`.
- Item pages (`index.php?item=19398`, `api.php?item=19398`) show how an item is divided into articles, as in bhl-article-coverage: every page in scan order, coloured by its article (Vega's tableau10 colours in turn), with pages in no article hatched and illustrations outlined, so gaps and unassigned plates stand out; a strip gives the whole item at a glance, and hovering a page or article highlights all of that article's pages. JSON-LD from `get_item()` in `records.php`: the `PublicationVolume` has its articles in `hasPart` (ordered by first page) and is also an `ItemList` of its pages, each a `CreativeWork` with its page types as `genre` and the articles it is in as `isPartOf`. Items on title pages and in search results link here.
- JSON-LD checks (`php test-jsonld.php`, after `composer install`): one of each kind of API output is converted to RDF with `ml/json-ld` (errors if it is not valid JSON-LD or has relative IRIs), then checked against schema.org's vocabulary (`testsets/schemaorg-current-http.jsonld`): unknown classes and properties are errors; superseded terms, and properties outside their `domainIncludes`/`rangeIncludes`, are warnings. SHACL is left for the RDF version; its shapes could later be run on this output too.
- Test searches in `testsets/queries.txt`, run with `php test-queries.php`; lines starting `articles:` are article searches.

## Next, in rough order

1. **Titles browser follow-ups.** Sort by number of items (`item_count`) or by date; BHL's subjects are messy subject headings with a long tail, so only the top 15 show.
   - Authors are untyped in title JSON-LD, because the index keeps only names; BHL's `creator.CreatorType` says person, organisation or meeting. Store it (with `CreatorID`) when we get to People.
   - BHL's `Abbreviation` identifiers (journal abbreviations) could go on titles as alternate names, to help reconcile abbreviated citations.
2. **Journal article lists, as in BioStor.** All parts of a title, filtered by year, sorted by year, volume and issue. Needs a numeric `volume_sort` field, because `volume` is a keyword and sorts "10" before "9". Use a `date_histogram` of articles per year for year navigation.
   - BioStor's version is in ~/Development/biostor-lite: `do_search()`, `do_issn()`, `do_issn_year()` and `page_sort_key()` in `index.php`, and `display_decade_list()` in `interface-shared.php`. It handles `issn:`, `oclc:` and `isbn:` prefixes in the search box, fetches up to 5,000 hits, sorts them in PHP on padded year-volume-issue-page keys (roman-numbered pages first), and shows a journal as collapsible decades of thumbnails. Journal descriptions come from `about/<ISSN>.json`.
   - Here: compute `volume_sort` and `spage_sort` (using the `page_sort_key()` logic) at load time and sort in Elasticsearch, so paging works for any size of journal. In BHL the ISSN and OCLC number belong to the title (`titleidentifier`), so add them to title documents as keywords; `issn:X` then finds the title and lists its parts by `titleid`. The journal page's JSON-LD comes from the title document.
3. **Item viewer.** `viewer.html?manifest=URL#page=N` (with `viewer.js`, `viewer.css`) is a static scrolling viewer for any simple IIIF Presentation 3 manifest, tested on manifests from the RDF version (`testsets/manifests/`): pages keep their canvas shape before loading, thumbnails show first and full images load once a page stays in view, full images far from view are dropped to save memory, ranges in `structures` are listed as articles, a thumbnail panel (beside the pages on wide screens, over them on phones; a bar in each article's colour) gives fast visual navigation, and in an iframe it tells its parent the current page and goes to a page on request (`postMessage`). Next:
   - Manifests from Elasticsearch (`api.php?item=N&format=iiif` or similar). Page documents need image width and height, from the OCR files or the RDF version's database; articles become ranges.
   - Text selection from word boxes: per-page `supplementing` annotations with `textGranularity` `word`, fetched as pages come into view, drawn as transparent text scaled to each box (as pdf.js does).
   - Images: the test manifests have been changed to BHL's `_large.webp` copies (about 930 px wide, 60-125 KB) instead of `_full.webp` scans (100-600 KB), as this viewer does not zoom; manifests for zooming viewers would keep the full scans or add an image service.
   - Possibly a coverage view across a title (articles per item), to find items no one has split into articles.
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
