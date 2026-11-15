/* home page: what the site can do, as cards with examples */
.home {
	max-width: 64em;
	margin: 0 auto;
	padding: 0 1em 3em 1em;
}

.home h1 {
	margin: 1em 0 0.3em 0;
}

.home-intro {
	max-width: 42em;
	line-height: 1.5;
	margin: 0 0 1.5em 0;
}

.home-heading {
	margin: 1.5em 0 0.6em 0;
}

.home-cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(17em, 1fr));
	gap: 1em;
}

.home-cards-planned {
	grid-template-columns: repeat(auto-fill, minmax(12em, 1fr));
}

.home-card {
	display: flex;
	flex-direction: column;
	border: 1px solid var(--border-color);
	border-radius: 6px;
	overflow: hidden;
	background-color: var(--bg);
}

/* A crop of a real page, filling the top of the card, zoomed in (the img's transform)
   on the part shown (its object-position and transform-origin) */
.home-card-image {
	display: block;
	aspect-ratio: 2 / 1;
	overflow: hidden;
	background-color: var(--thumbnail-bg);
	border-bottom: 1px solid var(--border-color);
}

.home-card-image:hover {
	background-color: var(--thumbnail-bg);
}

.home-card-image img {
	display: block;
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.home-card-body {
	padding: 0.8em 1em 1em 1em;
}

.home-card h3 {
	font-size: 1.05em;
	font-weight: 600;
	margin: 0 0 0.4em 0;
}

.home-card p {
	font-size: 0.9em;
	line-height: 1.45;
	margin: 0 0 0.6em 0;
}

.home-card-examples {
	list-style: none;
	margin: 0;
	padding: 0;
	font-size: 0.9em;
}

.home-card-examples li {
	padding: 0.15em 0;
}

.home-card-examples li::before {
	content: "→ ";
	color: var(--muted-text);
}

/* Features to come: no picture or links, and quieter */
.home-card-planned {
	border-style: dashed;
	background-color: transparent;
}

.home-card-planned h3,
.home-card-planned p {
	color: var(--muted-text);
}
