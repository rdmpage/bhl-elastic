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
// Thumbnails of all pages, for moving around fast, are built the first time they are
// shown: beside the pages on wide screens, following the page showing, and over them on
// phones, closing when a page is chosen. Thumbnails of pages in an article have a bar
// in its colour, as on item pages.
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

	var pages   = [];   // { canvas, label, thumbnail, image, service, el, img, ... }
	var ranges  = [];   // { label, first, last } as page indexes
	var current = -1;

	// How long a page must be in view before its full image is fetched (ms)
	var DWELL = 150;

	// Widths to fetch from an image service, so that cached images get reused
	var WIDTHS = [400, 600, 800, 1000, 1200, 1600, 2000, 2400, 3200];

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

	// Page is far from the screen: free its full image
	function page_far(page) {
		page_gone(page);
		page.loading = null;
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
	function go_to(index) {
		if (index >= 0 && index < pages.length) {
			pages[index].el.scrollIntoView({ block: 'start' });
		}
	}

	function hash_page() {
		var m = window.location.hash.match(/page=(\d+)/);
		return m ? parseInt(m[1], 10) - 1 : 0;
	}

	//------------------------------------------------------------------------------------
	function build(manifest) {
		var title = label_text(manifest.label) || '';
		document.title = title || 'Viewer';
		document.getElementById('viewer-title').textContent = title;

		var by_canvas = {};
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

		var start = hash_page();
		if (start === 0 && manifest.start && by_canvas[manifest.start.id] !== undefined) {
			start = by_canvas[manifest.start.id];
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

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && !thumbs.hidden && narrow.matches) {
			show_thumbs(false);
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
		if (index !== current) {
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
		} else if (data.canvas !== undefined) {
			for (var i = 0; i < pages.length; i++) {
				if (pages[i].canvas === data.canvas) {
					go_to(i);
					break;
				}
			}
		}
	});

	//------------------------------------------------------------------------------------
	var manifest_url = params.get('manifest');

	if (!manifest_url) {
		show_message('Give a IIIF manifest as ?manifest=, e.g. viewer.html?manifest=testsets/manifests/61794.json');
		return;
	}

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
