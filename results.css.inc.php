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
