/**
 * Living Draft — site analytics tracker.
 *
 * One small request per page, after the page has loaded, plus an
 * engagement ping when the reader leaves or hides the tab. Works on cached
 * pages, uses no cookies, loads nothing from anyone else.
 *
 *   ld_vid  random visitor id (localStorage)   → unique visitors
 *   ld_sid  random session id + last-activity  → sessions (30 min gap)
 *   ld_ssrc where the session came from        → channel on every page
 *
 * A browser can be excluded (staff, testing) with ?ld_optout=1 and put
 * back with ?ld_optout=0. Staff browsers are excluded automatically.
 */
(function () {
	'use strict';

	var cfg = window.ldTracker;
	if (!cfg || !cfg.hit) {
		return;
	}

	var ls = null;
	try {
		ls = window.localStorage;
		ls.setItem('ld_t', '1');
		ls.removeItem('ld_t');
	} catch (e) {
		ls = null;
	}

	function get(k) { try { return ls ? ls.getItem(k) : null; } catch (e) { return null; } }
	function set(k, v) { try { if (ls) { ls.setItem(k, v); } } catch (e) {} }

	// Opt-out switch.
	var q = window.location.search;
	if (/[?&]ld_optout=1/.test(q)) { set('ld_optout', '1'); }
	if (/[?&]ld_optout=0/.test(q)) { try { ls && ls.removeItem('ld_optout'); } catch (e) {} }
	if (get('ld_optout') === '1') {
		return;
	}

	function rid() {
		var a = new Uint8Array(8);
		(window.crypto || window.msCrypto).getRandomValues(a);
		return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}

	var SESSION_GAP = 30 * 60 * 1000;
	var now = Date.now();

	// ---- Visitor ------------------------------------------------------
	var vid = get('ld_vid');
	var isNew = false;
	if (!vid || !/^[a-f0-9]{16}$/.test(vid)) {
		vid = ls ? rid() : '';
		isNew = !!ls;
		set('ld_vid', vid);
	}

	// ---- Where this visit came from -----------------------------------
	function param(name) {
		var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(q);
		return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')).slice(0, 150) : '';
	}

	var ref = document.referrer || '';
	var external = false;
	if (ref) {
		try { external = new URL(ref).hostname.replace(/^www\./, '') !== location.hostname.replace(/^www\./, ''); }
		catch (e) { external = true; }
	}

	var utm = { source: param('utm_source'), medium: param('utm_medium'), campaign: param('utm_campaign') };
	// Click ids without UTM tags still tell us it was paid.
	if (!utm.medium && (param('gclid') || param('msclkid'))) {
		utm.medium = 'cpc';
		utm.source = utm.source || (param('gclid') ? 'google' : 'bing');
	}
	var hasCampaign = !!(utm.source || utm.medium || utm.campaign);

	// ---- Session ------------------------------------------------------
	var sid = get('ld_sid');
	var last = parseInt(get('ld_sts') || '0', 10);
	var isEntry = false;

	var newSrc = JSON.stringify({ r: external ? ref : '', utm: utm });
	// A reload keeps document.referrer, so only a DIFFERENT source counts
	// as a new arrival.
	var arrived = (external || hasCampaign) && newSrc !== get('ld_ssrc');

	if (!sid || !/^[a-f0-9]{16}$/.test(sid) || isNaN(last) || now - last > SESSION_GAP || arrived) {
		// A new arrival from outside, or a campaign link, starts a new
		// session even mid-visit — the same rule Google Analytics uses.
		sid = ls ? rid() : '';
		isEntry = true;
		set('ld_sid', sid);
		set('ld_ssrc', newSrc);
	}
	set('ld_sts', String(now));

	var src = { r: '', utm: {} };
	try { src = JSON.parse(get('ld_ssrc') || '{}') || src; } catch (e) {}
	if (!ls) { src = { r: external ? ref : '', utm: utm }; isEntry = true; }

	// ---- Per-article counter (views.php) --------------------------------
	var countView = false;
	if (cfg.count && cfg.post) {
		var key = 'ld-read-' + cfg.post;
		var seen = parseInt(get(key) || '0', 10);
		countView = !(seen && now - seen >= 0 && now - seen < cfg.window * 1000);
		if (countView) { set(key, String(now)); }
	}

	var uid = rid();

	function send(url, payload) {
		var body = JSON.stringify(payload);
		if (navigator.sendBeacon) {
			try {
				if (navigator.sendBeacon(url, new Blob([body], { type: 'text/plain;charset=UTF-8' }))) {
					return;
				}
			} catch (e) {}
		}
		if (window.fetch) {
			fetch(url, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain;charset=UTF-8' } })
				.catch(function () {});
		}
	}

	function hit() {
		send(cfg.hit, {
			u: uid,
			v: vid,
			s: sid,
			n: isNew ? 1 : 0,
			e: isEntry ? 1 : 0,
			p: cfg.post || 0,
			k: countView ? 1 : 0,
			path: location.pathname,
			r: src.r || '',
			utm: src.utm || {},
			w: window.screen ? window.screen.width : 0
		});
		startEngagement();
	}

	// ---- Engagement ---------------------------------------------------
	// Time counts only while the tab is visible AND the reader has done
	// something (scroll, key, pointer, touch) in the last 30 seconds.
	var engagedMs = 0, tickFrom = 0, lastInput = Date.now(), maxScroll = 0, lastSent = -1;
	var IDLE = 30000;

	function active() {
		return document.visibilityState === 'visible' && Date.now() - lastInput < IDLE;
	}
	function tick() {
		var t = Date.now();
		if (tickFrom && active()) { engagedMs += Math.min(t - tickFrom, 5000); }
		tickFrom = t;
	}
	function scrollDepth() {
		var doc = document.documentElement, body = document.body;
		var h = Math.max(doc.scrollHeight, body ? body.scrollHeight : 0) - window.innerHeight;
		var pct = h > 0 ? Math.round(100 * (window.scrollY || doc.scrollTop) / h) : 100;
		if (pct > maxScroll) { maxScroll = Math.min(100, pct); }
	}
	function ping() {
		tick();
		var secs = Math.round(engagedMs / 1000);
		if (secs === lastSent && maxScroll === 0) { return; }
		lastSent = secs;
		send(cfg.ping, { u: uid, t: secs, d: maxScroll });
	}
	function input() { lastInput = Date.now(); set('ld_sts', String(lastInput)); }

	function startEngagement() {
		tickFrom = Date.now();
		setInterval(tick, 1000);
		['scroll', 'keydown', 'pointerdown', 'pointermove', 'touchstart', 'wheel'].forEach(function (ev) {
			window.addEventListener(ev, function () { input(); if (ev === 'scroll') { scrollDepth(); } }, { passive: true });
		});
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'hidden') { ping(); } else { tickFrom = Date.now(); input(); }
		});
		window.addEventListener('pagehide', ping);
		// Long reads: report progress every minute so a crashed tab still counts.
		setInterval(function () { if (active()) { ping(); } }, 60000);
		scrollDepth();
	}

	// ---- Go, once the page has settled and is actually being seen -------
	function go() {
		if (document.visibilityState === 'prerender' || document.prerendering) {
			document.addEventListener('prerenderingchange', go, { once: true });
			return;
		}
		window.setTimeout(hit, 800);
	}

	if (document.readyState === 'complete') { go(); } else { window.addEventListener('load', go); }
})();
