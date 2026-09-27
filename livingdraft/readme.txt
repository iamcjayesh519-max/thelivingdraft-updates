=== The Living Draft ===
Version: 4.2.0
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

== What is new in 4.2 ==

NO COOKIE BANNER
The Cookies and tracking section is gone from the Customizer. The banner
itself was removed in The Living Draft Core 4.9.0, which now measures
every reader.

"IN BRIEF" KEY POINTS
Articles show the AI key points written and reviewed in the post sidebar
(The Living Draft Core 4.9.0) between the update log and the story.
Customize > Article > Show "In brief" key points turns it off site-wide;
each article can also hide its own.

== What is new in 4.1 ==

READING PROGRESS BAR
A thin rule at the top of the screen showing how far through the story a
reader is. It measures the ARTICLE, not the page — which is the whole
point. A story followed by an author box, a timeline, Read Next and forty
comments is maybe 45% article by page height, so a bar measuring page
scroll sits at 45% at the exact moment the reader finishes the last
paragraph. This one reads 100% there.

Switch it off under Customizer > Article page. Hidden automatically for
readers who have asked for reduced motion, and never printed.

== What is new in 4.0 ==

READ COUNTS THAT ARE REAL
The Popular and Trending lists on the front page are now driven by a counter
that works behind a page cache. Before, most readers were never counted at
all, because a cached page does not run PHP. The figures you have been seeing
were a fraction of the truth.

Nothing recorded is ever deleted. A story's whole readership history is kept
for as long as the site exists. This lives in The Living Draft Core.

Customize > Article page > "Read count in the byline" prints how many times a
story has been read, under the headline. Off by default, and hidden below 50
reads either way, because a low number under a fresh headline discourages the
next reader. The Popular and Trending lists show their counts regardless,
since the count is the reason a story is in those lists.

STORY TIMELINES ("This Story So Far")
A running news story is not one article. A reader arriving on the fourth
piece of a six-week story from a search result has no way to find the other
five, and no way to see the shape of what they have walked into halfway.

Articles now carry a dated timeline at the foot showing every other article
in the same story, oldest first, with the one being read marked in place.
Two things follow: every article in a story links to every other one, and a
reader can read the story instead of a fragment.

The grouping is worked out by The Living Draft Core, which scores every pair
of articles on whether one links to the other, whether they share rare names
like IN-SPACe or PPPAC, whether their URLs share a word, and how close
together they ran. The theme owns only the box that draws it, so on a site
without the plugin nothing is printed and nothing errors.

Switch it off in Customize > Article page > Story timeline.

DEMO CONTENT (Appearance > Demo Content)
This theme is impossible to judge empty. A drop cap needs a lead story, a
brief rail needs stories to brief, and the update log shows nothing until
something has been logged. There is now a page under Appearance that fills
the paper with seven worked examples, and takes them out again in one press.

Each demo story explains one part of the theme, so reading the demo site is
also how you learn to use it. One carries a published correction log, one is
labelled Analysis, one has a second byline, one has no featured image so you
can see how that reads.

What it will not do, ever:
  - It never runs on its own. Only when you press the button.
  - It never changes, overwrites or deletes anything you have written.
  - It never fills a menu position or a sidebar you have already filled.
  - It never changes a setting you have already chosen.
  - It never lets any of it reach Google. Every demo story is marked
    no-index and kept out of your sitemap for as long as it exists.

Removal works from a written record of exactly what was created, so it takes
out the demo and nothing else, and puts back any setting it changed.

Placeholder images are drawn on your own server at the theme's 1200 x 628
crop rather than shipped in the download, which keeps roughly a megabyte out
of every copy of this theme and guarantees the demo pictures are never
cropped or upscaled. Requires the GD extension; without it the demo simply
has no pictures.

LIGHT, DARK, AND LETTING READERS CHOOSE
Until now the theme followed the reader's device and offered no way to
disagree with it. There is now a control in the masthead, beside search:
light, dark, or match my device. Whatever a reader picks is remembered in
their own browser and survives navigation, with no flash of the wrong
colour in between.

Two settings live in Appearance > Customize > Light and dark:
  - How the paper opens. What a first-time reader sees before they have
    chosen anything. Light, Dark, or Match my device.
  - Let readers choose. Shows or hides the control. Turn it off and the
    paper is locked to the appearance you picked.

A reader who has made a choice keeps it. Changing the site default does not
overrule them.

The control is three real radio buttons in a fieldset, not a cycling button.
A cycling button cannot say what it will do next without being pressed,
which is guesswork with a screen reader. Three labelled options state every
choice at once and work by keyboard.

== Fixed in 4.0 ==

THE FRONT PAGE PRINTED ITSELF TWICE
If the brief rail was set to hold nearly as many stories as the whole page
(Customize > Front page set to 9 or 10, with Settings > Reading also at 10),
the column grid below reprinted the entire front page, lead included.
Readers saw every headline twice.

The cause was in WordPress rather than the theme, but the theme was walking
into it. WP_Query::have_posts() does something unexpected when a loop asks
for one post past the end: as well as returning false, it quietly rewinds
the query to the beginning. The rail asked one too many times, the query
rewound, and the next block started again from story one.

Two changes stop it for any combination of settings. The rail is now capped
so it always leaves at least one story for the columns, and its loop checks
its own counter before asking have_posts(), so it can never reach the end.
Nothing depends on the editor choosing sensible numbers any more.

Section fronts use the same template, so category and tag archives are fixed
by the same change.

NO H1 ON THE FRONT PAGE WHEN A LOGO WAS UPLOADED
The masthead switched the paper name between an h1 on the front page and a
paragraph everywhere else, but a site using a custom logo skipped that logic
entirely and put the logo in a plain div. Such a site had no h1 anywhere on
its front page. Every other template carries its own heading, which is why
it went unnoticed. The logo now uses the same h1/p rule as the text version.

VERSION NUMBERS DISAGREED IN THREE PLACES
style.css said 3.3.1, functions.php said 3.1.2 and this file said 3.1.2. The
constant is used as an asset cache-buster, so a stale value could serve an
old stylesheet after an update. All three now read 4.0.0.

== Tested ==
PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5: no compatibility errors.
WordPress 6.2 and up. Nothing in this theme calls an API newer than 6.2,
except wp_robots (5.7) and the script strategy argument (6.3), both of
which degrade quietly on older versions rather than failing.

== What loads on a page ==
1. style.css — inlined, about 5 KB gzipped, so there is no blocking request
2. assets/js/theme.js — about 3.5 KB, deferred
That is the entire theme payload.

To go back to a linked stylesheet (useful behind some CDNs), add this to a
child theme or a small plugin:

    add_filter( 'livingdraft_inline_css', '__return_false' );

== Images ==
Featured images are cut to 1200 x 628 — the same 1.91:1 frame as a share
card, so one upload serves the page, the Open Graph tag and the structured
data with nothing cropped twice.

  livingdraft-lede     1200 x 628   lead story, single stories, pages
  livingdraft-column    600 x 314   the three-column grid
  livingdraft-thumb     400 x 209   story rows on search and author pages

Upload at 1200 x 628 or larger. Anything smaller is upscaled by the browser.

== Fixed in 3.1.1 ==
Found by reading the live site.

  - The sidebar section list was showing the first five categories in the
    ALPHABET, not the five with the most stories. National Affairs, where most
    of the work is filed, was missing entirely. The block version of the
    Categories widget ignores the ordering arguments the classic widget takes,
    so it is now rebuilt from scratch, sorted by how much has been filed.
  - The remaining sections now sit in a "more sections" disclosure instead of
    a "View all sections" link that only went to the homepage. Every section
    stays in the HTML for search engines; a reader sees five.
  - The newsletter form's hidden anti-spam field was positioned 9999px to the
    left, which can drag a horizontal scrollbar onto the page. It is clipped
    now, the same way the rest of the theme hides things.

== Fixed in 3.1 — page speed ==

A PageSpeed report showed Largest Contentful Paint at 4.0 seconds. Almost all
of it was one problem: LiteSpeed Cache was rewriting the lead image to load by
JavaScript. The image itself downloaded in 20 milliseconds; the browser simply
was not allowed to start for 1.4 seconds.

The lead image now carries the exclusion marker for every lazy-loader in
common use — LiteSpeed, WP Rocket, Smush, Jetpack, Autoptimize — and overrides
WordPress's own decision as well. It is also announced in the head with a
preload, so the download can begin before the browser has read that far down
the page.

Also fixed:
  - The inlined stylesheet is squeezed before printing, 28 per cent smaller.
    Comments are for whoever edits the theme, not for a phone on mobile data.
  - The plugin's block stylesheet is inlined too, instead of being a second
    render-blocking request.
  - sizes on the lead image said 100vw. The page is 94vw, so the browser was
    picking a file one size larger than it could ever display.

== What is new in 3.0 ==

Page width is now fluid. It fills 94 per cent of the window and stops at
2100px. This matters more than it sounds: a fixed width looks fine at 100 per
cent zoom and leaves half the screen empty at 80 per cent, because zooming out
gives the page MORE css pixels, not fewer. Both numbers are in
Appearance > Customize > Layout and width.

Also new:
  - Latest-stories strip on the front page. Swipe, trackpad, arrow keys or the
    buttons. Nothing rotates on its own.
  - Latest / Popular / Trending lists.
  - A real footer: policy links, social, colophon.
  - Newsletter band above the footer, storing addresses in your own database.
  - An "In brief" box above articles for the AI key points from The Living Draft Core.
  - The sidebar lists your busiest five sections instead of all of them.
  - Colours, fonts, widths and every page element are now Customizer controls.
  - Nine article blocks in the editor: Key Points, FAQ, Rating, Pros and Cons,
    Timeline, Comparison table, Contents, Section, Author profile.

Fixed in 3.0:
  - Standing Matter used a fixed grid, so a short widget left a tall empty box
    and the next one started far below it. It flows in columns now.
  - The masthead and dateline rules stopped at the content width while the
    navigation rules ran edge to edge. Every band is now full width.

== The page furniture ==
Masthead      Volume number left, title centre, edition line right.
Dateline      Double rule, today's date and your strapline.
Navigation    Centred above 900px, a drawer below it.
Index line    Your most-used tags, as a printed index of contents.
Front page    Lead story with drop cap, brief rail beside it, then the rest
              of the edition in three ruled columns.
Section front Category and tag archives use the same shape.
Author page   Bio and avatar, then everything that writer has filed.

Rules come in three weights and nothing else divides anything: 3px double
for major breaks, 1px solid ink for section heads, a hairline within a block.

== The update log ==
This is what the theme is for. On any post, open "Byline and update log"
below the editor and record what changed and when. Each entry has a kind —
Update, Correction or Clarification — a time, and a note.

The log prints under the byline, oldest first, and the newest entry becomes
the story's dateModified in its structured data. A story edited more than six
hours after publication also carries a "Rev." stamp and a red square before
its headline in lists. To change that window:

    add_filter( 'livingdraft_revision_window', function () {
        return 12 * HOUR_IN_SECONDS;
    } );

The same box holds a second byline name and an editorial label — Opinion,
Analysis, Interview, Review, Explainer or Sponsored. Sponsored content must
be labelled; the label prints beside the kicker above the headline.

== Search and social ==
The theme emits its own Open Graph tags, Twitter card tags and a JSON-LD
graph: WebSite, Organization, NewsArticle, BreadcrumbList and ProfilePage.

If Yoast, Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO
or Squirrly is active, all of that stands down automatically so no tag is
printed twice. To force it either way:

    add_filter( 'livingdraft_output_seo', '__return_false' );

Canonical URLs and noindex on search results come from WordPress core and
are deliberately not duplicated here.

== Block editor ==
The editor canvas is styled to match the page. Six style variations are
registered: Brief (quote), Double rule (separator), Fact box and Correction
notice (group), Ruled frame (image), and Ruled list (list).

Five patterns are in the inserter under "The Living Draft": pull quote, fact box,
correction notice, section divider, and standfirst opening.

== Setting it up ==
1. Appearance > Themes > Add New > Upload Theme > livingdraft.zip > Activate.
2. Appearance > Menus: assign your sections to "Primary menu" and your policy
   pages to "Footer menu". Six or seven top-level sections is what the
   centred navigation is built for.
3. Appearance > Customize > Site Identity: upload your logo, or leave it off
   to set the title in the theme's serif.
4. Appearance > Customize > Masthead: the edition line, the strapline, and
   the year you founded the paper — that last one sets the volume number.
5. Appearance > Customize > Front page: how many stories run in the brief
   rail (0 runs the lead full width), the drop cap, and the newsletter slot.
6. Appearance > Customize > Sharing: a default share image at 1200 x 628 for
   pages with no featured image.
7. Appearance > Widgets: add Search, Recent Posts and Categories to
   "Sidebar". They become the right-hand rail on articles, author pages and
   search, and a three-column "Standing Matter" block at the foot of fronts.
8. Settings > Reading: set "Blog pages show at most" to 10. A front spends
   one on the lead and five on the rail, leaving four for the columns.
9. Users > Profile: write a biography. It becomes the author box under every
   story and the strongest authorship signal you have.

== Writing for it ==
The excerpt is the deck — the italic line under the headline. Write one, or
the lead runs without it.

The first three paragraphs of the lead story are carried on the front page
under the drop cap, so make them count.

Headlines are set in serif at up to 40px. Long ones hold, but a broadsheet
headline is written short: five to nine words.

== Breakpoints ==
600px   masthead becomes a row, two columns, story rows gain their thumbnail
900px   navigation goes horizontal, three columns, the rail appears
1100px  type steps up slightly

Below 600px every float unwinds and the layout is a single column.

== Accessibility ==
Skip link, visible focus rings, 44px touch targets, no furniture below 11px,
aria-current on the active menu item and breadcrumb, focus trapped inside the
open menu drawer, and both prefers-reduced-motion and prefers-contrast
honoured. Wide tables scroll inside a focusable region rather than stretching
the page.

== Translation ==
languages/livingdraft.pot carries all 430 strings, regenerated for 4.0. Drop your .po and .mo
beside it as livingdraft-xx_XX.mo. rtl.css loads automatically on
right-to-left locales.

== Upgrading from 2.0 ==
Settings, menus, widgets and social links all carry over. Two changes need
attention if you have overridden templates in a child theme:

  - Image sizes were re-cut from 16:9 and 4:3 to 1.91:1. Run a thumbnail
    regeneration plugin once so existing featured images get the new crops.
  - template-parts/edition.php is new and now holds the front-page loop that
    used to live in index.php. archive.php delegates to it too.

== Upgrading from 1.x ==
As above, plus: template-parts/secondary.php became template-parts/brief.php,
and template-parts/column.php is new.

== The Living Draft Core (the companion plugin) ==
livingdraft-core.zip holds the update log, editorial labels and second
bylines. Install it and the theme steps aside automatically — no setting to
change, and nothing already logged is lost.

Why it is separate: a theme decides how a site looks, a plugin decides what
it does. Corrections on the record are something the site does, so they must
survive a theme change. With the plugin installed, they will.

The plugin also carries a bridge for Rank Math and Yoast, so you can keep
Rank Math for sitemaps and redirects and still have your corrections reach
Google correctly.

== Child theme ==
livingdraft-child.zip ships alongside this file. Install and activate it
before editing any template, so updates cannot overwrite your work.

== Colours ==
All colours are CSS custom properties at the top of style.css, with dark mode
and high-contrast blocks right below. Change them in one place.
