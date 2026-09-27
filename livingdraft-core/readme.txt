=== The Living Draft Core ===
Version: 4.9.0
Stable tag: 4.9.0
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.2
Tested up to PHP: 8.5
License: GPLv2 or later

== What this is ==
A single site plugin for The Living Draft that carries the parts of the
newsroom that must keep working even if the theme changes — SEO stack,
Search Console integration, redirects, sitemaps, structured data — plus
the editorial infrastructure (update log, second bylines, view counting,
newsletter subscribers, site analytics, article blocks, custom CSS).

== SEO stack ==
The SEO panel lives in the post sidebar (right column), always visible
while writing. Every AI feature — Generate SEO title, Generate meta
description, Suggest focus keyword, Fix-with-AI on failing checks —
runs against your chosen provider (OpenAI, Gemini, xAI Grok, OpenRouter
or Groq) using
your own API key. The per-post Model picker lets a writer pick their
preferred model and it persists across posts.

What ships:
  - SEO metabox (sidebar) with SERP preview, 13-check content analysis,
    Fix-with-AI on each failing check
  - URL slug editor with live URL preview
  - Focus keyword, SEO title, meta description with AI generation
  - Open Graph, Twitter Card, canonical, robots directives
  - Front-end meta output on posts, pages, and taxonomy archives
  - XML + Google News sitemaps
  - IndexNow submission on publish
  - Schema.org @graph builder (NewsArticle + Organization + WebSite +
    BreadcrumbList) plus CollectionPage on term archives
  - Vision AI alt-text (per-image button + batch runner in the SEO panel)
  - Content brief workspace (structured research briefs for any keyword,
    optional draft-post spinup with the outline as H2s)
  - Embedding-based internal link suggestions with per-post reindex
  - GSC per-post analytics + Indexing API integration
  - 404 redirect suggestions
  - Rank Math migration bridge (reads Rank Math meta keys as a fallback
    so switching from Rank Math doesn't lose configured data)
  - Bring-your-own-key AI (OpenAI / Gemini / OpenRouter) with
    encrypted key storage

== PHP compatibility ==
Requires PHP 8.2 or higher — the currently-active PHP releases per
php.net (as of the release date). Tested on PHP 8.2, 8.3, and 8.4.

Sites running an older PHP version will see a clean admin notice
telling them to update PHP; the plugin refuses to load its code
rather than dying with a syntax error somewhere deep in the require
chain. If you're still on PHP 7.4 / 8.0 / 8.1 and can't update,
install the 3.1.x line, which supports PHP 7.4 and up.

== Changelog ==

= 4.9.0 =
* REMOVED: The cookie consent banner, its settings screen, its CSS and
  JavaScript. Google Consent Mode is now told "granted", so Site Kit /
  Google Analytics measures every reader in full. Old helper functions
  still exist and return "yes"; [ld_cookie_settings] now prints nothing.
  Page caches are purged once so no cached banner survives.
* NEW: Analytics. The Living Draft > Analytics shows visitors, pageviews
  and sessions for today, yesterday, this week, month, year and all
  time, each against the same point of the previous period. Chart by
  day, week, month or year over any range. Engaged time, scroll depth,
  bounce rate, pages per session, new vs returning. Channels (search,
  Google News / Discover, social, AI assistants, email, paid, referral,
  direct), referring sites, UTM campaigns, devices, browsers, systems,
  countries (behind Cloudflare), busiest hours, readers right now.
  Dashboard widget, per-post Readership box, CSV export.
* Counted in the reader's browser after load, so page caches do not
  hide visits. No cookies: a random id in localStorage. Bots, logged-in
  staff (remembered per browser), listed IPs and ?ld_optout=1 browsers
  are never counted. Raw pageviews kept 25 months (configurable); day,
  week, month and year totals kept forever. Settings > Analytics.
* The per-article view counter now rides on the same request, so an
  article costs one call instead of two.
* NEW: AI analyst on the Analytics screen. Sends aggregate figures only
  and returns a briefing: headline, what drove it, warning signs, five
  next actions, story ideas.
* NEW: AI key points. An editable "In brief" box for each article,
  generated from the post sidebar, hidden per article if wanted, flagged
  when the story changes afterwards. Optional auto-write on first
  publish (off by default). Settings > Key points (AI).
* NEW: xAI Grok as an AI provider (default grok-4.3). Groq is now on the
  settings screen too.
* NEW: AI fallback. If a provider fails, the request goes to the next
  one with a key, in an order you set. Test connection checks every key.
* FIX: OpenAI GPT-5.x and o-series requests. They reject max_tokens and
  most reject custom temperature on Chat Completions; requests now use
  max_completion_tokens, low reasoning effort and headroom, and retry
  once without any parameter a model refuses.
* FIX: Gemini 2.5 / 3.x "thinking" models could return an empty answer
  on short tasks; they now get output headroom.
* SECURITY: The Gemini key is sent in a header rather than the URL, so it
  no longer appears in proxy or server logs.

= 4.8.0 =
* NEW: Automatic redirects need your approval. When a published story
  changes address, is unpublished or is deleted, the redirect (or a
  410 Gone) is proposed under Redirections > Awaiting approval and does
  nothing until you approve it. Approve, edit, reject or approve all.
  The menu shows how many are waiting.
* While a proposal waits, WordPress still sends readers from a renamed
  story's old address to its new one. Settings has a strict mode that
  switches this (and WordPress's URL guessing) off.
* FIX: Renaming a story and renaming it back no longer creates a
  redirect loop. A story is never hidden by a redirect: automatic ones
  are removed and hand-made ones are paused when a story goes live there.
* FIX: Loops are refused and chains are shortened, so every redirect is
  one hop. Redirects that pointed at an address are updated when that
  address itself is redirected.
* FIX: A redirect over a live story is refused unless you tick a box.
* FIX: Full URLs, paths without a leading slash, %20-style characters
  and non-English slugs in the From field now match.
* NEW: ?utm_ and other query parameters are kept on redirect (setting).
* NEW: 410 Gone type, and Mark gone in the 404 log. 308 type added.
* NEW: Edit a redirect. Automatic proposals never replace a redirect you
  made.
* FIX: Ignore in the 404 log is permanent. Creating a redirect marks the
  404 fixed; deleting the redirect reopens it.
* 404 log filters prefetch checks, scanners, file probes, inline data:
  scripts and crawler loops, using the view counter's bot list.
* NEW: Answers /.well-known/traffic-advice so Chrome can prefetch stories
  from Google results (setting).
* Saving a redirect purges that address from LiteSpeed, WP Rocket, W3TC
  and WP Super Cache.
* FIX: Attachment pages with media on a CDN no longer redirect readers
  to the login page; a draft parent is never used as the target.
* Suggestions offer section (category / tag) pages and say when the
  address belongs to a draft or trashed story.
* CSV import follows the same rules as the form, skips existing
  redirects unless you tick Replace, accepts semicolon files and BOMs,
  round-trips its own export (with status), and refuses non-CSV files.
  The Rank Math, Yoast Premium and Redirection importers use the same
  rules and now import 410s.
* Upgrade: existing redirects are repaired once (paths normalised,
  duplicates merged, hidden stories unhidden, chains shortened, loops
  moved to Awaiting approval) and the counts are shown on the page.
  Redirects that were already live stay live.

= 4.7.0 =
* NEW: Link assistant, a panel on every article's edit screen.
  One button reads the article as it stands in the editor and suggests
  internal links on other articles' focus keywords, each as a small
  Before / After rewrite to Accept or Skip.
* Link limit: one link per 175 words by default (Settings > Link
  assistant). Existing links, internal and external, count. An article
  that is already full gets no suggestions.
* Reliability score for every target, with reasons: topic match (from
  the existing embeddings), keyword present, freshness, Google position,
  readership, and a boost for articles nothing links to yet. Noindexed,
  unpublished and password-protected articles are never suggested;
  articles carrying a correction are marked.
* Every AI suggestion is checked before it is shown: the sentence must
  exist word for word, the rewrite must stay close to the original, the
  link text must be the target's keyword or a close form, one link per
  target and per paragraph. Headings, quotes, lists, captions, tables and
  the plugin's own boxes are never touched.
* Warns when another article uses the same focus keyword.
* Nothing is saved until you press Update, and nothing is written to the
  update log.

= 4.6.1 =
* FIX: Google Analytics recorded almost no visitors on cached sites.
  The consent banner removed Site Kit's tag in PHP, so the cached copy
  of every page had no tag in it, even for readers who pressed Accept.
  The decision now happens in the browser with Google Consent Mode v2,
  so cached pages behave correctly for everyone.
* FIX: Site Kit's consent-mode script is no longer removed.
* CHANGE: Accept takes effect immediately; no page reload.
* NEW: Settings > Analytics & consent. Choose "Measure anonymously"
  (cookieless pings before consent, the default) or "Load nothing from
  Google" (tag downloaded only after Accept). Optional advertising
  consent, off by default.
* NEW: readers can change their choice. Add a menu link to
  #cookie-settings, or use the [ld_cookie_settings] shortcode.
  Declining after accepting also deletes Google Analytics cookies.
* CHANGE: removed the per-cookie cache split (LiteSpeed/WP Rocket vary
  and the Vary: Cookie header). It is no longer needed and was lowering
  cache hit rates.
* FIX: caches are now purged after an update, not only on activation.

= 4.6.0 =
* NEW: credential diagnostics on Settings > Mail. A "Check this
  credential" button beside each of the three credentials, plus a
  line under each field saying what is stored without showing it
  (prefix and last four characters only).

  Written because a test send answers "did this go?" and nothing
  else. Brevo's reply to an unrecognised key is the two words "Key
  not found", which is true and useless: it does not say that Brevo
  shows two different credentials on the same settings page and only
  one of them works with the API.

* The check runs in two stages. Locally first, with no network call —
  every provider prefixes its keys, so a wrong key TYPE is named
  exactly ("that is a Brevo SMTP key, this field needs an API key
  beginning xkeysib-"). Then against the provider's account endpoint,
  which confirms the key is real without sending anyone an email or
  spending a message from a 300-a-day allowance.

* The check also reports the next wall before you hit it: whether
  your From address is a verified sender on Brevo, or your sending
  domain is verified on Resend. Both produce a separate and equally
  opaque error at send time.

* SMTP gets a real connection test via PHPMailer's smtpConnect(),
  which performs the full handshake including AUTH — so a bad
  password fails here exactly as it would during a send, with no
  message queued.

* NEW: detection for an undecryptable key. Keys are encrypted against
  the salts in wp-config.php; if those change after a key is saved —
  a host move, a staging copy, a security plugin regenerating them —
  the key silently becomes unreadable and mail quietly falls back to
  wp_mail. That state is now named on screen with the fix (paste the
  key again) rather than looking like a missing key.

* NEW: channel warning. livingdraft_mail_channel() falls back to
  wp_mail when the configured transactional channel is not ready,
  which is right at send time and wrong on a settings screen. The
  screen now says when the channel in use is not the one selected.

* Whitespace inside a stored key is now detected. A line break
  survives a copy from a wrapped terminal or a PDF and produces the
  same 401 as a wrong key, with nothing visible in the field.

= 4.5.0 =
* FIXED: the newsletter signup bar has never worked. The theme's
  markup was correct — email field, honeypot, a valid nonce for
  `livingdraft_subscribe`, a data-endpoint pointing at admin-ajax —
  and nothing ever submitted it. There was no submit handler in the
  theme's JavaScript or the plugin's, and the form carried no action
  field, so pressing Subscribe posted nowhere and reloaded nothing.
  The plugin's endpoint has been sitting there the whole time with
  nothing calling it.

  The handler now lives in the plugin, not the theme, because
  subscribers are data and not presentation — a theme change must not
  be able to disconnect the form again. assets/js/forms.js binds to
  the theme's own `.ld-signup-form` class, so the existing bar starts
  working on activation with no theme edit and no markup change.

* NEW: contact form. `[contact_form]` shortcode, with a Messages
  screen at The Living Draft > Messages and settings under
  Settings > Forms.

  Every message is written to the database BEFORE any mail is
  attempted. A contact form that only emails loses the message when
  the mail fails — and shows the reader a thank-you while doing it.
  If the notification does not send, the message is still on the
  Messages screen, flagged, and the reader's thank-you was honest.

* NEW: `[subscribe_form]` shortcode — a signup form that does not
  depend on the theme printing one.

* Notification email is From your own verified address with Reply-To
  set to the sender. Sending it as the reader would forge the From
  header, fail SPF and DMARC, and land your own contact form in spam.
  Pressing reply still answers the reader, which is the only part
  that mattered.

* Spam handling: a honeypot, a signed timestamp trap (anything
  submitted under four seconds after the form rendered), and a
  per-address rate limit. No captcha — that means a third-party
  script on a site that ships no external requests, a reader's
  interaction data going to an advertising company, and a share of
  real people who fail it and leave.

  The honeypot and timestamp failures report success to the sender
  and store nothing. Telling a bot which check it failed is how the
  next version of the bot passes.

* Messages screen reuses the existing AI reply drafting with the
  message pre-filled. It drafts; you copy into your own mail client.
  Nothing sends from there.

* IP addresses are NOT stored by default, and are anonymised when
  turned on. A contact form does not need to know where someone was
  standing.

= 4.4.0 =
Admin interface rework. The palette, typography and flat editorial
identity are unchanged — the problem was never the design, it was
that half the plugin did not use it.

* CHANGED: Mail, Listen, Redirections and Fact check rebuilt on the
  plugin's own component system. They were on WordPress's form-table,
  widefat, regular-text and button-primary, which is why they looked
  like a different product next to the SEO panel. Raw WordPress
  component count across those four screens: 44 before, 0 now.

* REMOVED: the duplicate tab strip under every page title. It listed
  the same six destinations the WordPress sidebar shows at the same
  moment, three inches to the left — a second thing to scan, a second
  thing to keep in sync, and about 60px taken from every screen.
  `livingdraft_admin_tabs()` is kept, returning empty, so third-party
  filters do not fatal.

* CHANGED: one Settings screen with a rail — AI, Mail, Listen, Fact
  check, Updates. They used to be in four unrelated places. Modules
  register through the new `livingdraft_settings_panels` filter.
  Bookmarks to SEO → AI redirect to Settings → AI.

* CHANGED: the fact-check panel in the post editor no longer renders
  WordPress's #2271b1 blue in the middle of a warm editorial palette.
  The run button is the plugin's own ink button, the panel opens with
  a mono eyebrow, and the result leads with a two-word verdict kicker
  in the same style the front end uses for Correction labels.

* NEW components: .tld-rows (record lists that are not tabular),
  .tld-meter (72px progress rule), .tld-figure (tabular numerals),
  .tld-check (whole-line clickable checkbox labels), .tld-grid-2,
  .tld-panel, .tld-send-bar.

* NEW: responsive rules. There were four media queries across 1,300
  lines, so two-column layouts and the stat row simply overflowed
  below laptop width. Breakpoints at 960 / 782 / 600 are measured
  against the content width WP's sidebar leaves, not the viewport.
  Inputs go to 16px under 782px so iOS Safari stops zooming on focus.

= 4.3.0 =
* NEW: Mail — a full sending stack at The Living Draft > Mail.

  TRANSPORT. SMTP (your own mailbox) or the Brevo / Resend APIs, set
  independently for transactional and bulk mail, because they are
  different jobs. Credentials encrypted at rest with the same AES
  helpers the AI module uses. Optional takeover of all WordPress mail
  so password resets stop vanishing. Test send and a 300-entry send
  log with the provider's own error text.

* NEW: Subscriber consent lifecycle. Double opt-in with a
  confirmation email, per-subscriber confirm and unsubscribe tokens
  (separate secrets — a forwarded old newsletter must not carry a
  working confirmation link), RFC 8058 one-click unsubscribe, and
  status on the subscriber list.

  Existing subscribers are grandfathered as confirmed and marked
  `_ld_optin = single`, so the record shows what actually happened.
  They are NOT asked to re-confirm: a re-permission campaign is
  itself a bulk send to unconfirmed addresses, which is the thing
  being avoided.

* NEW: Campaigns. A batched queue driven by cron, 40 per five
  minutes by default. Recipients are removed from the queue before
  the send, not after — so a killed cron run loses at most one
  recipient rather than sending anyone the same newsletter twice.
  Status re-checked per recipient, so someone who unsubscribes
  mid-campaign does not receive it. Daily cap checked before every
  batch. Pause and resume.

  Bulk sending through the WordPress default mailer is refused, not
  warned about. mail() has no authentication and using it for a whole
  list is the fastest way to get a domain blocklisted — including for
  the password resets that had nothing to do with the newsletter.

* NEW: AI drafting — newsletter from the last 7 days of posts,
  subject lines with preview text, and reply drafts from a pasted
  message. All three work from material that already exists rather
  than originating claims. Links in a generated newsletter come from
  get_permalink(); any URL the model writes itself is stripped and
  marked, because it was told to use markers and never write one.

* NOT BUILT, deliberately:
  - No mailbox is read. Receiving mail means storing a real email
    account password in wp_options and polling it on cron, putting
    inbox access behind the site's weakest admin password. Reply
    drafting takes pasted text instead.
  - No open or click tracking. A pixel records that a named address
    read a named email at a named time, on a site that asks consent
    before counting a page view. Click tracking also hides link
    destinations and breaks the archive when the site moves.
  - Nothing sends itself. mail-ai.php has no code path to
    livingdraft_campaign_start().

* NEW: `livingdraft_subscribe_response` filter on the signup handler,
  so the form can say "check your inbox" instead of "you are on the
  list", which is no longer true at that moment.

* NEW: cron event `livingdraft_mail_queue`, registered in the shared
  hook list so deactivation clears it.

= 4.2.0 =
* NEW: Listen — a text-to-speech control on articles. The reader's own
  browser or phone speaks the page through the Web Speech API. No
  audio files, no API key, no third-party service, no per-article
  cost, and nothing about what anyone reads or listens to leaves
  their device.

  Includes: play / pause / resume, speed control (0.75x to 2x), voice
  picker populated from whatever the reader's device offers, a
  progress rule, an optional mark on the paragraph being spoken, and
  resume-where-you-stopped stored per article on the reader's own
  machine.

  Deliberately does NOT scroll the page to follow the voice. Most
  listeners read ahead of it, and taking their scroll position away is
  worse than letting them lose their place.

  Settings live at The Living Draft > Settings. The `[listen]`
  shortcode places the control by hand.

* NEW: `livingdraft_listen_audio_url` filter — the seam for swapping
  browser speech for real generated audio later. Return a URL and the
  player uses an audio file instead, with no other change.

* NEW: `livingdraft_settings_sections` action on the Settings screen,
  so a module with a handful of options can render them there instead
  of claiming a tab.

* NOTE: voice quality is the reader's device, not ours. Excellent on
  recent iPhones and Macs, adequate on Android and Windows,
  occasionally robotic on Linux. That is the trade for zero cost and
  always-current narration.

= 4.1.0 =
* REMOVED: The Desk — scheduled AI drafting. The whole `inc/desk/`
  module is gone: the wire fetcher, the story clusterer, the writer,
  the stock-image attacher, the Desk admin screen and its tab, the
  `livingdraft_desk_tick` cron event, and the four
  `livingdraft_desk_*` options.

  Why: the Desk could produce a sourced, anchored draft, but it could
  not produce one in house style. Structure — which paragraph carries
  the news, where context sits, how a standfirst is earned — is the
  expensive part of a story, and every Desk draft had to be rebuilt
  into that shape before it could run. That is more work than writing
  from the sources directly.

* KEPT: everything that assists a writer rather than replacing one —
  the SEO stack and its Generate / Fix-with-AI buttons, content
  briefs, internal-link suggestions, vision alt-text, the fact-check
  panel, timelines, and the bring-your-own-key AI provider layer.
  None of these changed.

* UPGRADE NOTE: drafts the Desk already created are left completely
  alone. They become ordinary drafts — only the "Desk draft" label
  beside them in the posts list disappears. Their `_ld_desk_origin`
  meta (which sources each was built from) is deliberately NOT
  deleted; provenance is not something this site quietly discards.

* UPGRADE NOTE: the retired cron cleanup now also clears
  `livingdraft_desk_tick` and deletes the Desk's four options, so an
  upgrade leaves no orphaned scheduled event and no unreadable rows.
  This runs once on the first admin page load after the update.

= 4.0.0 =
* NEW: Average position column on the Posts and Pages lists, sortable. The
  figure was already being fetched from Search Console and stored on every
  article; it just had no column. Impressions tell you Google is showing a
  page and clicks tell you it is being used, but position tells you what to
  do next.

  Banded so the actionable articles stand out: top three, page one, page two,
  and beyond. Page two gets the loud colour because that is almost always the
  right place to spend an hour - those articles earn a fraction of the clicks
  of page one and are close enough that a better title or opening can move
  them over. Labelled in words as well as colour.

  Sorting by position deliberately excludes articles with no data. Position
  runs the opposite way to every other column - 1 is the best result and a
  large number is a bad one, so the useful sort is ascending. That makes "no
  data", which is stored as 0, numerically better than first place. Sorted
  ascending without the filter, every unranked article on the site would pile
  up above the genuine top ten and the column would be useless for exactly
  the job it exists to do. Sorting by position therefore asks "my ranked
  pages, best first"; the unranked ones are still there under any other sort.

* CHANGED: View counting rewritten. It now works behind a page cache, and
  nothing it records is ever deleted.

  The old counter incremented in PHP while the page was being built. If any
  page cache is running - LiteSpeed, WP Rocket, Cloudflare, a host cache -
  PHP does not run for most visitors, so the counter never fired. On
  thelivingdraft.com that produced articles reading 0, 2 and 4 lifetime
  views while Search Console reported dozens of impressions for the same
  pieces over the same period.

  Counting now happens in a small separate request the browser makes after
  the page has loaded, which is never cached. Side effect worth having:
  crawlers do not run JavaScript, so most bot traffic now excludes itself
  without being on any list.

* CHANGED: Nothing is deleted any more. The old design kept one decaying
  float, multiplied it by 0.75 nightly and deleted it below 0.5 - which made
  Trending work but destroyed the history behind it within weeks.

  Four values are now kept per article, three of them permanent:
    _ld_views         lifetime total, only ever increases
    _ld_views_months  every calendar month, for the life of the site
    _ld_views_days    the last 120 days, day by day
    _ld_views_trend   derived: the last 7 days added up

  The day and the month bucket are written by the same request, so when a
  day older than 120 leaves the daily record its views are already in the
  month archive. The month archive is never trimmed. Only the derived
  trending figure is rewritten, and it can always be recomputed.

  Trending still means trending: a story that stops being read drops out
  because the days it was read in slide out of the seven-day window, not
  because anything was destroyed. Tested over a simulated two years -
  4,124 reads, every one still accounted for, storage bounded at 120 daily
  entries.

  Existing lifetime totals carry over untouched. The retired 3.x decayed
  score is still read as a top-up for Trending during the first week, and is
  never written to or removed.

* CHANGED: The nightly decay job (livingdraft_daily_decay) is unscheduled on
  upgrade, since it deleted data. Replaced by livingdraft_views_nightly,
  which only recomputes the derived trending figure.

* NEW: The Views column is sortable, and the figure in brackets is now reads
  in the last 7 days rather than a decayed score.

* NEW: livingdraft_views_stats() and livingdraft_views_total() for themes
  that want to print a read count.

* NEW: Story timelines. Groups articles into the running news stories they
  belong to and hands the grouping to the theme, which prints a dated
  timeline at the foot of each article. Review at The Living Draft >
  Timelines.

  Every plausible pair of articles is scored on four independent signals:

    A LINK BETWEEN THEM (50 points) - the strongest, and it was free all
    along. When a follow-up says "as reported on 25 August" and links back,
    a human being has stated outright that these two pieces belong together.
    Highest-precision signal on any news site. On thelivingdraft.com every
    internal link sampled pointed at another article in the same story.

    SHARED RARE NAMES (15 each, capped at 30). A slug is eight words; the
    article is hundreds, including the proper nouns that pin a story down -
    IN-SPACe, GMPCS, PPPAC, Jantar Mantar. Rarity carries the weight, so a
    name common across the site earns nothing.

    SHARED SLUG WORDS (20 each, capped at 40), and

    TIME AND SECTION (up to 20) as a tiebreaker.

  60 or above joins them. 30 to 59 waits for one click. Below 30, nothing.

  Why four signals rather than one: they fail independently. Three real
  articles from one Starlink story -

    centre-freezes-starlink-state-deals-pending-licence-gen-2-network
    in-space-clears-gen-2-constellation-40000-satellites-conditions
    trai-finalises-direct-to-device-spectrum-rules-satcom-operators

  - share not one word. A six-week story renames itself as it goes, and a
  missing timeline looks identical to a page that never had one, so nobody
  ever finds out. Scored, the first two reach 100 and the second two reach
  75, on links and shared names.

  It is also more cautious, not just greedier. Under the previous rule two
  articles sharing the word "reservation" were grouped automatically with
  nothing asked - a protest story and an OBC list revision are both about
  reservation policy and are not the same running story. Scored, that pair
  reaches 25 against a threshold of 60 and is left alone without even being
  queued.

  Three things keep it honest. The SERIES GUARD strips dates, numbers and
  month names from headlines and rejects any pair or group that collapses to
  one template - applied pairwise as well as per group, so a daily column
  scores zero rather than filling the queue with hundreds of near-misses.
  The RARITY CEILING discards any word or name appearing across more than a
  quarter of the site, whatever the stop list says. And THE QUEUE never
  guesses: middling pairs wait for a person, and that decision is stored
  against the two article ids and survives every future rebuild.

  Links are matched by slug rather than through url_to_postid(), which
  returns 0 for permalinks built on a nested category - the same failure
  that made the Search Console columns show a dash on every row before 4.0.

  Pairs are generated from inverted indexes, so only articles sharing at
  least one word, name or link are ever scored. Comparing all pairs on a
  5,000-article site would be 12.5 million comparisons; almost all of them
  would score zero.

* FIX: Google Search Console clicks and impressions showed a dash on every
  row of the Posts list, on sites where the editor sidebar showed the same
  figures correctly.

  The two read the cache from opposite directions. The sidebar takes the
  post's permalink and looks it up - it never has to guess. The list ran
  every cached URL through url_to_postid(), which resolves a URL back
  through the rewrite rules and commonly returns 0 for permalinks built on
  a nested category (/states/west-bengal/slug), for URLs without a trailing
  slash, and for percent-encoded paths. Google reports all three.

  The sync now makes a second pass over any post the first pass missed,
  using the sidebar's own lookup, and the column falls back to the same
  lookup live so a site that has not re-synced since upgrading sees its
  numbers immediately.

* FIX: CSV exports and imports emitted deprecation notices on PHP 8.4 and
  would have broken outright on 8.5. fgetcsv() and fputcsv() now pass the
  $escape argument explicitly instead of relying on a default PHP is
  removing. Affected the redirects import/export and the newsletter
  subscriber export - on PHP 8.4 the notices were printed into the top of
  the downloaded file, corrupting it.
  Passing an empty string also turns off PHP's non-standard backslash
  escaping, so the output now matches RFC 4180 and opens correctly in
  Excel and Google Sheets. Previously a redirect path containing a
  backslash could come back mangled on re-import.
* CHANGED: Version aligned to 4.0.0 with the theme, so plugin and theme
  releases move together from here.
* NOTE: This plugin still refuses to load below PHP 8.2, but a full
  PHPCompatibility scan against 7.4 through 8.5 finds no incompatibility
  anywhere in the codebase. The floor is a support policy, not a
  technical requirement. Worth revisiting: the theme allows PHP 7.4, so
  a site on 8.0 or 8.1 gets a working theme and a plugin that refuses to
  start, which silently removes the update log from every story.

= 3.8.0 =
* NEW: "The Desk" theme — a visual refresh of the admin, applying the
  frontend site's editorial identity to every plugin surface. Warmer
  cream paper background, Fraunces variable serif (self-hosted, ~82 KB
  total, latin subset) for masthead and card titles, larger 40px page
  heading, section-sign watermark at 8% opacity, softer hairline rules,
  tabular numerals on every figure.
* NEW: `.tld-numbers` — ledger-style stat row now used on the Overview
  dashboard. Front-page "circulation figures" look with hairline
  internal rules and the 3px reddish-brown accent bar on the left.
* NEW: `.tld-subtabs` component + `livingdraft_admin_render_subtabs()`
  helper. Replaces three copies of inline-styled sub-nav HTML in
  redirects-admin.php, seo-admin.php, and seo-desk.php with a single
  reusable component.
* NEW: `.tld-notice` component replacing WordPress-core `.notice`
  markup for plugin-owned messages (redirect saved, deleted, resolved,
  imported, error). Kickers rendered by CSS: FILED / NOTE / STOP / MEMO.
* NEW: `.tld-editor-note` closing strip appended by
  livingdraft_admin_render_footer() on every plugin page. Italic quote
  left ("Every story lives beyond the headline."), copyright + policy
  right. Mirrors the frontend site footer.
* NEW: `.tld-masthead-strip`, `.tld-section-rule`, `.tld-kicker`,
  `.tld-tag-auto`, `.tld-cell-num`, `.tld-cell-meta`, `.tld-cell-actions`
  utility classes available for future module refreshes.
* IMPROVE: WordPress-core `.widefat` tables (still used on the Redirects
  screen) are softened when they land inside the plugin shell — cream
  header row, mono column labels, mark-tinted hover state.
* IMPROVE: WordPress-core `.notice` markup inside the plugin shell is
  now visually aligned with the design system (matching border-left
  colors, softer surrounding treatment).
* NO BREAKING CHANGES: every existing class hook (`.tld-header`,
  `.tld-stat`, `.tld-btn`, `.tld-table`, `.tld-seo-score`, etc.)
  continues to work exactly as before. No data migration, no schema
  change, no PHP API change.

= 3.7.2 =
* FIX: Bulk term generator (SEO Desk → Bulk term generator) no
  longer stalls at ~30 terms. The per-user 30-calls-per-5-minutes
  interactive rate limit was designed to catch runaway UI bugs
  and cost blowouts on the metabox "Suggest" buttons, but it was
  wrong for the bulk generator, which fires one intentional call
  per term with a 250ms delay — a batch of 200 tags would previously
  process 30 terms, hit the limit, wait 5 minutes, process 30 more,
  and take ~35 minutes with mostly waiting. Now bulk operations
  bypass the rate limit via a server-set `bypass_rate_limit` arg
  and complete in one uninterrupted pass.
* CHANGED: `livingdraft_ai_complete()` accepts a new arg
  `bypass_rate_limit` (default false). SERVER-SET ONLY — never
  read from `$_POST` or `$_GET`, never passed through
  `livingdraft_ai_read_request_override()`, cannot be set from
  the client. Meant only for trusted internal callers that
  explicitly know a batch operation is intentional (bulk term
  generator today, future backfill workers). Interactive AI
  buttons still enforce the 30/5min limit unchanged — a stuck
  editor loop still gets caught.
* CHANGED: Docblock on `livingdraft_ai_read_request_override()`
  spells out the security expectation: this parser must never
  extend to pass through `bypass_rate_limit`, `system`, or any
  other arg that changes execution behaviour. Prevents a future
  edit from accidentally exposing the bypass to client input.
* Provider-side rate limits (OpenAI / Gemini / OpenRouter per-
  minute request caps on your account) are unaffected and still
  apply — nothing here can bypass those.

= 3.7.1 =
* FIX: Every OpenRouter request was failing with "provider: Invalid
  input: expected object, received string". Cause: our internal
  routing key `provider` (a string like "openrouter") was in the
  list of args forwarded verbatim into OpenRouter's HTTP body,
  where their API now requires `provider` to be an OBJECT (their
  routing preferences block, e.g. `{ order: [...], only: [...] }`).
  Fix: removed `provider` from the forwardable list. Callers that
  genuinely want to pin OpenRouter provider routing can pass an
  array under the new distinct key `openrouter_provider` which is
  explicitly mapped to the API's `provider` field.
* CHANGED: Default AI models refreshed for August 2026:
    - OpenAI default: gpt-5.4-mini (was gpt-4o-mini)
    - Gemini default: gemini-3.6-flash (was gemini-2.5-flash,
      which shuts down October 16, 2026)
    - OpenRouter default: openai/gpt-5.4-mini (was
      openai/gpt-4o-mini)
  Model picker lists now include the current GPT-5.4 family
  (mini, nano, flagship) plus GPT-4.1 as a proven fallback; the
  Gemini list leads with 3.6 Flash, 3.5 Flash Lite, 3 Pro; the
  OpenRouter list adds Anthropic Claude Sonnet 5, Opus 4.7, and
  Haiku 4.5 for strong writing quality. Existing users' saved
  model choices are NOT overwritten — if you had gpt-4o-mini or
  gemini-2.5-flash set, they remain until you change them. Both
  still work today but will be retired; migrate at your leisure
  under Settings → AI.
* NEW: "Rebuild per-post index" button on the Search Console
  admin tab next to "Sync now". Re-stamps the postmeta and
  termmeta indexes from the existing analytics cache without
  hitting Google's API. Use this if the Impr./Clicks columns on
  your Posts, Pages, or category / tag lists are showing dashes
  after upgrading — the sync already ran but the denormalization
  step (added in 3.7.0) hasn't yet.
* NEW: Auto-backfill of postmeta / termmeta stamps on upgrade.
  The first admin load after upgrading to 3.7.1 (or later) checks
  whether the denormalization has ever run and, if not, runs it
  once against the existing cache. Idempotent — a flag option
  tracks the last version we backfilled for.
* NEW: `wp_ajax_ld_gsc_rebuild_index` — public AJAX endpoint that
  runs `livingdraft_gsc_denormalize_metrics_to_postmeta()` and
  `livingdraft_gsc_denormalize_term_metrics()` against the current
  cache.

= 3.7.0 =
* NEW: GSC "Impr." and "Clicks" columns on the Posts and Pages
  admin lists. Sortable — click the column header to sort by
  most-impressions or most-clicks descending. Columns only appear
  when GSC integration is configured, so they don't waste space
  on sites that don't use GSC. Read from the same denormalized
  postmeta the queue view already uses (no extra DB cost).
* NEW: Same GSC columns on category, tag, and custom-taxonomy
  list screens, alongside a new SEO status pill (COMPLETE / PARTIAL
  / NONE) that tells you at a glance which terms have all three
  SEO fields filled in.
* NEW: SEO Desk admin page (The Living Draft → SEO → SEO Desk).
  Two tabs:
    - Overview: coverage matrix showing missing title / description
      / focus keyword counts per post type and per taxonomy, with a
      colored coverage bar for each row (green ≥80%, amber ≥50%,
      red <50%). Direct links to filtered admin lists.
    - Bulk term generator: fills in missing meta title, description,
      and focus keyword for every category, tag, and custom-taxonomy
      term with a single AI call per term. Uses a JSON-bundle prompt
      that returns all three fields at once — 3x cheaper than three
      calls per term. Existing values on your side are never
      overwritten. Safe to stop and resume (each call is atomic).
      Live progress bar, per-term log, cost estimate, model picker.
* NEW: `term_meta_bundle` prompt in ai-provider.php. Returns a
  JSON object with focus_keyword, seo_title, meta_description for
  a term archive, using the term description + titles of recent
  posts in the term as context. Callable by third-party integrations
  via livingdraft_ai_build_prompt('term_meta_bundle', $ctx).
* NEW: `wp_ajax_ld_seo_desk_generate_term` — bulk generator's
  per-term worker endpoint.
* NEW: `wp_ajax_ld_seo_desk_pending_terms` — fetches the list of
  pending term IDs so the client can loop through them independently.
* NEW: `livingdraft_seo_desk_pending_term_ids()` and
  `livingdraft_seo_desk_pending_term_count()` — public helpers.
* CHANGED: Analytics cache refresh now also stamps termmeta for
  every public taxonomy term whose archive URL is in the cache.
  Uses the same variant-aware resolver as the post version.
  Enables the new category/tag GSC columns and unblocks future
  term-level sort/filter work.

= 3.6.0 =
* NEW: Indexing Queue table on the GSC tab. Replaces the old
  "Submit next batch of 25" single button. Full paginated table
  of every published post with columns for title, publish date,
  last submitted (or "never"), impressions (28d), clicks (28d),
  and a per-row Submit / Resubmit button.
* NEW: Filter dropdown — All published, Never submitted, Not
  submitted in 30+ days (or never), Not submitted in 90+ days
  (or never), Submitted in the last 7 days.
* NEW: Sort dropdown — Longest since last submit (default,
  surfaces the most-neglected first), Never submitted (newest
  first), Newest published, Oldest published, Highest impressions
  (28d), Highest clicks (28d), Best average position (28d).
* NEW: Post-type dropdown — Posts + Pages, Posts only, Pages only.
* NEW: Bulk selection — per-row checkboxes plus a select-all-on-
  page master. "Submit selected (N)" button chunks the selection
  into batches of 10 and dispatches sequentially with a live
  progress line and quota-remaining counter. Auto-stops when the
  200/day quota is reached and tells you exactly how many made
  it through.
* NEW: Per-row Submit / Resubmit button on every queue row —
  reuses the ld_gsc_index_now endpoint.
* NEW: Pagination (50 posts per page), bookmarkable URL state —
  filter, sort, post-type, and page all live in the query string.
* NEW: `livingdraft_gsc_queue_query()` — public helper for
  themes or companion plugins that want to build their own
  queue views on the same filter/sort model.
* NEW: `wp_ajax_ld_gsc_index_batch` — submit an arbitrary list
  of post IDs. Different from ld_gsc_bulk_index (which auto-
  picks pending posts); accepts explicit IDs from the queue
  selection or from a "submit all with filter X" flow. Capped
  at 20 IDs per request; JS chunks larger selections.
* CHANGED: Analytics cache refresh now denormalizes per-post
  metrics (clicks, impressions, position) into postmeta as its
  final step. Enables SQL-side ORDER BY for the new queue's
  "Highest impressions / clicks / best position" sorts without
  loading the whole cache option into PHP on every query. Uses
  the same variant-aware URL resolver so http/https/www/slash
  quirks between GSC's index and get_permalink don't lose
  matches. Stale metrics on posts that dropped out of cache
  get cleared so old numbers don't linger and pollute sorting.
* CHANGED: The old "Submit next batch (up to 25)" button and
  the associated ld_gsc_bulk_index AJAX endpoint remain in
  the codebase (still used by the automatic auto-index-on-
  publish path internally) but are no longer surfaced in the
  UI — the queue supersedes them with a strictly better UX.

= 3.5.0 =
* NEW: AI-powered URL slug generation. "✦ Suggest" button next to
  the slug field on the post SEO metabox and a matching "Suggest
  slug" button in the term SEO panel (targets the native WordPress
  slug field). Same provider / model / bring-your-own-key layer
  that powers the existing title / description / focus keyword
  generators. Output is force-sanitised through sanitize_title()
  so the model can never write unsafe characters into post_name /
  URL, and pulls the last path segment if the model returned a
  whole URL by mistake.
* NEW: Import redirects from three third-party plugins:
  - Rank Math SEO (reads wp_rank_math_redirections table, imports
    exact-match rules with 301/302/307 codes preserved)
  - Yoast SEO Premium (reads wpseo-premium-redirects-base option,
    imports all plain redirects; regex ones are skipped because
    this plugin's storage is exact-path only)
  - Redirection plugin by John Godley (reads wp_redirection_items
    table, imports only enabled URL-to-URL rules; regex, disabled,
    and non-URL match types are skipped)
  Each source is detected independently and rendered as its own
  card in Redirects → Import with a count of what would be imported
  vs what would be skipped and why. Existing entries on our side
  are never overwritten — safe to re-run. Import source is recorded
  in each redirect's notes column for later auditing.
* NEW: Prompt library entry `seo_slug` in ai-provider.php with
  slug-specific constraints (lowercase ASCII, hyphens only, 3–6
  words, 15–60 chars, focus keyword woven in when supplied,
  transliteration for non-Latin scripts). Callable by any third-
  party integration via livingdraft_ai_build_prompt('seo_slug', ...).
* NEW: `livingdraft_redirects_detect_rank_math()`,
  `livingdraft_redirects_detect_yoast()`,
  `livingdraft_redirects_detect_redirection()` — public helpers
  that return { available, count, skipped, note } for each source.
* NEW: `livingdraft_redirects_import_rank_math()`,
  `livingdraft_redirects_import_yoast()`,
  `livingdraft_redirects_import_redirection()` — public importers
  that return { imported, existing, skipped }.
* CHANGED: Post AJAX endpoint `ld_seo_ai` accepts 'seo_slug' task.
  Term AJAX endpoint `ld_seo_term_ai` accepts 'seo_slug' task.

Note on the AI meta title / description on posts and terms: these
have been available since v3.2.1. The buttons only render when an
AI provider is configured under Settings → AI. If you don't see
them, it's because no OpenAI / Gemini / OpenRouter key is saved
yet — the plugin never makes an outbound call, so the buttons
would be non-functional without a key.

= 3.4.0 =
* NEW: GSC self-diagnostics. A new "Why isn't this working?" panel
  on the Search Console tab runs seven checks in sequence and shows
  pass/fail with a specific fix for each failure: openssl loaded,
  credentials parseable, property URL format, both API tokens
  obtainable, Search Console property queryable, cache freshness.
  Each fail row includes the exact remediation step
  ("Enable the Indexing API in your Cloud project" / "Add the
  service-account email as an Owner in Search Console" / etc).
  Turns most GSC setup problems from a support ticket into a
  self-resolving one.
* NEW: Live per-URL analytics fallback. When the nightly cache
  misses a post (because the URL is outside the top 1000 or Google
  indexed it under a different variant), the metabox strip shows
  a "Fetch live from GSC" button that queries Google for just this
  URL and warms the cache. Rate-limited to one call per minute per
  user.
* NEW: Per-post indexing status line in the SEO metabox. Shows
  "Submitted 2 hours ago (200 OK)" or "Last attempt failed (403):
  Indexing API has not been enabled" for the current post, plus a
  "Submit now" / "Retry" button that submits just this URL. Reads
  from both the persistent `_ld_gsc_indexed_at` postmeta and the
  recent submissions log, so success stays visible even after the
  log has rotated.
* CHANGED: URL cache lookup now tries every plausible variant of
  the permalink — with/without trailing slash × http/https × www /
  non-www — so a site whose canonical is https://example.com/foo/
  still gets a cache hit for data Google indexed under
  http://www.example.com/foo. Previously we tried only the trailing-
  slash variant.
* CHANGED: Indexing API error logging now surfaces the full
  actionable detail from Google's response body (status +
  errors[0].reason + message), not just the generic top-level
  message. The difference between "403 Forbidden" and
  "SERVICE_DISABLED — Indexing API has not been used in project X"
  is the difference between a support ticket and a fix.
* CHANGED: 403 message on the auto-index path updated to mention
  both possible causes (service-account not an Owner in Search
  Console, or Indexing API not enabled in Cloud project) rather
  than just the first.
* CHANGED: Saving GSC settings for the first time — or after
  changing the property URL — now triggers an immediate cache
  warm-up in the background. Previously users had to save, then
  hunt for the "Sync now" button, then wait.
* CHANGED: Metabox strip renders even when analytics is off,
  provided auto-indexing is on. Previously required both to render;
  now the analytics half is silently skipped when analytics is
  disabled but the indexing status line still appears.
* NEW: `wp_ajax_ld_gsc_live_metrics` endpoint.
* NEW: `wp_ajax_ld_gsc_index_now` endpoint.
* NEW: `wp_ajax_ld_gsc_diagnostics` endpoint.
* NEW: `livingdraft_gsc_url_variants()` helper — public, so themes
  and companion plugins can use the same normalization logic.

= 3.3.0 =
* NEW: Post-list SEO column on Posts and Pages admin lists. Colored
  status dot (good ≥80, needs-work 50–79, poor <50), numeric score,
  focus keyword. Sortable — click the SEO header to order the list
  by score, so an editor can find the ten posts that need the most
  attention in one click. Score is cached in `_ld_seo_score` postmeta
  and updated on save (both classic and Gutenberg / REST flows).
  Legacy posts show a dash until edited or backfilled via the new
  "Recompute SEO scores" utility in Webmasters.
* NEW: Site verification meta tags. New Webmasters tab in SEO
  settings takes verification codes for Google Search Console, Bing
  Webmaster Tools, Yandex, Pinterest, Baidu (百度), and Facebook
  Domain Verification. Handles the common mistake of pasting the
  whole meta tag instead of just the token.
* NEW: Attachment page redirect. 301s /?attachment_id=X pages to
  their parent post (or the file itself if orphaned), eliminating
  the duplicate-content problem WordPress creates by default. On by
  default. Toggle under Webmasters. Filterable per-attachment via
  `livingdraft_seo_attachment_redirect_allow` for themes with a
  legitimate attachment gallery template.
* NEW: RSS feed footer. Appends configurable text to every RSS item
  in every feed. Default template includes a canonical link back to
  the original post, which Google treats as a strong signal against
  scrapers who republish the feed. Supports variables: %%link%%,
  %%title%%, %%sitename%%, %%siteurl%%, %%author%%, %%year%%. Set
  under Webmasters. Empty field disables entirely.
* NEW: Separate Twitter Card fields on the post SEO metabox
  (twitter:title, twitter:description, twitter:image). Each falls
  back to its Open Graph counterpart when unset. Lets a writer punch
  up a headline for Twitter without changing the more measured OG
  copy used on Facebook and LinkedIn. Also emits twitter:site from
  a filterable / option-driven site handle.
* NEW: Yoast SEO import in the Migration tab. Copies Yoast's per-
  post SEO fields into this plugin's namespace, and reconstructs
  Yoast's split per-directive robots rows (noindex, nofollow,
  noarchive, noimageindex, nosnippet) into our combined array.
  Rank Math import stays as it was.
* NEW: Yoast fallback in the metabox reader. The read chain is now
  our key → Rank Math key → Yoast key → empty, so a site switching
  from Yoast sees its old values in the metabox on day one — before
  ever running the Migration import.
* NEW: Webmasters settings tab in SEO settings.
* CHANGED: Twitter Card front-end output now uses twitter-specific
  fields when set, with OG fallback otherwise. Previous behaviour
  was to always reuse OG. Emits twitter:site when a handle is
  configured via `livingdraft_seo_twitter_site` option or filter.
* CHANGED: `livingdraft_seo_get()` now understands the three-line
  fallback chain and handles Yoast's per-directive robots layout.

= 3.2.1 =
* NEW: AI-powered SEO on tag / category / custom taxonomy edit screens.
  The term SEO panel now has the same "✦ Generate" / "✦ Suggest"
  buttons the post metabox does — one click populates the SEO title,
  meta description, or focus keyword. Every provider you've configured
  (OpenAI, Gemini, OpenRouter) is available via the same per-user
  model picker.
* NEW: Live SERP preview on the term edit screen — updates as you
  type, showing exactly what Google will render for the archive.
* NEW: Live character counters on SEO title (30–60 ideal) and meta
  description (140–160 ideal), color-coded (green in range,
  amber outside, red well outside).
* NEW: `wp_ajax_ld_seo_term_ai` endpoint. Builds the archive's
  context for the model from the term description plus the titles
  of the ten most recent posts in the term — so the AI understands
  what the archive actually covers instead of guessing from just
  the term name.
* NEW: Model picker on the term edit screen. Chosen model persists
  per-user via a term-scoped localStorage key, so a writer's
  preferred model for archives can differ from their preferred
  model for posts.
* FIXED: Robots directive parity — the term meta was missing
  `noimageindex`, which posts had. Both places now expose all five
  directives (noindex, nofollow, noarchive, noimageindex, nosnippet).
* CHANGED: Term SEO panel restyled to use the .ld-seo-* design
  system classes instead of inline styles. Same visual identity as
  the post metabox in the sidebar — SERP preview, AI button styling,
  input focus rings, character counter treatment.
* CHANGED: Term SEO form field names moved to plain `ld_seo_title`,
  `ld_seo_description`, etc. (no bracket wrapping) to match the
  submit handler that was already expecting them without brackets.

= 3.2.0 =
* CHANGED: PHP floor lifted from 7.4 to 8.2 — the currently-active
  PHP release line per php.net. Older PHP sees a clean admin notice
  and the plugin refuses to load its code, rather than white-screening
  from a syntax error deep in the require chain. Sites still on
  7.4 / 8.0 / 8.1 can stay on the 3.1.x line.
* FIXED: SEO front-end output (article:modified_time meta tag),
  Schema.org dateModified, and sitemap lastmod all correctly fall
  back to WordPress's own post_modified when the update-log
  timestamp is empty or unparseable. Previously the int-cast of
  strtotime(false) produced a stale 1970-01-01 timestamp that
  flags in Google's Rich Results Test.
* CHANGED: UI refresh across the admin. Soft depth shadows on
  cards and stat cards, animated hover states, accent bar on the
  header, accessible focus rings on tabs and buttons, editorial
  empty states, sheen animation on AI buttons, respects
  prefers-reduced-motion. Zero markup changes — every existing
  page keeps working.
* CHANGED: UI refresh on the SEO sidebar metabox. Softer status
  dot glows, focus rings on inputs, sheen on AI buttons, hover
  polish on check rows, respectful animation of the
  Fix-with-AI suggestion panel.
* REMOVED: Stale "News Agent" comment in the OpenRouter provider
  path — leftover from the retired v3.0 Agents system.
* VERIFIED: Full SEO stack audit — sitemap.xml (index + per
  post-type + taxonomy + author + Google News), Schema.org
  @graph (Organization, WebSite, NewsArticle, WebPage,
  BreadcrumbList, CollectionPage, ItemList), 13-check content
  analyzer, IndexNow ping-on-publish, GSC service-account auth
  and Indexing API, front-end meta output (title, description,
  canonical, OG, Twitter, robots). All working correctly.

= 3.1.0 =
* REMOVED: Agents system (v3.0) — the entire user-owned writing agent
  feature has been retired. This removes the four "Agents" submenu pages
  (Agents, New Agent, Run History, Agents Settings), the 5-minute cron
  heartbeat, per-agent dedup memory, run history logs, agent storage,
  and the RSS/URL/search source fetcher.
* REMOVED: Legacy v2.x wire pipeline files (inc/agent/) that were kept
  in the repo for reference. The wire-driven news pipeline (RSS polling
  of ANI/PTI/IANS, central enrichment, auto-drafting) is fully gone.
* KEPT: All AI provider infrastructure (inc/ai/) is untouched. The
  bring-your-own-key layer, encrypted key storage, per-post Model
  picker, and provider selection (OpenAI, Gemini, OpenRouter) continue
  to power every AI feature in the SEO stack — Generate SEO title,
  Generate meta description, Suggest focus keyword, Fix-with-AI,
  image alt generation, content briefs, and internal-link embeddings.
* CHANGED: The SEO metabox moves from the "normal" position (below the
  content) to the sidebar (right column). The AI-powered meta
  description recommendation is now always visible while writing
  instead of buried under the editor. Sidebar-specific CSS overrides
  reflow the SERP preview, score row, check list, and field buttons
  for the narrower ~280px column.
* CHANGED: Activation and deactivation now clear the retired agent
  cron hooks (ld_agents_heartbeat, ld_agent_cron_run,
  ld_agent_pipeline_tick) so WP-Cron doesn't keep firing events with
  no listeners on sites upgrading from 3.0.
* CHANGED: An admin_init safety net clears the retired cron hooks for
  sites that upgraded by overwriting files instead of re-activating.
* CHANGED: Plugin description no longer mentions the News Agent.
* CHANGED: Version 3.1.0. Tested up to PHP 8.4.
* FIXED: Hardened the fallback update-log renderer against missing
  array keys in legacy log rows.

= 3.0.0 =
* NEW: Agents system — user-owned writing agents with per-agent prompt,
  sources, model, schedule, and output config. Up to 50.
  (Retired in 3.1.0.)
* NEW: Single 5-minute heartbeat scheduler with per-tick concurrency cap.
  (Retired in 3.1.0.)
* NEW: SEO fields on Category / Tag / custom taxonomy edit screens.
* NEW: Front-end meta output on term archives.
* NEW: Schema.org CollectionPage + BreadcrumbList on term archives.
* NEW: Sitemap respects per-term noindex.

= 2.1.0 =
* Wire-driven news pipeline: RSS polling of ANI/IANS/PTI/Hindu/IE/Livemint
  with live-search fallback per source. (Retired in 3.0.0, removed in 3.1.0.)
* Hybrid enrichment mode.
* Gemini as default provider with native `google_search` grounding.

= 2.0.0 =
* Merged standalone News Agent companion plugin into Core. (Retired.)
* Fixed PHP 8 empty-explode WSOD in inc/seo/seo-meta.php.
* Auto-updater moved to the main "The Living Draft" menu.

= 1.4.x =
* Full AI-powered SEO stack — metabox, URL slug editor, front-end meta,
  XML + Google News sitemaps, IndexNow, Schema.org @graph, vision alt-text,
  brief workspace, embedding link suggestions, GSC integration, redirects,
  Rank Math migration.

== Upgrade notice ==

= 3.7.2 =
Bulk term generator no longer stalls at ~30 terms. Interactive AI
buttons still enforce the 30-calls-per-5-minutes safety limit;
bulk operations (which are intentional batches by definition) now
bypass it via a server-set flag. A 200-tag backfill that used to
take 35 minutes now runs to completion in one pass.

= 3.7.1 =
Fixes OpenRouter "provider: Invalid input: expected object" error
(every OpenRouter request was failing). Refreshes default AI models
to August 2026 offerings — GPT-5.4 family, Gemini 3.6 Flash, and
Claude Sonnet 5 / Opus 4.7 via OpenRouter. Adds a "Rebuild per-post
index" button on the Search Console tab so the Impr./Clicks columns
on Posts, Pages, and category / tag lists show data immediately
without waiting for the next sync, and auto-backfills the index once
on first admin load after upgrade.

= 3.7.0 =
GSC impressions and clicks columns on Posts, Pages, and every
taxonomy list (sortable on posts and pages). New SEO Desk admin
page under SEO menu — coverage dashboard plus a bulk AI generator
that fills missing title / description / focus keyword for every
category and tag in one click. Uses a JSON-bundle prompt (3x
cheaper than three calls per term). Never overwrites existing
values. Analytics cache refresh now also denormalizes into
termmeta so term list screens can show clicks / impressions.

= 3.6.0 =
Old "Submit next batch" button replaced by a full Indexing Queue
table on the GSC tab. Filter (never submitted / stale 30d / stale
90d / recent), sort (longest since submit, top impressions, top
clicks, best position, newest, oldest), per-row Submit, bulk
select + chunked bulk-submit that respects the 200/day quota.

= 3.5.0 =
AI slug generation on posts and terms. Import redirects from Rank
Math, Yoast SEO Premium, and the Redirection plugin — separate
detected-source cards under Redirects → Import.

= 3.4.0 =
New GSC self-diagnostics panel (seven checks, actionable fixes for
each). Live per-URL fallback when the nightly cache misses. Per-post
indexing status and "Submit now" button in the SEO metabox. URL cache
lookup now handles all http/https × www / non-www × slash variants,
not just the trailing-slash one. Indexing errors now show the full
Google reason (SERVICE_DISABLED, PERMISSION_DENIED, etc), not the
generic top-level message.

= 3.3.0 =
Six new features: post-list SEO column, site verification tags
(Google/Bing/Yandex/Pinterest/Baidu/Facebook), attachment page
redirect, RSS feed footer against scrapers, separate Twitter Card
fields, and Yoast SEO import in Migration. New "Webmasters" tab
under SEO settings houses the verification/attachment/RSS
settings. Post-list scores backfill via a one-click utility.

= 3.2.1 =
AI Generate / Suggest buttons and live SERP preview now available on
Category, Tag, and custom taxonomy edit screens — parity with the
post SEO metabox. Robots directive parity fix (adds noimageindex).

= 3.2.0 =
Requires PHP 8.2+ (the currently-active PHP release line). Sites on
older PHP will see an admin notice and the plugin will refuse to load.
UI refresh across the admin — no markup changes, just design polish.
Fixes an edge-case where article:modified_time / schema dateModified /
sitemap lastmod could emit a 1970-01-01 stamp if the update-log
timestamp was empty or unparseable.

= 3.1.0 =
Agents system removed. The four "Agents" submenu pages, cron heartbeat,
and all agent-related storage/runners are gone. Existing agent configs
and run history stored in the database are not touched by this upgrade —
they will just sit unused. The SEO metabox moves from below the content
to the sidebar. Every AI feature in the SEO stack continues to work
against your configured provider.
