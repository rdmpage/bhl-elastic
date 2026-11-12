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

.hit {
	display: flex;
	gap: 1em;
	padding: 1em 0;
	border-bottom: 1px solid var(--border-color);
}

.hit-thumbnail {
	flex: 0 0 80px;
}

.hit-thumbnail img {
	width: 80px;
	height: auto;
	border: 1px solid var(--border-color);
	background-color: var(--thumbnail-bg);
	display: block;
}

.hit-body {
	flex: 1 1 auto;
	min-width: 0; /* let long OCR words wrap rather than widen the column */
}

.hit-body h2 {
	font-size: 1.05em;
	margin: 0 0 0.2em 0;
	line-height: 1.3;
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
