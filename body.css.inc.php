body {
	padding:0;
	margin:0;
	font-family: ui-sans-serif, system-ui, sans-serif;
 	background-color: var(--bg);
 	color: var(--text); 
 	font-size:1em;
}

/* main column */
main {
	padding: 0;
	width: 100%;
	margin: auto;
}

h1, h2 {
	font-weight: normal;
	color:var(--text);
}

h1 {
	font-size:1.5em;
}

h2 {
	font-size:1.2em;
}

p {
}

li {
	padding:0.2em;
}

a {
    text-decoration: none;
    color: var(--link-text);
}

/* If we want an underline when we mouse over the link */
a:hover {
	background-color:var(--link-bg);   
}	

a.external:hover {
	text-decoration:underline;
	background-color:var(--link-bg);   
}	

.search {
	border:1px solid var(--input-border);
 	background-color: var(--input-bg);
 	color: var(--input-color);
}  

.search:focus { 
	background-color: var(--input-bg-focus);
	border:1px solid var(--input-border-focus);
	color: var(--input-focus-color);
}


button {
	font-size:1em;

	background: var(--button-bg);
	color: var(--button-text);
	border: 1px solid var(--button-border);

	padding: 0.5em 1em;
	border-radius: 0.2em;

	-webkit-appearance: none;
	display: inline-block;
}


.error {
	padding:1em;
	background-color: var(--error-bg);
	color: var(--error-text);
}

.warning {
	padding:1em;
	background-color: var(--warning-bg);
	color: var(--warning-text);
}

.debug {
	width: 90vw;
	margin: 1em auto;
}

.debug-data {
	font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
	white-space: pre-wrap;
	word-break: break-word;
	border: 1px solid var(--border-color);
	padding: 1em;
	margin: 0.5em 0 0 0;
	font-size: 0.85em;
	overflow-x: auto;
}

.json-key     { color: #a626a4; }
.json-string  { color: #50a14f; }
.json-number  { color: #986801; }
.json-boolean { color: #0184bc; }
.json-null    { color: #a0a1a7; }

@media (prefers-color-scheme: dark) {
	.json-key     { color: #c678dd; }
	.json-string  { color: #98c379; }
	.json-number  { color: #d19a66; }
	.json-boolean { color: #61afef; }
	.json-null    { color: #888; }
}
