/* search results */
.results {
	max-width: 50em;
	margin: 0 auto;
	padding: 0 1em 2em 1em;
}

.results-summary {
	color: var(--muted-text);
	font-size: 0.9em;
	margin: 1em 0;
}






.hit-meta {
	color: var(--muted-text);
	font-size: 0.85em;
	margin: 0 0 0.5em 0;
}

.hit-snippets {
	font-size: 0.9em;
	line-height: 1.5;
	margin: 0;
	overflow-wrap: anywhere;
}

.hit-snippets em {
	font-style: normal;
	font-weight: bold;
	background-color: var(--highlight-bg);
}

.pager {
	display: flex;
	justify-content: space-between;
	padding: 1em 0;
}

/* grouped results: one article or volume, then its best pages */
.group {
	padding: 1em 0;
	border-bottom: 1px solid var(--border-color);
}

.group-with-thumbnail {
	display: flex;
	gap: 0.8em;
}

.group h2 {
	font-size: 1.05em;
	margin: 0 0 0.2em 0;
	line-height: 1.3;
}

.group-authors {
	font-size: 0.9em;
	margin: 0 0 0.2em 0;
}

.page-hit {
	display: flex;
	gap: 0.8em;
	margin: 0.6em 0 0 0;
}

.page-thumbnail {
	flex: 0 0 56px;
}

.page-thumbnail img {
	width: 56px;
	height: auto;
	border: 1px solid var(--border-color);
	background-color: var(--thumbnail-bg);
	display: block;
}

.page-body {
	flex: 1 1 auto;
	min-width: 0;
}

.page-name {
	font-size: 0.85em;
}

.page-body .hit-snippets {
	margin-top: 0.2em;
}

.group-more {
	color: var(--muted-text);
	font-size: 0.85em;
	margin: 0.6em 0 0 0;
}

/* kinds of search, below the search bar */
.tabs {
	list-style: none;
	display: flex;
	gap: 1.5em;
	margin: 0;
	padding: 0.8em 0 0 0;
	border-bottom: 1px solid var(--border-color);
}

.tabs li {
	padding: 0 0 0.4em 0;
}

.tabs li.active {
	border-bottom: 2px solid var(--text);
}

.tabs li.active a {
	color: var(--text);
}

/* match page */
.match-input {
	width: 100%;
	box-sizing: border-box;
	font: inherit;
	font-size: 0.9em;
}

.match-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.match-table th,
.match-table td {
	text-align: left;
	vertical-align: top;
	padding: 0.4em;
	border-bottom: 1px solid var(--border-color);
	overflow-wrap: anywhere;
}

.match-table td:first-child {
	width: 45%;
}

.match-table td:last-child {
	white-space: nowrap;
}

.match-table tr.no-match td {
	color: var(--muted-text);
}

.match-json pre {
	font-size: 0.8em;
	max-height: 20em;
	overflow: auto;
	background-color: var(--thumbnail-bg);
	padding: 0.5em;
}

/* title page */
.title-header {
	display: flex;
	gap: 1em;
	align-items: flex-start;
	margin-top: 1em;
}

.title-header h1 {
	margin-top: 0;
}

.title-thumbnail {
	width: 80px;
	flex: 0 0 auto;
	background-color: var(--thumbnail-bg);
}

.title-summary {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 0.3em 1em;
	font-size: 0.9em;
}

.title-summary dt {
	color: var(--muted-text);
}

.title-summary dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.counts {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.counts td {
	padding: 0.2em 0.4em 0.2em 0;
	vertical-align: middle;
}

.counts td.count {
	text-align: right;
	white-space: nowrap;
}

.counts td.bar-cell {
	width: 30%;
}

.counts .bar {
	display: block;
	height: 0.8em;
	background-color: var(--muted-text);
	opacity: 0.4;
}

/* title page items, as a list or a grid */
.items-header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 1em;
}

.view-toggle {
	list-style: none;
	display: flex;
	margin: 0;
	padding: 0;
	border: 1px solid var(--border-color);
	border-radius: 4px;
	overflow: hidden;
	font-size: 0.85em;
}

.view-toggle li a {
	display: block;
	padding: 0.25em 0.8em;
}

.view-toggle li.active {
	background-color: var(--thumbnail-bg);
}

.view-toggle li.active a {
	color: var(--text);
}

.items-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
	gap: 1em;
	margin-top: 0.6em;
}

.items-grid .page-hit {
	flex-direction: column;
	gap: 0.3em;
	margin: 0;
}

.items-grid .page-thumbnail {
	flex: none;
}

.items-grid .page-thumbnail img {
	width: 100%;
	/* same shape for every cover, so the names line up */
	aspect-ratio: 2 / 3;
	object-fit: contain;
	/* no visible box: covers sit on a common baseline above their names */
	object-position: center bottom;
	border: none;
	background-color: transparent;
}

.items-grid .page-name {
	font-size: 0.85em;
}

.items-grid .hit-meta {
	display: none;
}

/* items by year */
.histogram {
	margin: 0.5em 0 0 0;
}

.histogram-bars {
	display: flex;
	align-items: stretch;
	height: 80px;
	border-bottom: 1px solid var(--border-color);
}

/* each year is a full-height hover target, wider than its bar */
.histogram-year {
	flex: 1 1 0;
	min-width: 0;
	display: flex;
	align-items: flex-end;
	padding: 0 1px;
	position: relative;
}

.histogram-bar {
	display: block;
	width: 100%;
	max-width: 24px;
	margin: 0 auto;
	min-height: 2px;
	background-color: var(--muted-text);
	opacity: 0.55;
	border-radius: 4px 4px 0 0;
}

.histogram-year:hover .histogram-bar {
	opacity: 1;
}

.histogram-year:hover::after {
	content: attr(data-label);
	position: absolute;
	bottom: 100%;
	left: 50%;
	transform: translateX(-50%);
	white-space: nowrap;
	font-size: 0.8em;
	padding: 0.2em 0.5em;
	background-color: var(--bg);
	color: var(--text);
	border: 1px solid var(--border-color);
	border-radius: 4px;
	pointer-events: none;
	z-index: 1;
}

.histogram-axis {
	position: relative;
	height: 1.4em;
	font-size: 0.75em;
	color: var(--muted-text);
}

.histogram-axis span {
	position: absolute;
	top: 0.2em;
	transform: translateX(-50%);
	white-space: nowrap;
}

/* Item coverage: pages coloured by article. Colours are Vega's tableau10, used in turn,
   so neighbouring articles differ; hatching marks pages in no article. */
:root {
	--part-0: #4c78a8;
	--part-1: #f58518;
	--part-2: #e45756;
	--part-3: #72b7b2;
	--part-4: #54a24b;
	--part-5: #eeca3b;
	--part-6: #b279a2;
	--part-7: #ff9da6;
	--part-8: #9d755d;
	--part-9: #bab0ac;
	--coverage-hatch: repeating-linear-gradient(45deg, var(--thumbnail-bg) 0 4px, var(--border-color) 4px 6px);
}

.coverage-none {
	background: var(--coverage-hatch);
}

.coverage-key {
	display: inline-block;
	width: 1em;
	height: 1em;
	border-radius: 2px;
	vertical-align: -0.15em;
	flex: none;
}

.coverage-plate-key {
	border: 2px solid var(--text);
	box-sizing: border-box;
}

.coverage-strip {
	display: flex;
	height: 28px;
	gap: 0;
	margin: 0.5em 0 1em 0;
	border-radius: 4px;
	overflow: hidden;
}

.coverage-strip span {
	flex: 1 1 0;
	min-width: 0;
}

.coverage-strip .coverage-plate {
	box-shadow: inset 0 -6px 0 var(--text);
}

.coverage-pages {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(64px, 1fr));
	gap: 2px;
}

.coverage-pages .coverage-page {
	display: block;
	padding: 5px 5px 2px 5px;
	border-radius: 4px;
	text-decoration: none;
	color: var(--text);
}

/* transparent, so the article colour fills the space round a letterboxed image */
.coverage-image {
	display: block;
	aspect-ratio: 2 / 3;
}

.coverage-image img {
	width: 100%;
	height: 100%;
	object-fit: contain;
	display: block;
}

.coverage-label {
	display: block;
	font-size: 0.7em;
	text-align: center;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	margin-top: 2px;
}

/* the label sits on the article colour, so give it the page background */
.coverage-page .coverage-label {
	background-color: var(--bg);
	border-radius: 2px;
}

.coverage-page.coverage-plate .coverage-image {
	outline: 2px solid var(--text);
	outline-offset: -2px;
}

.coverage-active {
	filter: brightness(1.15);
	outline: 2px solid var(--text);
	outline-offset: -2px;
}

.coverage-articles {
	padding-left: 0;
	list-style: none;
}

.coverage-articles li {
	display: flex;
	gap: 0.6em;
	align-items: flex-start;
	padding: 0.4em;
	border-radius: 4px;
}

.coverage-articles li .coverage-key {
	margin-top: 0.2em;
}

.coverage-articles li.coverage-active {
	filter: none;
	background-color: var(--thumbnail-bg);
}

.coverage-articles p {
	margin: 0.1em 0 0 0;
}

/* title browser: facets down the side, a grid of titles */
.browser {
	max-width: 72em;
	margin: 0 auto;
	padding: 0 1em 2em 1em;
}

.browser-columns {
	display: grid;
	grid-template-columns: 16em minmax(0, 1fr);
	gap: 2em;
}

@media (max-width: 700px) {
	.browser-columns {
		grid-template-columns: minmax(0, 1fr);
		gap: 0;
	}
}

.facets h3 {
	font-size: 0.9em;
	margin: 1.2em 0 0.3em 0;
}

.facet-filter {
	width: 100%;
	box-sizing: border-box;
	font: inherit;
	margin-top: 1em;
	padding: 0.3em 0.5em;
	border: 1px solid var(--input-border);
	border-radius: 4px;
	background-color: var(--input-bg);
	color: var(--input-color);
}

.facet {
	list-style: none;
	margin: 0;
	padding: 0;
	font-size: 0.85em;
}

.facet a {
	display: flex;
	gap: 0.5em;
	padding: 0.15em 0.3em;
	border-radius: 4px;
	color: var(--text);
	text-decoration: none;
}

.facet a:hover {
	background-color: var(--thumbnail-bg);
}

.facet-name {
	flex: 1 1 auto;
	min-width: 0;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.facet-count {
	flex: none;
	color: var(--muted-text);
}

.facet li.selected a {
	font-weight: bold;
	background-color: var(--thumbnail-bg);
}

.facet-histogram .histogram-bars {
	height: 50px;
}

.facet-histogram .histogram-year.selected .histogram-bar {
	background-color: var(--text);
	opacity: 1;
}

.facet-histogram .histogram-axis span:first-child {
	transform: none;
	left: 0 !important;
}

.facet-histogram .histogram-axis span:last-child {
	transform: none;
	left: auto !important;
	right: 0;
}

.title-grid .tile-meta {
	margin: 0;
	font-size: 0.75em;
	color: var(--muted-text);
}

.title-grid .page-name {
	display: -webkit-box;
	-webkit-line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.load-more {
	display: flex;
	align-items: center;
	justify-content: center;
	aspect-ratio: 2 / 3;
	border: 1px dashed var(--border-color);
	border-radius: 4px;
	font-size: 0.9em;
}

.facet-details summary {
	display: none;
}

@media (max-width: 700px) {
	.facet-details summary {
		display: list-item;
		margin-top: 0.8em;
		color: var(--link-text);
		cursor: pointer;
	}
}

/* citing an article */
.cite-bar {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5em;
	align-items: center;
	margin-bottom: 0.6em;
}

.cite-styles {
	display: inline-flex;
}

.cite-bar button {
	font: inherit;
	font-size: 0.85em;
	padding: 0.25em 0.8em;
	border: 1px solid var(--border-color);
	background-color: var(--bg);
	color: var(--text);
	cursor: pointer;
}

.cite-styles button {
	border-radius: 0;
	margin-left: -1px;
}

.cite-styles button:first-child {
	border-radius: 4px 0 0 4px;
	margin-left: 0;
}

.cite-styles button:last-child {
	border-radius: 0 4px 4px 0;
}

.cite-styles button[aria-pressed="true"] {
	background-color: var(--button-bg);
	border-color: var(--button-border);
	color: var(--button-text);
	position: relative;
}

.cite-copy {
	border-radius: 4px;
}

.cite-copy:disabled {
	cursor: default;
	color: var(--muted-text);
}

.cite-output {
	padding: 0.6em 0.8em;
	border: 1px solid var(--border-color);
	border-radius: 4px;
	background-color: var(--thumbnail-bg);
	overflow-wrap: anywhere;
	min-height: 1.5em;
}

.cite-downloads {
	margin-top: 0.5em;
}

/* map of the points in an article's text */
.part-map {
	height: 22em;
	border: 1px solid var(--border-color);
	border-radius: 4px;
	background-color: var(--thumbnail-bg);
}

/* the map of points in the text: the map beside the list of what is in view */
.map-layout {
	display: grid;
	grid-template-columns: minmax(0, 3fr) minmax(20em, 2fr);
	height: calc(100vh - var(--nav-height) - 1px);
}

.map-pane {
	height: 100%;
	background-color: var(--thumbnail-bg);
}

.map-side {
	overflow-y: auto;
	padding: 0.8em 1em 2em 1em;
	border-left: 1px solid var(--border-color);
}

.map-filter {
	display: flex;
	gap: 0.5em;
}

.map-filter input {
	flex: 1 1 auto;
	min-width: 0;
	font: inherit;
	padding: 0.3em 0.5em;
	border: 1px solid var(--input-border);
	border-radius: 4px;
	background-color: var(--input-bg);
	color: var(--input-color);
}

.map-filter button,
.map-results .pager button {
	font: inherit;
	padding: 0.3em 0.8em;
	border: 1px solid var(--border-color);
	border-radius: 4px;
	background-color: var(--bg);
	color: var(--text);
	cursor: pointer;
}

/* a count of pages, sized by how many; the number is centred in its own span, as
   Leaflet's stylesheet (loaded later) makes the marker itself display: block */
.map-count {
	border-radius: 50%;
	background-color: rgba(5, 150, 105, 0.85);
	border: 2px solid #fff;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
	color: #fff;
	font: 600 11px/1 sans-serif;
	box-sizing: border-box;
}

.map-count span {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
}

@media (max-width: 800px) {
	.map-layout {
		grid-template-columns: 1fr;
		height: auto;
	}
	.map-pane {
		height: 60vh;
	}
	.map-side {
		border-left: none;
		overflow-y: visible;
	}
}

