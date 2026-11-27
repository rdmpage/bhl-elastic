// A scrolling page viewer for a IIIF Presentation 3 manifest (see viewer.html).
//
// Only what a simple manifest needs is read: the canvases (width, height, label,
// thumbnail), the image painted on each, and the ranges in "structures", which are
// listed as articles. An image with a IIIF Image service is fetched at the width the
// page is shown at; otherwise the image itself is used.
//
// Images are loaded only for pages near the screen: the thumbnail first, as it is
// small, then the full image once the page has stayed in view for a moment, so
// scrolling fast past pages does not fetch them all. Full images of pages far from the
// screen go back to thumbnails, as a decoded scan can take 50 MB or more of memory.
//
// The text of a page, if its canvas links to annotations of it (supplementing
// annotations whose body is text and whose target is a box, #xywh=, on the canvas, one
// per word or line), is fetched with its full image and laid over the image as
// invisible text, each piece stretched to fill its box, so it can be selected and
// copied. It is dropped again with the full image.
//
// Text with no box (textGranularity page, say: OCR that gives no word positions) is not
// laid over the image, but it is shown, with any other text, in the text panel at the
// right, which shows the text of the page showing.
//
// Thumbnails of all pages, for moving around fast, are built the first time they are
// shown: beside the pages on wide screens, following the page showing, and over them on
// phones, closing when a page is chosen. Thumbnails of pages in an article have a bar
// in its colour, as on item pages.
//
// If the manifest has a search service (IIIF Content Search 2.0, SearchService2), a
// panel at the right searches inside it and lists the hits by page, each in its
// context (the TextQuoteSelector of its contextualizing annotation). Hits whose target
// is a box (#xywh=), or several, are marked on their pages; choosing a hit goes to it.
// Hits are also marked in the text panel, found by their quote; choosing a hit with no
// box opens the text panel at it.
// ?q= in the viewer's URL runs a search when it opens, and is kept up to date, so a
// search can be linked to; the first hit on the page asked for is then shown.
//
// In an iframe the viewer sends its parent { type: 'page', page, label, canvas } when
// the page showing changes (page counts from 1), and goes to a page when sent
// { goto: page } or { canvas: canvas id }.

(function () {

	var params = new URLSearchParams(window.location.search);

	var scroller    = document.getElementById('viewer-pages-list');
	var page_select = document.getElementById('viewer-pages');
	var range_select = document.getElementById('viewer-articles');
	var thumbs       = document.getElementById('viewer-thumbs');
	var thumbs_toggle = document.getElementById('viewer-thumbs-toggle');
	var search_panel   = document.getElementById('viewer-search');
	var search_toggle  = document.getElementById('viewer-search-toggle');
	var search_form    = document.getElementById('viewer-search-form');
	var search_input   = document.getElementById('viewer-search-q');
	var search_status  = document.getElementById('viewer-search-status');
	var search_results = document.getElementById('viewer-search-results');
	var side           = document.getElementById('viewer-side');
	var text_panel     = document.getElementById('viewer-text');
	var text_toggle    = document.getElementById('viewer-text-toggle');
	var text_label     = document.getElementById('viewer-text-label');
	var text_body      = document.getElementById('viewer-text-body');
	var link_panel     = document.getElementById('viewer-link');
	var link_toggle    = document.getElementById('viewer-link-toggle');
	var link_label     = document.getElementById('viewer-link-label');
	var link_url       = document.getElementById('viewer-link-url');
	var link_copy      = document.getElementById('viewer-link-copy');

	var pages   = [];   // { canvas, label, thumbnail, image, service, el, img, ... }
	var ranges  = [];   // { label, first, last } as page indexes
	var current = -1;
	var by_canvas = {};  // page index by canvas id
	var search_service = null;  // id of the manifest's search service
	var search_run = 0;         // counts searches, so replies to old ones are dropped
	var current_hit = null;     // the search hit chosen

	// How long a page must be in view before its full image is fetched (ms)
	var DWELL = 150;

	// Widths to fetch from an image service, so that cached images get reused
	var WIDTHS = [400, 600, 800, 1000, 1200, 1600, 2000, 2400, 3200];

	// Most responses to follow (through "next") for one search
	var SEARCH_RESPONSES = 40;

	var in_iframe = window.parent !== window;

	var narrow = window.matchMedia('(max-width: 700px)');

	//------------------------------------------------------------------------------------
	// First string of a IIIF language map ({ "en": ["p. 1"] }), or null
	function label_text(label) {
		if (!label) {
			return null;
		}
		if (typeof label === 'string') {
			return label;
		}
		for (var lang in label) {
			if (label[lang] && label[lang].length > 0) {
				return label[lang].join(' ');
			}
		}
		return null;
	}

	//------------------------------------------------------------------------------------
	function as_array(x) {
		return x === undefined || x === null ? [] : (Array.isArray(x) ? x : [x]);
	}

	//------------------------------------------------------------------------------------
	// The image painted on a canvas, as { id, service } (service is the base URL of a
	// IIIF Image service, if there is one)
	function canvas_image(canvas) {
		var pages_ = as_array(canvas.items);
		for (var i = 0; i < pages_.length; i++) {
			var annotations = as_array(pages_[i].items);
			for (var j = 0; j < annotations.length; j++) {
				var annotation = annotations[j];
				if (annotation.motivation !== 'painting') {
					continue;
				}
				var body = annotation.body;
				if (body && body.type === 'Choice') {
					body = as_array(body.items)[0];
				}
				if (!body) {
					continue;
				}
				var service = null;
				as_array(body.service).forEach(function (s) {
					var type = s.type || s['@type'] || '';
					if (!service && /ImageService/.test(type)) {
						service = (s.id || s['@id']).replace(/\/$/, '');
					}
				});
				return { id: body.id, service: service };
			}
		}
		return null;
	}

	//------------------------------------------------------------------------------------
	// URL of a page's image at about the given width in pixels
	function image_url(page, width) {
		if (page.service) {
			var w = WIDTHS[WIDTHS.length - 1];
			for (var i = 0; i < WIDTHS.length; i++) {
				if (WIDTHS[i] >= width) {
					w = WIDTHS[i];
					break;
				}
			}
			if (page.width && w >= page.width) {
				return page.service + '/full/max/0/default.jpg';
			}
			return page.service + '/full/' + w + ',/0/default.jpg';
		}
		return page.image;
	}

	//------------------------------------------------------------------------------------
	function thumbnail_url(canvas, page) {
		var thumbnail = as_array(canvas.thumbnail)[0];
		if (thumbnail && thumbnail.id) {
			return thumbnail.id;
		}
		if (page.service) {
			return page.service + '/full/200,/0/default.jpg';
		}
		return null;
	}

	//------------------------------------------------------------------------------------
	// All canvas ids in a range, including those in ranges inside it
	function range_canvases(range, list) {
		as_array(range.items).forEach(function (item) {
			if (item.type === 'Canvas') {
				list.push(item.id.replace(/#.*$/, ''));
			} else if (item.type === 'Range') {
				range_canvases(item, list);
			}
		});
		return list;
	}

	//------------------------------------------------------------------------------------
	function show_message(text) {
		scroller.innerHTML = '';
		var p = document.createElement('p');
		p.className = 'viewer-message';
		p.textContent = text;
		scroller.appendChild(p);
	}

	//------------------------------------------------------------------------------------
	// Image loading

	// Show the thumbnail, then fetch the full image if the page is still in view
	function page_near(page) {
		if (page.state === 'none' && page.thumbnail) {
			page.img.src = page.thumbnail;
			page.state = 'thumbnail';
		}
		if (page.state !== 'full' && !page.timer) {
			page.timer = setTimeout(function () {
				page.timer = null;
				load_full(page);
				load_text(page);
			}, DWELL);
		}
	}

	// Page has left the screen before its full image was asked for
	function page_gone(page) {
		if (page.timer) {
			clearTimeout(page.timer);
			page.timer = null;
		}
	}

	function load_full(page) {
		var url = image_url(page, page.el.clientWidth * (window.devicePixelRatio || 1));
		if (page.state === 'full' && page.img.src === url) {
			return;
		}
		// Load and decode off screen, then swap, so the thumbnail never flashes blank
		var full = new Image();
		full.decoding = 'async';
		full.src = url;
		page.loading = full;
		var swap = function () {
			if (page.loading === full) {
				page.img.src = url;
				page.state = 'full';
				page.loading = null;
			}
		};
		(full.decode ? full.decode() : Promise.reject()).then(swap, function () {
			full.onload = swap;
		});
	}

	// Page is far from the screen: free its full image and its text
	function page_far(page) {
		page_gone(page);
		page.loading = null;
		if (page.text_el) {
			page.text_el.remove();
			page.text_el = null;
		}
		if (page.state === 'full') {
			if (page.thumbnail) {
				page.img.src = page.thumbnail;
				page.state = 'thumbnail';
			} else {
				page.img.removeAttribute('src');
				page.state = 'none';
			}
		}
	}

	//------------------------------------------------------------------------------------
	// Text

	// Widths of text are measured at 100px in the font the text layer uses
	var measure = document.createElement('canvas').getContext('2d');
	measure.font = '100px sans-serif';

	// A box on a canvas, [x, y, w, h], from an annotation's target: a string ending in
	// #xywh=, or an object with a FragmentSelector
	function target_box(target) {
		var value = target;
		if (target && typeof target === 'object') {
			value = target.selector ? as_array(target.selector)[0].value : target.id;
		}
		var m = typeof value === 'string'
			? value.match(/xywh=(?:pixel:)?(\d+(?:\.\d+)?),(\d+(?:\.\d+)?),(\d+(?:\.\d+)?),(\d+(?:\.\d+)?)/)
			: null;
		return m ? m.slice(1).map(Number) : null;
	}

	// An annotation's text, if its body is text
	function body_text(body) {
		var bodies = as_array(body);
		for (var i = 0; i < bodies.length; i++) {
			if (typeof bodies[i] === 'string') {
				return bodies[i];
			}
			if (bodies[i] && typeof bodies[i].value === 'string') {
				return bodies[i].value;
			}
		}
		return null;
	}

	// Fetch the page's annotations (each AnnotationPage its canvas lists, inline or by
	// id) once, and keep its pieces of text, { box, text, granularity }
	function load_text(page) {
		if (page.text_items) {
			draw_text(page);
			return;
		}
		if (page.text_loading || page.annotation_pages.length === 0 || !page.width || !page.height) {
			return;
		}
		page.text_loading = true;
		var unplaced = [];

		Promise.all(page.annotation_pages.map(function (annotation_page) {
			if (annotation_page.items) {
				return annotation_page;
			}
			return fetch(annotation_page.id)
				.then(function (response) {
					return response.ok ? response.json() : {};
				})
				.catch(function () {
					return {};
				});
		})).then(function (annotation_pages) {
			var items = [];
			var places = [];
			var names = [];
			annotation_pages.forEach(function (annotation_page) {
				as_array(annotation_page.items).forEach(function (annotation) {
					var place = annotation_place(annotation);
					if (place) {
						places.push(place);
						return;
					}
					var name = annotation_name(annotation);
					if (name) {
						names.push(name);
						return;
					}
					if (annotation.motivation && annotation.motivation !== 'supplementing') {
						return;
					}
					var box = target_box(annotation.target);
					var text = body_text(annotation.body);
					if (box && text && box[2] > 0 && box[3] > 0) {
						items.push({ box: box, text: text, granularity: annotation.textGranularity });
					} else if (text && !box) {
						unplaced.push(text);
					}
				});
			});
			page.text_items = items;
			page.text_unplaced = unplaced.join('\n\n');
			page.text_loading = false;
			page.places = places;
			page.names = names;
			draw_names(page);
			draw_places(page);
			if (page.state === 'full' || page.loading) {
				draw_text(page);
			}
			if (page.index === current) {
				show_page_text();
			}
		});
	}

	// A point on a map that an annotation tags its target with: { boxes, label, lat,
	// lon }, if its body is a GeoJSON Point (as iiif.php?page=N&annotations=geo gives for
	// latitude and longitude pairs in the text), otherwise null
	function annotation_place(annotation) {
		if (annotation.motivation !== 'tagging') {
			return null;
		}
		var bodies = as_array(annotation.body);
		for (var i = 0; i < bodies.length; i++) {
			var body = bodies[i];
			if (body && body.type === 'Feature' && body.geometry && body.geometry.type === 'Point') {
				return {
					boxes: as_array(annotation.target).map(target_box).filter(Boolean),
					label: body.properties ? label_text(body.properties.label) : '',
					lon: body.geometry.coordinates[0],
					lat: body.geometry.coordinates[1]
				};
			}
		}
		return null;
	}

	// A scientific name that an annotation tags its target with: { boxes, name, terms,
	// is_new }, if it has a TextualBody with purpose tagging (as iiif.php?page=N&
	// annotations=names gives), otherwise null. terms are its bodies with purpose
	// classifying, the nomenclatural statements after it ("sp. nov.", "syn. nov."...);
	// is_new is whether it is published as new here, a classifying body "new name" (not
	// every "nov." is: syn. nov. publishes no name, and "G. aus sp. nov." is a new name
	// mentioned again)
	function annotation_name(annotation) {
		if (annotation.motivation !== 'tagging') {
			return null;
		}
		var name = null;
		var terms = [];
		var is_new = false;
		as_array(annotation.body).forEach(function (body) {
			if (body && body.type === 'TextualBody' && typeof body.value === 'string') {
				if (body.purpose === 'tagging' && name === null) {
					name = body.value;
				} else if (body.purpose === 'classifying' && body.value === 'new name') {
					is_new = true;
				} else if (body.purpose === 'classifying') {
					terms.push(body.value);
				}
			}
		});
		if (name === null) {
			return null;
		}
		return {
			boxes: as_array(annotation.target).map(target_box).filter(Boolean),
			name: name,
			terms: terms,
			is_new: is_new
		};
	}

	// Mark the names on a page, beneath the places: new names outlined, others
	// underlined faintly; each searches the index for the name
	function draw_names(page) {
		if (page.names_el || !page.names || page.names.length === 0 || !page.width || !page.height) {
			return;
		}
		var layer = document.createElement('div');
		layer.className = 'viewer-names';
		page.names.forEach(function (name) {
			var title = name.name + (name.terms.length ? ' (' + name.terms.join(', ') + ')' : '') + ': search for this name';
			var href = './?q=' + encodeURIComponent(name.name);
			name.boxes.forEach(function (box) {
				var a = document.createElement('a');
				a.href = href;
				a.title = title;
				a.setAttribute('aria-label', title);
				if (name.is_new) {
					a.className = 'viewer-name-new';
				}
				a.style.left = (box[0] / page.width * 100) + '%';
				a.style.top = (box[1] / page.height * 100) + '%';
				a.style.width = (box[2] / page.width * 100) + '%';
				a.style.height = (box[3] / page.height * 100) + '%';
				layer.appendChild(a);
			});
		});
		page.names_el = layer;
		page.el.appendChild(layer);
	}

	// "24.3667° N, 110.9667° W"
	function format_point(lat, lon) {
		return Math.abs(lat).toFixed(4) + '° ' + (lat < 0 ? 'S' : 'N') + ', '
			+ Math.abs(lon).toFixed(4) + '° ' + (lon < 0 ? 'W' : 'E');
	}

	// Outline the places on a page, above its text; each opens its point on a map
	function draw_places(page) {
		if (page.places_el || !page.places || page.places.length === 0 || !page.width || !page.height) {
			return;
		}
		var layer = document.createElement('div');
		layer.className = 'viewer-places';
		page.places.forEach(function (place) {
			var title = format_point(place.lat, place.lon) + (place.label ? ' (' + place.label + ')' : '') + ': open in OpenStreetMap';
			var href = 'https://www.openstreetmap.org/?mlat=' + place.lat + '&mlon=' + place.lon
				+ '#map=10/' + place.lat + '/' + place.lon;
			place.boxes.forEach(function (box) {
				var a = document.createElement('a');
				a.href = href;
				a.target = '_blank';
				a.rel = 'noopener';
				a.title = title;
				a.setAttribute('aria-label', title);
				a.style.left = (box[0] / page.width * 100) + '%';
				a.style.top = (box[1] / page.height * 100) + '%';
				a.style.width = (box[2] / page.width * 100) + '%';
				a.style.height = (box[3] / page.height * 100) + '%';
				layer.appendChild(a);
			});
		});
		page.places_el = layer;
		page.el.appendChild(layer);
	}

	// A page's text, for the text panel: its text without boxes if it has any, otherwise
	// the pieces laid over the image, joined as when copied; null if not loaded yet
	function page_text(page) {
		if (!page.text_items) {
			return page.annotation_pages.length === 0 ? '' : null;
		}
		if (page.text_unplaced) {
			return page.text_unplaced;
		}
		return page.text_items.map(function (item, i) {
			return item.text + text_separator(item, page.text_items[i + 1]);
		}).join('').replace(/\s+$/, '');
	}

	// Where a search hit's quote is in a text, from from on, as [start, end], or null:
	// the first place of its exact text whose text before ends with its prefix, or else
	// the first place of its exact text
	function find_quote(text, quote, from) {
		var exact = quote.exact.trim().replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s+');
		if (!exact) {
			return null;
		}
		var re = new RegExp(exact, 'g');
		re.lastIndex = from;
		var prefix = (quote.prefix || '').replace(/\s+/g, ' ').trim();
		var first = null;
		var m;
		while ((m = re.exec(text)) !== null) {
			var range = [m.index, m.index + m[0].length];
			if (!first) {
				first = range;
			}
			var before = text.slice(Math.max(0, m.index - 2 * prefix.length - 10), m.index).replace(/\s+/g, ' ').trim();
			if (prefix && before.slice(-prefix.length) === prefix) {
				return range;
			}
		}
		return first;
	}

	// Show the text of the page showing in the text panel, with its search hits marked
	function show_page_text() {
		if (text_panel.hidden || current < 0) {
			return;
		}
		var page = pages[current];
		text_label.textContent = page.label || '[' + (current + 1) + ']';

		var text = page_text(page);
		if (text === null) {
			text_body.className = 'viewer-text-body viewer-text-none';
			text_body.textContent = 'Loading…';
			load_text(page);
			return;
		}
		if (text === '') {
			text_body.className = 'viewer-text-body viewer-text-none';
			text_body.textContent = 'No text for this page.';
			return;
		}

		// The hits, which come in the order they are in the text, each found after the
		// one before
		var marks = [];
		var from = 0;
		(page.hits || []).forEach(function (hit) {
			var range = hit.quote ? find_quote(text, hit.quote, from) : null;
			if (range) {
				marks.push({ start: range[0], end: range[1], hit: hit });
				from = range[1];
			}
		});

		text_body.className = 'viewer-text-body';
		text_body.textContent = '';
		var at = 0;
		var current_mark = null;
		marks.forEach(function (m) {
			text_body.appendChild(document.createTextNode(text.slice(at, m.start)));
			var mark = document.createElement('mark');
			mark.textContent = text.slice(m.start, m.end);
			if (m.hit === current_hit) {
				mark.className = 'current';
				current_mark = mark;
			}
			text_body.appendChild(mark);
			at = m.end;
		});
		text_body.appendChild(document.createTextNode(text.slice(at)));

		if (current_mark) {
			text_body.scrollTop = current_mark.offsetTop - text_body.offsetTop - text_body.clientHeight / 3;
		} else {
			text_body.scrollTop = 0;
		}
	}

	// What follows a piece of text when copied: a line break after a line, or after a
	// word if the next word starts a new line (it is lower, or further left)
	function text_separator(item, next) {
		if (!next || item.granularity === 'line' || item.granularity === 'block'
			|| item.granularity === 'paragraph' || item.granularity === 'page') {
			return '\n';
		}
		var b = item.box;
		var n = next.box;
		return n[1] > b[1] + b[3] / 2 || n[0] < b[0] ? '\n' : ' ';
	}

	// Lay the text over the image. Positions and sizes are in % of the page and font
	// sizes in cqw (% of the page's width), so the layer scales with the page and
	// nothing needs redoing when the window changes size; each piece is stretched
	// across its box with scaleX, which also does not depend on the page's size.
	function draw_text(page) {
		if (page.text_el || !page.text_items || page.text_items.length === 0) {
			return;
		}
		var w = page.width;
		var h = page.height;
		var layer = document.createElement('div');
		layer.className = 'viewer-text';

		page.text_items.forEach(function (item, i) {
			var box = item.box;
			var span = document.createElement('span');
			span.textContent = item.text;
			span.dataset.after = text_separator(item, page.text_items[i + 1]);
			span.style.left = (box[0] / w * 100) + '%';
			span.style.top = (box[1] / h * 100) + '%';
			span.style.fontSize = (box[3] / w * 100) + 'cqw';
			var natural = measure.measureText(item.text).width;
			if (natural > 0) {
				span.style.transform = 'scaleX(' + (100 * box[2] / (natural * box[3])) + ')';
			}
			layer.appendChild(span);
		});

		var end = document.createElement('div');
		end.className = 'viewer-text-end';
		layer.appendChild(end);

		page.text_el = layer;
		page.el.appendChild(layer);
	}

	// Selecting text. Between the pieces of text the pointer is over the layer itself,
	// and the browser puts the end of the selection at the layer's start, so everything
	// above where the selection began flashes selected until the pointer reaches the next
	// piece. As in PDF.js, an invisible box (.viewer-text-end) covers the layer while
	// selecting, and is moved to just after (or before, going backwards) the piece the
	// selection ends in, so over a gap the selection ends there instead.
	function text_end(layer) {
		return layer.querySelector(':scope > .viewer-text-end');
	}

	function end_selecting() {
		Array.prototype.forEach.call(scroller.querySelectorAll('.viewer-text.selecting'), function (layer) {
			layer.classList.remove('selecting');
			layer.appendChild(text_end(layer));
		});
	}

	scroller.addEventListener('pointerdown', function (event) {
		var layer = event.target.closest && event.target.closest('.viewer-text');
		if (layer) {
			layer.classList.add('selecting');
		}
	});

	document.addEventListener('pointerup', end_selecting);
	window.addEventListener('blur', end_selecting);

	document.addEventListener('selectionchange', function () {
		var selection = window.getSelection();
		if (!selection || selection.rangeCount === 0 || !scroller.querySelector('.viewer-text.selecting')) {
			return;
		}
		var range = selection.getRangeAt(0);

		// Selecting across pages: each page the selection reaches
		Array.prototype.forEach.call(scroller.querySelectorAll('.viewer-text'), function (layer) {
			if (range.intersectsNode(layer)) {
				layer.classList.add('selecting');
			}
		});

		// The piece of text the moving end (focus) of the selection is in
		var node = selection.focusNode;
		var span = node && (node.nodeType === Node.TEXT_NODE ? node.parentNode : null);
		if (!span || span.tagName !== 'SPAN' || !span.parentNode.classList.contains('viewer-text')) {
			return;
		}
		var backwards = range.startContainer === selection.focusNode && range.startOffset === selection.focusOffset;
		var layer = span.parentNode;
		var end = text_end(layer);
		// Going forwards and at the very start of a piece, the selection stops before it
		var before = backwards || selection.focusOffset === 0;
		var next = before ? span : span.nextSibling;
		if (next !== end && end.nextSibling !== next) {
			layer.insertBefore(end, next);
		}
	});

	// Copy what is selected as text, with a space or line break after each piece that
	// is selected to its end (see text_separator()). The page has just the pieces of
	// text, as a trailing space or line break would stick out of its box.
	document.addEventListener('copy', function (event) {
		var selection = window.getSelection();
		if (!selection || selection.rangeCount === 0 || selection.isCollapsed) {
			return;
		}
		var range = selection.getRangeAt(0);
		var spans = scroller.querySelectorAll('.viewer-text span');
		var parts = [];
		for (var i = 0; i < spans.length; i++) {
			var span = spans[i];
			if (!range.intersectsNode(span)) {
				continue;
			}
			var text = span.textContent;
			var start = 0;
			var end = text.length;
			if (span.contains(range.startContainer)) {
				start = range.startOffset;
			}
			if (span.contains(range.endContainer)) {
				end = range.endOffset;
			}
			parts.push(text.substring(start, end) + (end === text.length ? span.dataset.after : ''));
		}
		if (parts.length > 0 && event.clipboardData) {
			event.clipboardData.setData('text/plain', parts.join('').replace(/\s+$/, ''));
			event.preventDefault();
		}
	});

	//------------------------------------------------------------------------------------
	// The page showing (the one across the middle of the screen) has changed
	function set_current(index) {
		if (index === current) {
			return;
		}
		current = index;
		var page = pages[index];

		page_select.selectedIndex = index;

		if (ranges.length > 0) {
			var r = 0;
			for (var i = 0; i < ranges.length; i++) {
				if (index >= ranges[i].first && index <= ranges[i].last) {
					r = i + 1;
					break;
				}
			}
			range_select.selectedIndex = r;
		}

		if (page.thumb_el) {
			var old = thumbs.querySelector('.current');
			if (old) {
				old.classList.remove('current');
			}
			page.thumb_el.classList.add('current');
			if (!thumbs.hidden) {
				page.thumb_el.scrollIntoView({ block: 'nearest' });
			}
		}

		history.replaceState(null, '', '#page=' + (index + 1));

		show_link();

		show_page_text();

		if (in_iframe) {
			window.parent.postMessage({
				type: 'page',
				page: index + 1,
				label: page.label,
				canvas: page.canvas
			}, '*');
		}
	}

	//------------------------------------------------------------------------------------
	// A page's stable link: its canvas's web page (homepage), which on this site is
	// ?page=PageID. The URL bar (#page=) is the page's place in this manifest, which
	// differs between an article and its item.
	function canvas_link(canvas) {
		var homepage = as_array(canvas.homepage).filter(function (h) {
			return h && h.id;
		});
		return homepage.length > 0 ? homepage[0].id : null;
	}

	// The link box shows the link of the page showing
	function show_link() {
		var page = pages[current];
		if (!page) {
			return;
		}
		link_label.textContent = 'Link to ' + (page.label ? 'page ' + page.label : 'this page');
		link_url.value = page.link || '';
		link_copy.disabled = !page.link;
		link_copy.textContent = 'Copy';
	}

	function show_link_panel(show) {
		link_panel.hidden = !show;
		link_toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
		if (show) {
			show_link();
			// Below its button, kept on the screen
			var box = link_toggle.getBoundingClientRect();
			link_panel.style.top = (box.bottom + 6) + 'px';
			var left = Math.min(box.left, window.innerWidth - link_panel.offsetWidth - 8);
			link_panel.style.left = Math.max(8, left) + 'px';
			link_url.focus();
			link_url.select();
		}
	}

	//------------------------------------------------------------------------------------
	function go_to(index) {
		if (index >= 0 && index < pages.length) {
			pages[index].el.scrollIntoView({ block: 'start' });
		}
	}

	// The page asked for in the URL: #page=N (its position, from 1), or #canvas=id (the
	// canvas's id, URL-encoded), which works whatever pages a manifest leaves out.
	// -1 if neither, or no such page.
	function hash_page() {
		var m = window.location.hash.match(/page=(\d+)/);
		if (m) {
			return parseInt(m[1], 10) - 1;
		}
		m = window.location.hash.match(/canvas=([^&]+)/);
		if (m && by_canvas[decodeURIComponent(m[1])] !== undefined) {
			return by_canvas[decodeURIComponent(m[1])];
		}
		return -1;
	}

	//------------------------------------------------------------------------------------
	function build(manifest) {
		var title = label_text(manifest.label) || '';
		document.title = title || 'Viewer';
		document.getElementById('viewer-title').textContent = title;

		var fragment = document.createDocumentFragment();
		var options = document.createDocumentFragment();

		as_array(manifest.items).forEach(function (canvas, i) {
			if (canvas.type !== 'Canvas') {
				return;
			}
			var image = canvas_image(canvas) || {};
			var page = {
				index: pages.length,
				canvas: canvas.id,
				label: label_text(canvas.label),
				width: canvas.width,
				height: canvas.height,
				image: image.id,
				service: image.service,
				state: 'none',
				annotation_pages: as_array(canvas.annotations),
				link: canvas_link(canvas),
				timer: null,
				loading: null
			};
			page.thumbnail = thumbnail_url(canvas, page);

			var el = document.createElement('div');
			el.className = 'viewer-page';
			el.dataset.index = page.index;
			if (canvas.width && canvas.height) {
				el.style.aspectRatio = canvas.width + ' / ' + canvas.height;
			}

			var img = document.createElement('img');
			img.alt = '';   // no broken-image icon while loading
			el.setAttribute('role', 'img');
			el.setAttribute('aria-label', page.label || 'Page ' + (page.index + 1));
			img.draggable = false;
			el.appendChild(img);

			page.el = el;
			page.img = img;
			fragment.appendChild(el);

			var option = document.createElement('option');
			option.value = page.index;
			option.textContent = (page.label || '[' + (page.index + 1) + ']');
			options.appendChild(option);

			by_canvas[canvas.id] = page.index;
			pages.push(page);
		});

		if (pages.length === 0) {
			show_message('This manifest has no pages.');
			return;
		}

		// Page list shows the label and the position in the item
		Array.prototype.forEach.call(options.childNodes, function (option, i) {
			option.textContent += '  (' + (i + 1) + ' / ' + pages.length + ')';
		});

		scroller.innerHTML = '';
		scroller.appendChild(fragment);
		page_select.appendChild(options);

		// Articles
		as_array(manifest.structures).forEach(function (range) {
			var indexes = range_canvases(range, []).map(function (id) {
				return by_canvas[id];
			}).filter(function (x) {
				return x !== undefined;
			});
			if (indexes.length > 0) {
				ranges.push({
					label: label_text(range.label) || '[' + (ranges.length + 1) + ']',
					first: Math.min.apply(null, indexes),
					last: Math.max.apply(null, indexes)
				});
			}
		});

		// Each page's article (the first, if it is in more than one)
		ranges.forEach(function (range, r) {
			for (var i = range.first; i <= range.last; i++) {
				if (pages[i].range === undefined) {
					pages[i].range = r;
				}
			}
		});

		if (ranges.length > 0) {
			var none = document.createElement('option');
			none.value = '';
			none.textContent = ranges.length + ' article' + (ranges.length === 1 ? '' : 's');
			range_select.appendChild(none);
			ranges.forEach(function (range, i) {
				var option = document.createElement('option');
				option.value = i;
				option.textContent = range.label;
				range_select.appendChild(option);
			});
			range_select.hidden = false;
		}

		observe();

		if (stored_thumbs_open() && !narrow.matches) {
			show_thumbs(true);
		}

		if (pages.some(function (page) { return page.annotation_pages.length > 0; })) {
			text_toggle.hidden = false;
		}

		if (pages.some(function (page) { return page.link; })) {
			link_toggle.hidden = false;
		}

		search_service = find_search_service(manifest);
		if (search_service) {
			search_toggle.hidden = false;
			var q = (params.get('q') || '').trim();
			if (q) {
				search_input.value = q;
				// On a phone the panel would cover the page asked for
				if (!narrow.matches) {
					show_search(true);
				}
				var asked = hash_page();
				run_search(q, asked >= 0 ? asked : undefined);
			}
		}

		var start = hash_page();
		if (start < 0) {
			start = manifest.start && by_canvas[manifest.start.id] !== undefined ? by_canvas[manifest.start.id] : 0;
		}
		go_to(start);
		set_current(Math.max(0, Math.min(start, pages.length - 1)));
	}

	//------------------------------------------------------------------------------------
	// Thumbnails

	function build_thumbs() {
		var fragment = document.createDocumentFragment();
		pages.forEach(function (page) {
			var a = document.createElement('a');
			a.className = 'viewer-thumb';
			a.href = '#page=' + (page.index + 1);
			a.dataset.index = page.index;
			if (page.range !== undefined) {
				a.title = ranges[page.range].label;
			}

			var img = document.createElement('img');
			img.loading = 'lazy';
			img.decoding = 'async';
			img.alt = '';
			if (page.width && page.height) {
				img.width = page.width;
				img.height = page.height;
			}
			if (page.thumbnail) {
				img.src = page.thumbnail;
			}
			if (page.range !== undefined) {
				img.style.borderBottomColor = 'var(--part-' + (page.range % 10) + ')';
			}
			a.appendChild(img);

			var span = document.createElement('span');
			span.textContent = page.label ? page.label.replace(/^Page /, '') : '[' + (page.index + 1) + ']';
			a.appendChild(span);

			page.thumb_el = a;
			fragment.appendChild(a);
		});
		thumbs.appendChild(fragment);
		if (current >= 0) {
			pages[current].thumb_el.classList.add('current');
		}
	}

	function show_thumbs(show) {
		if (show && !pages[0].thumb_el) {
			build_thumbs();
		}
		if (show && narrow.matches) {
			show_search(false);
			show_text(false);
		}
		thumbs.hidden = !show;
		thumbs_toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
		if (show && current >= 0) {
			pages[current].thumb_el.scrollIntoView({ block: 'center' });
		}
		// The page showing stays in view when the pages get narrower or wider
		if (current >= 0 && !narrow.matches) {
			go_to(current);
		}
		try {
			localStorage.setItem('viewer-thumbs', show ? '1' : '0');
		} catch (e) {
		}
	}

	function stored_thumbs_open() {
		try {
			return localStorage.getItem('viewer-thumbs') === '1';
		} catch (e) {
			return false;
		}
	}

	//------------------------------------------------------------------------------------
	// Search inside

	// The id of a manifest's IIIF Content Search 2.0 service, or null
	function find_search_service(manifest) {
		var services = as_array(manifest.service);
		for (var i = 0; i < services.length; i++) {
			if ((services[i].type || services[i]['@type']) === 'SearchService2' && services[i].id) {
				return services[i].id;
			}
		}
		return null;
	}

	// Open or close one of the panels at the right (search, or text), which share a
	// column; the column shows if either is open
	function show_side(panel, toggle, show) {
		if (show === !panel.hidden) {
			return false;
		}
		if (show && narrow.matches && !thumbs.hidden) {
			show_thumbs(false);
		}
		var was_open = !side.hidden;
		panel.hidden = !show;
		toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
		side.hidden = search_panel.hidden && text_panel.hidden;
		// The page showing stays in view when the pages get narrower or wider
		if (current >= 0 && !narrow.matches && was_open !== !side.hidden) {
			go_to(current);
		}
		return true;
	}

	function show_search(show) {
		if (show_side(search_panel, search_toggle, show) && show && !search_input.value) {
			search_input.focus();
		}
	}

	function show_text(show) {
		if (show_side(text_panel, text_toggle, show) && show) {
			show_page_text();
		}
	}

	// The canvas an annotation targets: a URI (maybe with a #xywh= box), or an object
	// with an id, or a SpecificResource with a source
	function target_canvas(target) {
		target = as_array(target)[0];
		if (target && typeof target === 'object') {
			target = target.source || target.id;
			if (target && typeof target === 'object') {
				target = target.id;
			}
		}
		return typeof target === 'string' ? target.replace(/#.*$/, '') : null;
	}

	// Mark a page's hits on it, in a layer under its text
	function draw_hits(page) {
		if (!page.width || !page.height) {
			return;
		}
		if (!page.hits_el) {
			page.hits_el = document.createElement('div');
			page.hits_el.className = 'viewer-hits';
			page.el.insertBefore(page.hits_el, page.img.nextSibling);
		}
		page.hits.forEach(function (hit) {
			if (hit.els) {
				return;
			}
			hit.els = hit.boxes.map(function (box) {
				var div = document.createElement('div');
				div.style.left = (box[0] / page.width * 100) + '%';
				div.style.top = (box[1] / page.height * 100) + '%';
				div.style.width = (box[2] / page.width * 100) + '%';
				div.style.height = (box[3] / page.height * 100) + '%';
				page.hits_el.appendChild(div);
				return div;
			});
		});
	}

	function clear_hits() {
		pages.forEach(function (page) {
			if (page.hits_el) {
				page.hits_el.remove();
				page.hits_el = null;
			}
			page.hits = null;
		});
		current_hit = null;
		show_page_text();
	}

	// Show a hit: mark it and its entry in the list as current, and scroll its first
	// box to a third of the way down the screen (or its page to the top)
	function go_to_hit(hit) {
		var old = scroller.querySelectorAll('.viewer-hits .current');
		for (var i = 0; i < old.length; i++) {
			old[i].classList.remove('current');
		}
		old = search_results.querySelector('.current');
		if (old) {
			old.classList.remove('current');
		}
		hit.button.classList.add('current');
		hit.button.scrollIntoView({ block: 'nearest' });
		current_hit = hit;

		// A hit with no box on the page is shown in the text
		var page = pages[hit.index];
		if (!hit.els || hit.els.length === 0) {
			go_to(hit.index);
			show_text(true);
			show_page_text();
			return;
		}
		hit.els.forEach(function (el) {
			el.classList.add('current');
		});
		var top = page.el.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop;
		scroller.scrollTop = top + page.el.clientHeight * hit.boxes[0][1] / page.height - scroller.clientHeight / 3;
		if (hit.index === current) {
			show_page_text();
		}
	}

	// Search, and list the hits as they come in, page by page. If focus is a page
	// index, its first hit is shown when it arrives.
	function run_search(q, focus) {
		var run = ++search_run;
		var hits = 0;
		var hit_pages = 0;
		var last_index = -1;
		var list = null;
		var responses = 0;

		search_results.innerHTML = '';
		clear_hits();

		// Keep the search in the URL
		if (q) {
			params.set('q', q);
		} else {
			params.delete('q');
		}
		history.replaceState(null, '', '?' + params.toString() + window.location.hash);

		if (!q) {
			search_status.textContent = '';
			return;
		}
		search_status.textContent = 'Searching…';

		var status = function (more) {
			search_status.textContent = hits === 0
				? (more ? 'Searching…' : 'Nothing found.')
				: hits + ' hit' + (hits === 1 ? '' : 's') + ' on ' + hit_pages
					+ ' page' + (hit_pages === 1 ? '' : 's') + (more ? '…' : '');
		};

		var get = function (url) {
			responses++;
			fetch(url)
				.then(function (response) {
					if (!response.ok) {
						throw new Error('HTTP ' + response.status);
					}
					return response.json();
				})
				.then(function (result) {
					if (run !== search_run) {
						return;
					}

					// Each hit's context, by the hit's id
					var quotes = {};
					as_array(result.annotations).forEach(function (annotation_page) {
						as_array(annotation_page.items).forEach(function (annotation) {
							if (annotation.motivation !== 'contextualizing' || !annotation.target) {
								return;
							}
							as_array(annotation.target.selector).forEach(function (selector) {
								if (selector.type === 'TextQuoteSelector') {
									var source = annotation.target.source;
									quotes[typeof source === 'object' ? source.id : source] = selector;
								}
							});
						});
					});

					var fragment = document.createDocumentFragment();
					as_array(result.items).forEach(function (annotation) {
						var index = by_canvas[target_canvas(annotation.target)];
						if (index === undefined) {
							return;
						}
						if (index !== last_index) {
							var li = document.createElement('li');
							li.className = 'viewer-search-page';
							var h3 = document.createElement('h3');
							h3.textContent = pages[index].label || '[' + (index + 1) + ']';
							li.appendChild(h3);
							fragment.appendChild(li);
							list = li;
							last_index = index;
							hit_pages++;
						}

						var page = pages[index];
						var hit = {
							index: index,
							boxes: as_array(annotation.target).map(target_box).filter(function (box) {
								return box && box[2] > 0 && box[3] > 0;
							})
						};
						if (!page.hits) {
							page.hits = [];
						}
						page.hits.push(hit);

						var button = document.createElement('button');
						button.type = 'button';
						button.className = 'viewer-search-hit';
						button.hit = hit;
						hit.button = button;
						var quote = quotes[annotation.id];
						hit.quote = quote;
						if (quote) {
							button.appendChild(document.createTextNode((quote.prefix ? '…' + quote.prefix : '')));
							var mark = document.createElement('mark');
							mark.textContent = quote.exact;
							button.appendChild(mark);
							button.appendChild(document.createTextNode((quote.suffix ? quote.suffix + '…' : '')));
						} else {
							button.textContent = body_text(annotation.body) || 'Hit ' + (hits + 1);
						}
						list.appendChild(button);
						hits++;
					});
					search_results.appendChild(fragment);

					// Mark the hits on their pages, and show the first on the page asked for
					var seen = {};
					as_array(result.items).forEach(function (annotation) {
						var index = by_canvas[target_canvas(annotation.target)];
						if (index !== undefined && !seen[index] && pages[index].hits) {
							seen[index] = true;
							draw_hits(pages[index]);
						}
					});
					if (seen[current]) {
						show_page_text();
					}
					if (focus !== undefined && seen[focus]) {
						go_to_hit(pages[focus].hits[0]);
						focus = undefined;
					}

					var more = result.next && result.next.id && responses < SEARCH_RESPONSES;
					status(more);
					if (more) {
						get(result.next.id);
					}
				})
				.catch(function (error) {
					if (run === search_run) {
						search_status.textContent = 'Search failed (' + error.message + ').';
					}
				});
		};

		get(search_service + (search_service.indexOf('?') < 0 ? '?' : '&') + 'q=' + encodeURIComponent(q));
	}

	//------------------------------------------------------------------------------------
	function observe() {
		// Pages within a screen's height of the view: load
		var near = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				var page = pages[entry.target.dataset.index];
				if (entry.isIntersecting) {
					page_near(page);
				} else {
					page_gone(page);
				}
			});
		}, { root: scroller, rootMargin: '100% 0px 100% 0px' });

		// Pages more than five screens away: unload
		var far = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) {
					page_far(pages[entry.target.dataset.index]);
				}
			});
		}, { root: scroller, rootMargin: '500% 0px 500% 0px' });

		// The page across the middle of the view is the current page
		var middle = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					set_current(parseInt(entry.target.dataset.index, 10));
				}
			});
		}, { root: scroller, rootMargin: '-50% 0px -50% 0px' });

		pages.forEach(function (page) {
			near.observe(page.el);
			far.observe(page.el);
			middle.observe(page.el);
		});
	}

	//------------------------------------------------------------------------------------
	thumbs_toggle.addEventListener('click', function () {
		if (pages.length > 0) {
			show_thumbs(thumbs.hidden);
		}
	});

	thumbs.addEventListener('click', function (event) {
		var a = event.target.closest('.viewer-thumb');
		if (!a) {
			return;
		}
		event.preventDefault();
		go_to(parseInt(a.dataset.index, 10));
		if (narrow.matches) {
			show_thumbs(false);
		}
	});

	search_toggle.addEventListener('click', function () {
		show_search(search_panel.hidden);
	});

	text_toggle.addEventListener('click', function () {
		show_text(text_panel.hidden);
	});

	search_form.addEventListener('submit', function (event) {
		event.preventDefault();
		run_search(search_input.value.trim());
	});

	search_results.addEventListener('click', function (event) {
		var button = event.target.closest('.viewer-search-hit');
		if (!button) {
			return;
		}
		go_to_hit(button.hit);
		if (narrow.matches) {
			show_search(false);
		}
	});

	link_toggle.addEventListener('click', function () {
		show_link_panel(link_panel.hidden);
	});

	link_copy.addEventListener('click', function () {
		var done = function () {
			link_copy.textContent = 'Copied';
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(link_url.value).then(done, function () {
				link_url.select();
			});
		} else {
			link_url.select();
			if (document.execCommand('copy')) {
				done();
			}
		}
	});

	// Closes on Escape, or a click elsewhere
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && !link_panel.hidden) {
			show_link_panel(false);
			link_toggle.focus();
		}
	});

	document.addEventListener('mousedown', function (event) {
		if (!link_panel.hidden && !link_panel.contains(event.target) && !link_toggle.contains(event.target)) {
			show_link_panel(false);
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && narrow.matches) {
			if (!thumbs.hidden) {
				show_thumbs(false);
			}
			show_search(false);
			show_text(false);
		}
	});

	page_select.addEventListener('change', function () {
		go_to(parseInt(page_select.value, 10));
	});

	range_select.addEventListener('change', function () {
		if (range_select.value !== '') {
			go_to(ranges[parseInt(range_select.value, 10)].first);
		}
	});

	window.addEventListener('hashchange', function () {
		var index = hash_page();
		if (index >= 0 && index !== current) {
			go_to(index);
		}
	});

	window.addEventListener('message', function (event) {
		var data = event.data;
		if (!data || typeof data !== 'object') {
			return;
		}
		if (data.goto !== undefined) {
			go_to(parseInt(data.goto, 10) - 1);
		} else if (data.canvas !== undefined && by_canvas[data.canvas] !== undefined) {
			go_to(by_canvas[data.canvas]);
		}
	});

	//------------------------------------------------------------------------------------
	var manifest_url = params.get('manifest');

	if (!manifest_url) {
		show_message('Give a IIIF manifest as ?manifest=, e.g. viewer.html?manifest=testsets/manifests/61794.json');
		return;
	}

	// A manifest from this site (iiif.php?part=… or ?item=…) has a metadata page,
	// ./?part=… or ./?item=…, which the "i" link goes to
	(function () {
		var info = document.getElementById('viewer-info');
		var url;
		try {
			url = new URL(manifest_url, window.location.href);
		} catch (e) {
			return;
		}
		if (url.origin !== window.location.origin) {
			return;
		}
		['part', 'item'].some(function (key) {
			var id = url.searchParams.get(key);
			if (id && /^\d+$/.test(id)) {
				var what = key === 'part' ? 'article' : 'item';
				info.href = './?' + key + '=' + id;
				info.title = 'About this ' + what;
				info.setAttribute('aria-label', 'About this ' + what);
				info.hidden = false;
				return true;
			}
			return false;
		});
	})();

	fetch(manifest_url)
		.then(function (response) {
			if (!response.ok) {
				throw new Error('HTTP ' + response.status);
			}
			return response.json();
		})
		.then(build)
		.catch(function (error) {
			show_message('Could not load the manifest ' + manifest_url + ' (' + error.message + ').');
		});

})();
