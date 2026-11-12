
/* navigation bar */
nav {
	min-height: var(--nav-height);
	border-bottom: 1px solid var(--border-color);
 	background-color: var(--bg);
 	color: var(--text);

	width: 100%;

	position: sticky;
	top: 0;
	/* Leaflet map controls have z-index: 1000 so need to be larger than that to
	hide controls behind nav bar when scrolling */
	z-index: 1001;
}

/* https://stackoverflow.com/questions/23226888/horizontal-list-items-fit-to-100-with-even-spacing */
nav ul {
	margin: 0;
	padding: 0.5em 1em;
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.5em;
	list-style: none;
}

nav ul li {
	display: block;
	flex: 0 1 auto;
	list-style-type: none;
	/* ensure adequate touch target height */
	min-height: 2.5em;
	display: flex;
	align-items: center;
}

nav ul li a {
	padding: 0.4em 0.2em;
}

/* Search item takes up available space */
nav ul li.search-item {
	flex: 1 1 auto;
}

nav ul li.search-item form {
	display: flex;
	gap: 0.3em;
	width: 100%;
}

#search {
	font-size: 1em;
	flex: 1 1 auto;
	min-width: 0; /* allow shrinking below content size */
}

/* Mobile: search bar becomes its own full-width row */
@media (max-width: 600px) {
	nav ul {
		padding: 0.4em 0.6em;
		gap: 0.4em;
	}

	nav ul li.search-item {
		flex: 1 0 100%; /* full width, own row */
		order: 10;      /* push to end so links stay on first row */
	}

	nav ul li a {
		font-size: 0.9em;
	}
}
