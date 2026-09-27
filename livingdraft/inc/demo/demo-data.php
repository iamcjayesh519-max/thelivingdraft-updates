<?php
/**
 * What the demo import creates.
 *
 * Separated from the machinery in demo-content.php so the words can be
 * edited without going anywhere near the import logic.
 *
 * === THE RULE FOR WRITING THESE ===
 *
 * Every demo story must teach one thing about the theme. Nobody learns
 * anything from lorem ipsum, and a reader evaluating this theme wants to
 * know what it does, not what Latin looks like at 17px. So each piece is
 * written as a real article whose subject happens to be the theme itself.
 * Read the demo site top to bottom and you have read the manual.
 *
 * The order below is the order they publish in, newest first, because the
 * front page reads from the top. The first entry becomes the lead story.
 *
 * @package LivingDraft
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The sections the demo stories are filed under.
 *
 * @since 4.0.0
 * @return array<string,array{name:string,description:string}>
 */
function livingdraft_demo_categories() {
	return array(
		'reading-this-theme' => array(
			'name'        => __( 'Reading This Theme', 'livingdraft' ),
			'description' => __( 'How the front page, the article page and the furniture are put together.', 'livingdraft' ),
		),
		'on-the-record'      => array(
			'name'        => __( 'On The Record', 'livingdraft' ),
			'description' => __( 'Corrections, update logs and editorial labelling.', 'livingdraft' ),
		),
		'the-desk'           => array(
			'name'        => __( 'The Desk', 'livingdraft' ),
			'description' => __( 'Settings, housekeeping and what to do before you publish for real.', 'livingdraft' ),
		),
	);
}

/**
 * The demo stories.
 *
 * Each entry:
 *   slug        Used for the URL and for matching on re-import.
 *   title       The headline.
 *   excerpt     The deck. This theme prints the excerpt as the italic line
 *               under the headline, so every demo story has one.
 *   category    Key from livingdraft_demo_categories().
 *   tags        Plain names; created if missing.
 *   image       Label drawn onto the generated placeholder, or '' for none.
 *   days_ago    How far back to date it. Controls front-page order.
 *   label       Editorial label meta, or '' — Opinion, Analysis, etc.
 *   coauthor    Second byline name, or ''.
 *   updates     Update-log rows: kind, days_ago, note.
 *   content     Block markup.
 *
 * @since 4.0.0
 * @return array<int,array<string,mixed>>
 */
function livingdraft_demo_stories() {

	$stories = array();

	/* ---------------------------------------------------------------
	 * 1. THE LEAD. Newest, so it takes the drop cap and the big image.
	 * --------------------------------------------------------------- */
	$stories[] = array(
		'slug'     => 'start-here-how-this-front-page-is-built',
		'title'    => __( 'Start here: how this front page is built', 'livingdraft' ),
		'excerpt'  => __( 'The story you are reading is the lead. Everything to its right is the brief rail. Below, the edition runs in ruled columns. Here is what controls each part.', 'livingdraft' ),
		'category' => 'reading-this-theme',
		'tags'     => array( 'Front page', 'Getting started' ),
		'image'    => 'The Lead Story',
		'days_ago' => 0,
		'label'    => '',
		'coauthor' => '',
		'updates'  => array(),
		'content'  => '
<!-- wp:paragraph -->
<p>This is the lead story. It takes the widest column, the largest headline and the drop cap on its opening paragraph. Whichever story is newest becomes the lead automatically, so you never have to mark one.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>The italic line under the headline is not written into the article. It is the excerpt. Write one for every story, or the headline sits directly on the body text and the page loses its opening breath.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>What each part of the front page is</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul>
<li><strong>The masthead</strong> carries the volume number on the left, the paper name in the centre and the edition line on the right. The volume number counts up from the year you founded the paper.</li>
<li><strong>The dateline</strong> is the double-ruled strip under it, holding today\'s date and your strapline.</li>
<li><strong>The index line</strong> lists your most-used tags, the way a printed paper lists its contents.</li>
<li><strong>The brief rail</strong> is the narrow column beside the lead. It holds the next few stories in short form.</li>
<li><strong>The edition</strong> is the ruled three-column grid below, holding everything the lead and the rail did not use.</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>One setting worth understanding</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Under <strong>Appearance &rarr; Customize &rarr; Front page</strong> you decide how many stories run in the brief rail. Under <strong>Settings &rarr; Reading</strong> you decide how many stories the page holds in total. The rail can never take so many that nothing is left for the columns; the theme caps it for you. Five in the rail against ten on the page is the shape this layout was drawn for.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Set the rail to zero and the lead runs the full width of the page instead. That is a legitimate front page too, and worth trying on a day when one story matters more than the rest.</p>
<!-- /wp:paragraph -->
',
	);

	/* ---------------------------------------------------------------
	 * 2. The update log. The reason this theme exists.
	 * --------------------------------------------------------------- */
	$stories[] = array(
		'slug'     => 'how-a-correction-is-published-here',
		'title'    => __( 'How a correction is published on this site', 'livingdraft' ),
		'excerpt'  => __( 'Most sites edit a story quietly and hope nobody noticed. This one keeps a log under the byline. Scroll up: this story carries three entries.', 'livingdraft' ),
		'category' => 'on-the-record',
		'tags'     => array( 'Corrections', 'Getting started' ),
		'image'    => 'On The Record',
		'days_ago' => 1,
		'label'    => '',
		'coauthor' => '',
		'updates'  => array(
			array(
				'kind'     => 'correction',
				'days_ago' => 1,
				'note'     => __( 'An earlier version of this story said the update log appears at the foot of the article. It appears under the byline, above the body text.', 'livingdraft' ),
			),
			array(
				'kind'     => 'clarification',
				'days_ago' => 1,
				'note'     => __( 'Added that the newest entry becomes the story\'s dateModified in its structured data.', 'livingdraft' ),
			),
			array(
				'kind'     => 'update',
				'days_ago' => 1,
				'note'     => __( 'Third entry, added to show how a log of several entries reads. They print oldest first.', 'livingdraft' ),
			),
		),
		'content'  => '
<!-- wp:paragraph -->
<p>Look under the byline of this story. Three entries are logged there: a correction, a clarification and an update. That block is the whole point of this theme, and it is the one feature most likely to go unnoticed, because a site with nothing logged shows nothing at all.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Where to write one</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Open any post for editing and look below the editor for the panel marked <strong>Byline and update log</strong>. Each entry has three parts: what kind of change it was, when it happened, and a plain sentence saying what changed. Write the sentence for a reader, not for yourself. "Corrected the minister\'s name" is useful. "Fixed typo" is not.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul>
<li><strong>Correction</strong> — the story said something untrue.</li>
<li><strong>Clarification</strong> — the story was true but read as though it meant something else.</li>
<li><strong>Update</strong> — the story was right and something has since happened.</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>What it does beyond the page</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>The newest entry becomes the story\'s modification date in the structured data search engines read, so the log is not decoration. A story edited more than six hours after it ran also carries a small revision stamp, and a red square appears beside its headline in every list on the site.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>The log lives in The Living Draft Core plugin, not in the theme. That is deliberate. Change the theme next year and every correction you have ever published stays exactly where it is.</p>
<!-- /wp:paragraph -->
',
	);

	/* ---------------------------------------------------------------
	 * 3. Editorial labels. Carries a label so the kicker area is populated.
	 * --------------------------------------------------------------- */
	$stories[] = array(
		'slug'     => 'labelling-opinion-analysis-and-sponsored-work',
		'title'    => __( 'Labelling opinion, analysis and sponsored work', 'livingdraft' ),
		'excerpt'  => __( 'Reporting and opinion should not look identical. This story is labelled Analysis; the label prints beside the section name above the headline.', 'livingdraft' ),
		'category' => 'on-the-record',
		'tags'     => array( 'Editorial standards' ),
		'image'    => 'Analysis',
		'days_ago' => 2,
		'label'    => 'analysis',
		'coauthor' => '',
		'updates'  => array(),
		'content'  => '
<!-- wp:paragraph -->
<p>Above this headline you will see the section name, and beside it the word <strong>Analysis</strong>. That is an editorial label, set on this story in the same panel as the update log.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Six labels ship with the theme: Opinion, Analysis, Interview, Review, Explainer and Sponsored. Leave a story unlabelled and it is straight reporting, which is what most stories are.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Sponsored work is not optional</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>If a story was paid for, label it Sponsored. This is a legal requirement in most countries and an ethical one everywhere. The label is printed in the same place as the others, before the headline, where a reader sees it before they have read a word of the piece rather than after.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>The purpose of a label is not decoration. It tells a reader in one word what kind of thing they are about to read, so they can weigh it correctly. A paper that labels its opinion is trusted more, not less.</p>
<!-- /wp:paragraph -->
',
	);

	/* ---------------------------------------------------------------
	 * 4. Long piece, showing the plugin blocks. Also a second byline.
	 * --------------------------------------------------------------- */
	$stories[] = array(
		'slug'     => 'the-furniture-key-points-contents-and-tables',
		'title'    => __( 'The furniture: key points, contents and comparison tables', 'livingdraft' ),
		'excerpt'  => __( 'A long story needs somewhere for a hurried reader to land. These are the blocks that give them one, and where to find each in the editor.', 'livingdraft' ),
		'category' => 'reading-this-theme',
		'tags'     => array( 'Blocks', 'Writing' ),
		'image'    => 'The Furniture',
		'days_ago' => 3,
		'label'    => '',
		'coauthor' => 'The Desk',
		'updates'  => array(),
		'content'  => '
<!-- wp:paragraph -->
<p>This story carries two names in its byline. The second is set in the <strong>Byline and update log</strong> panel and is useful for pieces with a contributing reporter, or for work filed by a desk rather than a person.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Key points, at the top</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>The single most valuable block on a long story is a short summary near the top. A reader arriving from a search result wants one fact. Give it to them in fifteen seconds and they will stay for the rest; make them scroll for it and they will leave.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul>
<li>Insert it from the block inserter under <strong>The Living Draft</strong>.</li>
<li>Six to eight lines is the working range. Twelve is a second article.</li>
<li>Each line should be a fact, not a topic. "Prices rose 14 per cent" beats "Prices".</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2>Contents, for anything long</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Any story running past about a thousand words earns a contents block. It builds itself from your headings, so the only thing you have to do is write sensible headings in the first place.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>What else is in the inserter</h2>
<!-- /wp:heading -->

<!-- wp:table -->
<figure class="wp-block-table"><table><thead><tr><th>Block</th><th>Use it for</th></tr></thead><tbody>
<tr><td>Key Points</td><td>A summary at the top of a long story</td></tr>
<tr><td>Contents</td><td>Navigation within a long story</td></tr>
<tr><td>FAQ</td><td>Questions readers actually ask, in their words</td></tr>
<tr><td>Pros and Cons</td><td>Reviews and comparisons</td></tr>
<tr><td>Comparison</td><td>Two or more things side by side</td></tr>
<tr><td>Timeline</td><td>A story that unfolded over months</td></tr>
<tr><td>Rating</td><td>Reviews with a score</td></tr>
<tr><td>Author profile</td><td>Establishing who wrote this and why they would know</td></tr>
</tbody></table></figure>
<!-- /wp:table -->

<!-- wp:paragraph -->
<p>The FAQ and Rating blocks also produce structured data, which is how a search engine can show your answers directly in its results. That only works if the questions are real ones. Inventing questions nobody asked produces markup that is technically valid and practically worthless.</p>
<!-- /wp:paragraph -->
',
	);

	/* ---------------------------------------------------------------
	 * 5. Light and dark. The new 4.0 control.
	 * --------------------------------------------------------------- */
	$stories[] = array(
		'slug'     => 'light-dark-and-letting-readers-choose',
		'title'    => __( 'Light, dark, and letting readers choose', 'livingdraft' ),
		'excerpt'  => __( 'New in version 4.0. The control sits in the masthead beside search. Here is what each of the three settings does, and which one to make your default.', 'livingdraft' ),
		'category' => 'reading-this-theme',
		'tags'     => array( 'Design', 'Getting started' ),
		'image'    => 'Light and Dark',
		'days_ago' => 4,
		'label'    => '',
		'coauthor' => '',
		'updates'  => array(),
		'content'  => '
<!-- wp:paragraph -->
<p>Look at the navigation bar, to the right of the sections, just before the search icon. Three small buttons: a sun, a moon and a screen. Press one and the page changes immediately. Press the screen and the page goes back to following whatever your phone or computer is set to.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Whatever you choose is remembered in your own browser. Open another story and it is still applied, with no flash of the wrong colour in between.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Two separate decisions</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Under <strong>Appearance &rarr; Customize &rarr; Light and dark</strong> there are two settings, and they answer different questions.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul>
<li><strong>How the paper opens</strong> decides what a reader sees on their very first visit, before they have chosen anything. "Match my device" is the sensible default for most papers.</li>
<li><strong>Let readers choose</strong> shows or hides the control itself. Turn it off and the paper is locked to whatever you picked above.</li>
</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>A reader who has made a choice keeps it. Changing the site default afterwards does not overrule them, and it should not.</p>
<!-- /wp:paragraph -->
',
	);

	/* ---------------------------------------------------------------
	 * 6. Writing for the layout.
	 * --------------------------------------------------------------- */
	$stories[] = array(
		'slug'     => 'writing-a-headline-a-kicker-and-a-deck',
		'title'    => __( 'Writing a headline, a kicker and a deck', 'livingdraft' ),
		'excerpt'  => __( 'Three short pieces of text sit above every story. Each does a different job, and the layout was drawn assuming you would write all three.', 'livingdraft' ),
		'category' => 'the-desk',
		'tags'     => array( 'Writing' ),
		'image'    => '',
		'days_ago' => 5,
		'label'    => '',
		'coauthor' => '',
		'updates'  => array(),
		'content'  => '
<!-- wp:paragraph -->
<p>This story has no featured image, which is worth seeing at least once. The layout does not break; it simply closes up. But it is quieter in every list on the site, so use the absence deliberately rather than by accident.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>The kicker</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>The small line above the headline. It is your section name, printed automatically. You do not write it, but you do choose it, by filing the story in the right place.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>The headline</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Set in serif at up to forty pixels. Long headlines hold without breaking the layout, but a broadsheet headline is written short: five to nine words. If yours needs fifteen, the second half is probably the deck.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>The deck</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>The italic line under the headline. In WordPress this is the excerpt field, and it is the one most often left empty. Write it. It is the sentence that decides whether someone reads the story, it is what appears under your headline in every list on this site, and it is what search engines and social platforms quote when they show your work.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>One more thing the front page takes: the first three paragraphs of the lead story are carried on the front under the drop cap. Whatever you write at the top of your newest story is what visitors read first. Make those three count.</p>
<!-- /wp:paragraph -->
',
	);

	/* ---------------------------------------------------------------
	 * 7. Housekeeping, and how to get rid of all this.
	 * --------------------------------------------------------------- */
	$stories[] = array(
		'slug'     => 'before-you-publish-for-real',
		'title'    => __( 'Before you publish for real', 'livingdraft' ),
		'excerpt'  => __( 'A short list of things to set, and one button to press, before this demo content stops being helpful and starts being embarrassing.', 'livingdraft' ),
		'category' => 'the-desk',
		'tags'     => array( 'Getting started' ),
		'image'    => 'The Desk',
		'days_ago' => 6,
		'label'    => '',
		'coauthor' => '',
		'updates'  => array(),
		'content'  => '
<!-- wp:paragraph -->
<p>Everything on this site right now is demo content. None of it is indexed by search engines, and all of it can be removed in one press.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Removing it</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Go to <strong>Appearance &rarr; Demo Content</strong> and press Remove. Every story, page, section, tag and image this import created is deleted, and any setting it changed is put back the way it was. Anything you wrote yourself is untouched, including edits you made to these demo stories.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>What to set before you write</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul>
<li><strong>Site Identity</strong> — upload your logo, or leave it off and let the paper name set in serif.</li>
<li><strong>Masthead</strong> — the edition line, the strapline, and the year you founded the paper. That last one sets your volume number.</li>
<li><strong>Users &rarr; Profile</strong> — write a biography. It becomes the author box under every story and it is the strongest authorship signal you have.</li>
<li><strong>Settings &rarr; Reading</strong> — ten stories per page suits this layout.</li>
<li><strong>Appearance &rarr; Menus</strong> — six or seven top-level sections is what the centred navigation was drawn for.</li>
</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>One habit worth forming on day one: when you change a published story in any way that matters, log it. A paper that corrects openly is worth more than one that never appears to be wrong.</p>
<!-- /wp:paragraph -->
',
	);

	return $stories;
}

/**
 * Pages the demo creates, so the footer policy links resolve.
 *
 * @since 4.0.0
 * @return array<int,array<string,string>>
 */
function livingdraft_demo_pages() {
	return array(
		array(
			'slug'    => 'demo-about',
			'title'   => __( 'About this paper', 'livingdraft' ),
			'content' => '
<!-- wp:paragraph -->
<p>Replace this page with a description of who writes here, what the paper covers, and how it is funded. Readers and search engines both weigh it heavily, and it is usually the second page a new reader opens.</p>
<!-- /wp:paragraph -->
',
		),
		array(
			'slug'    => 'demo-corrections-policy',
			'title'   => __( 'Corrections policy', 'livingdraft' ),
			'content' => '
<!-- wp:paragraph -->
<p>This theme keeps a public log of changes on every story. A corrections policy explains to readers what that log means and how to ask for a correction. Replace this text with yours.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>A workable policy answers three questions: how a reader reports an error, how quickly you respond, and what the difference is between a correction, a clarification and an update.</p>
<!-- /wp:paragraph -->
',
		),
		array(
			'slug'    => 'demo-contact',
			'title'   => __( 'Contact the desk', 'livingdraft' ),
			'content' => '
<!-- wp:paragraph -->
<p>An email address is enough. Replace this page with yours.</p>
<!-- /wp:paragraph -->
',
		),
	);
}
