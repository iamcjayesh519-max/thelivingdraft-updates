<?php
/**
 * Plugin Name: The Living Draft Core
 * Plugin URI:  https://thelivingdraft.com
 * Description: The parts of this site that must survive a theme change: the update log, editorial labels, second bylines, view counting, newsletter subscribers, cookie consent, the article blocks, redirections, custom CSS, plus a full AI-powered SEO stack (metabox, sitemaps, schema, Rank Math bridge) and Google Search Console integration.
 * Version:     4.6.0
 * Author:      The Living Draft
 * License:     GPL-2.0-or-later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: livingdraft-core
 * Requires PHP: 8.2
 * Requires at least: 6.2
 * Tested up to: 6.9
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 *
 * A theme decides how a site looks. A plugin decides what a site does.
 * Corrections on the record are something this site DOES, so they belong here.
 * Change the theme tomorrow and every logged correction stays exactly where
 * it is.
 * ---------------------------------------------------------------------------
 *
 * === v3.1 — Agents removed ===
 *
 * The Agents system (v3.0) and the older v2.x wire pipeline have both been
 * removed. The AI provider layer stays — that's what powers the "Generate"
 * and "Fix with AI" buttons in the SEO metabox, the vision-based image alt
 * generation, content briefs, and internal-link embeddings. Bring-your-own-
 * key configuration continues to live under The Living Draft → Settings → AI.
 *
 * === v3.8.0 — "The Desk" theme ===
 *
 * Visual refresh of the admin. Editorial newspaper identity applied to
 * every plugin surface: warmer cream paper, Fraunces variable serif
 * (self-hosted, ~82 KB) for the masthead and card titles, larger 40px
 * page heading, section-sign watermark at 8% opacity, softer hairline
 * rules, tabular numerals on all figures.
 *
 * New components (all additive — every existing class hook still works):
 *   • .tld-numbers        — ledger-style stat row on the dashboard
 *   • .tld-masthead-strip — dateline strip for landing pages
 *   • .tld-section-rule   — heading with a black bottom rule
 *   • .tld-subtabs        — module sub-nav (consolidates three inline copies)
 *   • .tld-notice         — plugin-owned notices, in-shell
 *   • .tld-editor-note    — closing quote strip on every page
 *   • .tld-kicker         — ALL-CAPS story-kind tags (Correction, 404 spike, …)
 *
 * All tab and menu labels are unchanged. Purely a visual + structural
 * refresh — no data migration, no schema change, no breaking API.
 *
 * === v4.1.0 — The Desk removed ===
 *
 * The Desk (v4.0.0) wrote drafts on a schedule from clustered wire copy. It
 * worked, in the narrow sense that it produced anchored, sourced, never-
 * published drafts. It is gone anyway, for a reason worth writing down:
 *
 * A draft is not the expensive part of a story. The structure is — which
 * paragraph carries the news, where the context sits, when the standfirst
 * earns its line, how a correction reads. That structure is house style, and
 * house style is not something a scheduled job infers from a voice profile
 * and four wire summaries. Every Desk draft had to be rebuilt into the house
 * shape before it could run, which is more work than starting from the
 * sources directly.
 *
 * So the writing goes back to the desk that a person sits at. What stays is
 * everything that helps a person write: the SEO stack, content briefs,
 * internal links, the fact-check panel, timelines, and the AI provider layer
 * behind the per-field Generate buttons. Those assist a draft. They do not
 * author one.
 *
 * Removed: inc/desk/ (desk.php, desk-sources.php, desk-images.php,
 * desk-writer.php, desk-admin.php), the Desk submenu and tab, the
 * livingdraft_desk_tick cron event, and the four livingdraft_desk_* options.
 * Drafts the Desk already created are untouched, including their
 * _ld_desk_origin source provenance.
 *
 * === v4.2.0 — Listen ===
 *
 * A "Listen" control on articles, spoken by the reader's own browser through
 * the Web Speech API. No audio files, no API key, no per-article cost, and
 * nothing leaves the reader's device. The voice is worse than a paid
 * text-to-speech service and always current, which is the right way round
 * for a site that publishes corrections: a correction made at 4pm is spoken
 * at 4:01, with no queue to regenerate.
 *
 * inc/listen/listen.php documents the seam — livingdraft_listen_audio_url()
 * — at which that decision can be reversed without touching anything else.
 *
 * === v4.3.0 — Mail ===
 *
 * Sending, properly. A transport layer (SMTP or the Brevo / Resend APIs) with
 * credentials encrypted at rest, a subscriber consent lifecycle with double
 * opt-in and RFC 8058 one-click unsubscribe, a batched campaign queue that
 * survives a killed cron run without sending anyone the same newsletter
 * twice, and AI drafting for newsletters, subject lines and replies.
 *
 * Three lines this module does not cross, each for a stated reason in the
 * file that enforces it:
 *
 *   - No mailbox is read. Receiving mail would mean storing the password to
 *     a real email account in wp_options and polling it on cron, putting
 *     full inbox access behind the site's weakest admin password. Reply
 *     drafting takes pasted text instead.
 *
 *   - No open or click tracking. A tracking pixel records that a named
 *     address read a named email at a named time, on a site that asks for
 *     consent before counting a page view.
 *
 *   - Nothing sends itself. mail-ai.php has no code path to
 *     livingdraft_campaign_start(). A newsletter goes out because a person
 *     pressed a button on a screen showing the recipient count.
 *
 * === v4.4.0 — One design system, one navigation, one settings screen ===
 *
 * Not a repaint. The palette, the typography and the flat editorial identity
 * are unchanged — they were already coherent. What was wrong was that only
 * about half the plugin used them.
 *
 * Mail, Listen, Redirections and Fact check were built on WordPress's own
 * form-table / widefat / button-primary components and looked like a
 * different product sitting next to the SEO panel. All four are now on the
 * .tld-* system. Raw WordPress component count across those screens: 44 to 0.
 *
 * The duplicate tab strip is gone. Every page printed a row of links to the
 * same six destinations the WordPress sidebar was already showing, three
 * inches to the left.
 *
 * Settings were in four unrelated places — AI under SEO, updates on their own
 * page, Listen bolted to the bottom of Settings, Mail on a sub-tab of Mail.
 * They are now one screen with a rail, fed by the livingdraft_settings_panels
 * filter. Old bookmarks to SEO → AI redirect.
 *
 * The fact-check panel in the editor no longer renders WordPress's #2271b1
 * blue in the middle of a warm editorial palette.
 *
 * === v4.5.0 — Forms ===
 *
 * A contact form, a message store, and the fix for a bug that had been
 * sitting in plain sight: the theme's newsletter signup bar had correct
 * markup, a correct nonce and a correct endpoint, and nothing had ever
 * submitted it. No JavaScript in either the theme or the plugin bound a
 * submit handler to it, and the form carried no action field, so pressing
 * Subscribe posted nowhere. The list stayed empty in a way that looked like
 * nobody wanted to subscribe.
 *
 * The handler lives here rather than in the theme, because subscribers are
 * data and not presentation. forms.js binds to the theme's own
 * .ld-signup-form class, so the existing bar starts working on activation
 * with no theme edit.
 *
 * The contact form stores every message before it mails anything. A form
 * that only emails loses the message when the mail fails, and shows the
 * reader a thank-you while doing it.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHP version guard.
 *
 * From v3.2 the plugin requires PHP 8.2 or higher (the currently-active
 * PHP releases per php.net). On older PHP we bail with an admin notice
 * instead of loading the code and dying with a syntax error somewhere
 * deep in the require chain — a bad experience for anyone still on 7.4
 * or 8.0/8.1.
 */
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>The Living Draft Core</strong> requires PHP 8.2 or higher. You are running PHP ' . esc_html( PHP_VERSION ) . '. Update PHP on your host, or install the previous plugin version (3.1.x) which supports PHP 7.4+.</p></div>';
	} );
	return;
}

define( 'LIVINGDRAFT_CORE_VERSION', '4.6.0' );
define( 'LIVINGDRAFT_CORE_FILE', __FILE__ );
define( 'LIVINGDRAFT_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIVINGDRAFT_CORE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load the parts.
 *
 * These are guarded with function_exists() inside each file, so if an older
 * copy of the theme still carries its own version, the site will not die with
 * a "cannot redeclare function" error. The plugin always wins.
 */
require_once LIVINGDRAFT_CORE_DIR . 'inc/updates.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo-bridge.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/views.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/newsletter.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/consent.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/content-schema.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/editor-blocks.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/update-channel.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/redirects.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/redirects-import.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/custom.php';

// AI provider — the bring-your-own-key layer used by every AI feature in the
// SEO stack (Generate, Suggest, Fix-with-AI, image alt, content briefs,
// internal-link embeddings). This is intentionally loaded before the SEO
// files so livingdraft_ai_* functions are defined by the time they are
// referenced.
require_once LIVINGDRAFT_CORE_DIR . 'inc/ai/ai-provider.php';

// SEO stack: meta box, front-end output, content analyzer, AJAX bridge.
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/seo-meta.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/term-meta.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/seo-output.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/content-analysis.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/seo-ajax.php';

// SEO discovery: XML + News sitemaps, IndexNow pings on publish.
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/sitemap.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/indexnow.php';

// SEO structured data: NewsArticle + Organization + WebSite +
// BreadcrumbList as a single @graph. FAQ/Rating from content-schema.php
// continue emitting their own blocks alongside.
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/schema.php';

// Vision AI: alt-text generation from image pixels via any of the three
// providers. Adds a button to every attachment edit form and a batch
// runner to the SEO metabox.
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/ai-image-alt.php';

// Content brief workspace: structured research briefs for any keyword.
// Optionally spins up a WP draft post with the outline as H2s and the
// SEO fields pre-populated.
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/content-brief.php';

// Internal link suggestions via embeddings: per-post embedding storage,
// cosine-similarity search, related-posts strip in the SEO metabox,
// batch reindexer under SEO → Links.
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/internal-links.php';

// v3.3.0: additional SEO modules.
//   verification         — Site verification meta tags (Google Search
//                          Console, Bing, Yandex, Pinterest, Baidu,
//                          Facebook domain verification).
//   attachment-redirect  — 301 attachment pages to their parent post to
//                          eliminate the duplicate-content trap WP creates
//                          by giving every uploaded file its own URL.
//   rss-footer           — Append a canonical-link footer to every RSS
//                          item so scrapers can't outrank the original.
//   post-list-columns    — SEO score + focus keyword column on the
//                          Posts and Pages admin lists. Sortable.
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/verification.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/attachment-redirect.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/rss-footer.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/post-list-columns.php';

// v4.0.0: fact checking against Google's Fact Check Tools API. Searches
// published fact-checks for claims in a draft. It does not verify facts —
// see the long note at the top of the file for what it does and, more
// importantly, what it refuses to do.
require_once LIVINGDRAFT_CORE_DIR . 'inc/factcheck/factcheck.php';

// v4.0.0: story timelines. Groups articles into the running news stories
// they belong to, from the tags the newsroom already writes, and hands the
// grouping to the theme to draw at the foot of each article. Membership is
// a fact about the journalism, so it lives here and survives a theme change.
require_once LIVINGDRAFT_CORE_DIR . 'inc/timeline/timeline.php';

// v4.1.0: The Desk (scheduled drafting) was removed here. See the note at
// the top of this file. The story-clustering helper it borrowed from
// timeline.php is still used by the timeline itself, so nothing below moved.

// v4.2.0: Listen — a text-to-speech control on articles, spoken by the
// reader's own device. See the header of listen.php for why it is the
// browser's synthesiser and not a paid API.
require_once LIVINGDRAFT_CORE_DIR . 'inc/listen/listen.php';

// v4.3.0: Mail — transport (SMTP / Brevo / Resend), subscriber consent
// lifecycle, campaign queue, and AI drafting. Loaded after newsletter.php
// because the consent module hooks the signup action that file fires.
require_once LIVINGDRAFT_CORE_DIR . 'inc/mail/mail.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/mail/mail-verify.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/mail/mail-subscribers.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/mail/mail-campaigns.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/mail/mail-ai.php';

// v4.5.0: Forms — the contact form, the message store, and the submit
// handler the theme's newsletter bar has been missing since it shipped.
// Loaded after mail.php: notifications go out over the configured
// transactional channel.
require_once LIVINGDRAFT_CORE_DIR . 'inc/forms/forms.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/forms/forms-contact.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/forms/forms-subscribe.php';

// Google Search Console integration: per-post performance metrics
// (clicks, impressions, CTR, position) in the metabox, plus
// auto-submission of new URLs to the Google Indexing API with bulk
// backfill support.
require_once LIVINGDRAFT_CORE_DIR . 'inc/gsc/gsc.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/gsc/gsc-hooks.php';
require_once LIVINGDRAFT_CORE_DIR . 'inc/gsc/gsc-diagnostics.php';

// v1.5.0 admin shell — top-level menu, dashboard, shared header renderer.
// Loaded last so all module functions are already defined.
if ( is_admin() ) {
	require_once LIVINGDRAFT_CORE_DIR . 'inc/admin/menu.php';
	require_once LIVINGDRAFT_CORE_DIR . 'inc/ai/ai-admin.php';
	require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/seo-admin.php';
	require_once LIVINGDRAFT_CORE_DIR . 'inc/gsc/gsc-admin.php';
	// v3.7.0: SEO Desk — coverage dashboard, term list columns,
	// bulk AI generator for category/tag meta.
	require_once LIVINGDRAFT_CORE_DIR . 'inc/seo/seo-desk.php';
	// v4.0.0: the timeline review screen.
	require_once LIVINGDRAFT_CORE_DIR . 'inc/timeline/timeline-admin.php';
	require_once LIVINGDRAFT_CORE_DIR . 'inc/factcheck/factcheck-metabox.php';
	require_once LIVINGDRAFT_CORE_DIR . 'inc/listen/listen-admin.php';
	require_once LIVINGDRAFT_CORE_DIR . 'inc/mail/mail-admin.php';
	require_once LIVINGDRAFT_CORE_DIR . 'inc/forms/forms-admin.php';
}

/**
 * Activation.
 *
 * The Agents system (v3.0), the wire pipeline (v2.x) and the Desk (v4.0)
 * have all been removed. Their cron hooks may still be scheduled on sites
 * that are upgrading from an earlier version — clearing them on activation
 * is the safe way to make sure WP-Cron doesn't keep firing events with no
 * listeners.
 */
function livingdraft_core_activate() {
	// Retired events only. Current ones reschedule themselves on init.
	foreach ( array( 'ld_agents_heartbeat', 'ld_agent_cron_run', 'ld_agent_pipeline_tick', 'livingdraft_daily_decay', 'livingdraft_desk_tick' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}
register_activation_hook( __FILE__, 'livingdraft_core_activate' );

/**
 * Deactivation.
 *
 * Also clears the retired agent cron hooks — belt and braces for sites
 * that never ran the v3.1 activation path (e.g. plugin was replaced by
 * unzipping over the old copy without re-activating).
 */
function livingdraft_core_deactivate() {
	foreach ( livingdraft_core_cron_hooks() as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

/**
 * Every scheduled event this plugin owns, current and retired.
 *
 * === WHY THIS IS ONE LIST ===
 *
 * Cron cleanup was previously written out by hand in each module, and two
 * events were missed: livingdraft_gsc_daily_refresh and, once it was added,
 * livingdraft_timeline_daily. Both stayed in WordPress's cron array after
 * deactivation, firing on schedule with nothing listening — invisible, but
 * an entry that never goes away and that a site owner has no way to find.
 *
 * A hand-maintained list in five files will always drift. One list, walked on
 * deactivation, is the only version that stays correct: adding a new
 * scheduled event means adding one line here, and forgetting to is the kind
 * of omission a reader notices.
 *
 * @since 4.0.0
 * @return string[]
 */
function livingdraft_core_cron_hooks() {
	return array(
		// Current.
		'livingdraft_views_nightly',
		'livingdraft_timeline_daily',
		'livingdraft_gsc_daily_refresh',
		'livingdraft_mail_queue',

		// Retired, cleared so upgrades from older versions leave nothing
		// behind. See the v3.1 note above on the Agents system.
		'livingdraft_desk_tick',
		'livingdraft_daily_decay',
		'ld_agents_heartbeat',
		'ld_agent_cron_run',
		'ld_agent_pipeline_tick',
	);
}
register_deactivation_hook( __FILE__, 'livingdraft_core_deactivate' );

/**
 * Safety net for existing installs: even if activation never fires (because
 * the plugin file is simply overwritten in place), an admin page load will
 * clear any lingering agent cron hooks. Cheap — one option lookup — and
 * bounded to the admin so it never touches a front-end response.
 */
function livingdraft_core_clear_retired_cron_events() {
	if ( ! is_admin() ) {
		return;
	}
	if ( get_option( 'livingdraft_core_retired_cron_cleared' ) === LIVINGDRAFT_CORE_VERSION ) {
		return;
	}
	foreach ( array( 'ld_agents_heartbeat', 'ld_agent_cron_run', 'ld_agent_pipeline_tick', 'livingdraft_desk_tick' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	/*
	 * v4.1.0: the Desk's own storage. Nothing reads these any more, and two
	 * of them grow without bound (the run log and the seen-fingerprint
	 * list), so leaving them would be leaving rows that no screen can ever
	 * show and no admin would know to look for.
	 *
	 * Drafts the Desk already produced are NOT touched. They are ordinary
	 * posts; only the "Desk draft" label beside them in the posts list is
	 * gone. Their _ld_desk_origin meta is left in place deliberately — it
	 * records which sources a draft was built from, which is exactly the
	 * kind of provenance this site does not delete.
	 */
	foreach ( array(
		'livingdraft_desk_settings',
		'livingdraft_desk_log',
		'livingdraft_desk_voice',
		'livingdraft_desk_seen',
	) as $option ) {
		delete_option( $option );
	}

	update_option( 'livingdraft_core_retired_cron_cleared', LIVINGDRAFT_CORE_VERSION, false );
}
add_action( 'admin_init', 'livingdraft_core_clear_retired_cron_events' );

/**
 * If the display side is missing — because the active theme is not Living
 * Draft — print the update log at the end of the story anyway, so the record
 * is never silently lost.
 *
 * @param string $content Post content.
 * @return string
 */
function livingdraft_core_fallback_log( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	// The Living Draft theme prints its own, properly placed under the byline.
	if ( function_exists( 'livingdraft_theme_prints_update_log' ) ) {
		return $content;
	}

	$updates = livingdraft_get_updates( get_the_ID() );
	if ( empty( $updates ) ) {
		return $content;
	}

	$kinds = livingdraft_update_kinds();

	$out  = '<section class="livingdraft-update-log">';
	$out .= '<h2>' . esc_html__( 'Updates to this story', 'livingdraft-core' ) . '</h2><ol>';

	foreach ( $updates as $row ) {
		// Guard each row against a missing 'kind' — historical data may
		// carry rows written before the kinds vocabulary was locked down.
		$kind_key   = isset( $row['kind'] ) ? (string) $row['kind'] : '';
		$kind_label = isset( $kinds[ $kind_key ] ) ? $kinds[ $kind_key ] : $kind_key;
		$time_val   = isset( $row['time'] ) ? (string) $row['time'] : '';
		$note_val   = isset( $row['note'] ) ? (string) $row['note'] : '';

		$out .= sprintf(
			'<li><strong>%1$s</strong> <time datetime="%2$s">%3$s</time><br>%4$s</li>',
			esc_html( $kind_label ),
			esc_attr( mysql2date( DATE_W3C, $time_val ) ),
			esc_html( mysql2date( 'j M Y, H:i', $time_val ) ),
			esc_html( $note_val )
		);
	}

	$out .= '</ol></section>';

	return $content . $out;
}
add_filter( 'the_content', 'livingdraft_core_fallback_log', 20 );
