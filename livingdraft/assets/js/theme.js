/**
 * Living Draft — the entire client-side runtime.
 * No framework, no jQuery, no dependencies. Runs deferred.
 */
(function () {
	'use strict';

	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

	function close(button, panel, openClass, refocus) {
		if (!panel || !panel.classList.contains(openClass)) {
			return;
		}
		panel.classList.remove(openClass);
		if (button) {
			button.setAttribute('aria-expanded', 'false');
			if (refocus) {
				button.focus();
			}
		}
	}

	function wire(button, panel, openClass) {
		if (!button || !panel) {
			return null;
		}

		button.addEventListener('click', function () {
			var open = panel.classList.toggle(openClass);
			button.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) {
				var first = panel.querySelector(FOCUSABLE);
				if (first) {
					first.focus();
				}
			}
		});

		// Tab must not walk out of an open drawer and into the page behind it.
		panel.addEventListener('keydown', function (event) {
			if (event.key !== 'Tab' || !panel.classList.contains(openClass)) {
				return;
			}

			var items = Array.prototype.filter.call(
				panel.querySelectorAll(FOCUSABLE),
				function (el) { return el.offsetParent !== null; }
			);

			if (!items.length) {
				return;
			}

			var first = items[0];
			var last = items[items.length - 1];

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});

		return { button: button, panel: panel, openClass: openClass };
	}

	var menu = wire(
		document.querySelector('.menu-toggle'),
		document.getElementById('nav-panel'),
		'is-open'
	);

	var search = wire(
		document.querySelector('.search-toggle'),
		document.getElementById('search-drawer'),
		'is-open'
	);

	var panels = [menu, search].filter(Boolean);

	// Escape closes whatever is open and returns focus to its button.
	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') {
			return;
		}
		panels.forEach(function (p) {
			close(p.button, p.panel, p.openClass, true);
		});
	});

	// So does a click anywhere outside.
	document.addEventListener('click', function (event) {
		panels.forEach(function (p) {
			if (p.panel.contains(event.target) || p.button.contains(event.target)) {
				return;
			}
			close(p.button, p.panel, p.openClass, false);
		});
	});

	// Reset the mobile drawer when the layout goes wide, so it cannot get stuck.
	var wide = window.matchMedia('(min-width: 900px)');
	function syncMenu() {
		if (wide.matches && menu) {
			close(menu.button, menu.panel, menu.openClass, false);
		}
	}
	if (wide.addEventListener) {
		wide.addEventListener('change', syncMenu);
	}
	syncMenu();

	// A wide table scrolls inside its own box rather than stretching the page.
	var tables = document.querySelectorAll('.entry-content > table, .entry-content > .wp-block-table');
	Array.prototype.forEach.call(tables, function (table) {
		if (table.parentNode && table.parentNode.classList.contains('table-scroll')) {
			return;
		}
		var box = document.createElement('div');
		box.className = 'table-scroll';
		box.setAttribute('tabindex', '0');
		box.setAttribute('role', 'region');
		box.setAttribute('aria-label', table.getAttribute('summary') || 'Table');
		table.parentNode.insertBefore(box, table);
		box.appendChild(table);
	});
}());

/**
 * Living Draft — front page strip and tabs.
 * Appended as a second block so the drawer logic above stays self-contained.
 */
(function () {
	'use strict';

	/* ---- The latest-stories strip ------------------------------------ */

	var rail = document.querySelector('.strip-rail');
	if (rail) {
		function step() {
			var slide = rail.querySelector('.slide');
			return slide ? slide.getBoundingClientRect().width + 22 : rail.clientWidth * 0.8;
		}

		function nudge(direction) {
			rail.scrollBy({ left: direction * step(), behavior: 'smooth' });
		}

		var prev = document.querySelector('.strip-prev');
		var next = document.querySelector('.strip-next');

		if (prev) { prev.addEventListener('click', function () { nudge(-1); }); }
		if (next) { next.addEventListener('click', function () { nudge(1); }); }

		rail.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowRight') { event.preventDefault(); nudge(1); }
			if (event.key === 'ArrowLeft') { event.preventDefault(); nudge(-1); }
		});

		// Grey out a button when there is nothing more that way.
		function sync() {
			var atStart = rail.scrollLeft < 6;
			var atEnd = rail.scrollLeft + rail.clientWidth >= rail.scrollWidth - 6;
			if (prev) { prev.disabled = atStart; }
			if (next) { next.disabled = atEnd; }
		}
		rail.addEventListener('scroll', sync, { passive: true });
		window.addEventListener('resize', sync);
		sync();
	}

	/* ---- Latest / Popular / Trending --------------------------------- */

	var tablist = document.querySelector('.tablist');
	if (!tablist) {
		return;
	}

	var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));

	function show(tab) {
		tabs.forEach(function (other) {
			var panel = document.getElementById(other.getAttribute('aria-controls'));
			var on = other === tab;

			other.setAttribute('aria-selected', on ? 'true' : 'false');
			other.setAttribute('tabindex', on ? '0' : '-1');
			other.classList.toggle('is-on', on);

			if (panel) {
				panel.hidden = !on;
				panel.classList.toggle('is-on', on);
			}
		});
	}

	tablist.addEventListener('click', function (event) {
		var tab = event.target.closest('[role="tab"]');
		if (tab) { show(tab); }
	});

	tablist.addEventListener('keydown', function (event) {
		var at = tabs.indexOf(document.activeElement);
		if (at < 0) { return; }

		var to = null;
		if (event.key === 'ArrowRight') { to = tabs[(at + 1) % tabs.length]; }
		if (event.key === 'ArrowLeft') { to = tabs[(at - 1 + tabs.length) % tabs.length]; }
		if (event.key === 'Home') { to = tabs[0]; }
		if (event.key === 'End') { to = tabs[tabs.length - 1]; }

		if (to) {
			event.preventDefault();
			to.focus();
			show(to);
		}
	});
}());
