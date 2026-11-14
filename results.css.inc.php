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
