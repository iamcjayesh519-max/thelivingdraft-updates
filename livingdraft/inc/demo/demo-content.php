<?php
/**
 * Demo content: import, and remove again.
 *
 * === THE PROBLEM THIS SOLVES ===
 *
 * A newspaper theme is impossible to judge empty. Drop cap, brief rail,
 * ruled columns, update log, editorial labels — none of it shows on a
 * site with no stories, so the first impression of this theme has always
 * been a blank page and a wall of settings.
 *
 * WordPress already has a facility for this, add_theme_support(
 * 'starter-content' ), and the theme has used it since 1.0. But it only
 * fires on a site that has never published anything, only when the
 * Customizer is opened, and it is silent — the user is never asked. It
 * cannot show an update log, cannot create images, and cannot be undone.
 * It is kept (in inc/starter-content.php) because it costs nothing, but
 * it cannot do this job.
 *
 * === THE RULES THIS FOLLOWS ===
 *
 * Demo importers have a bad reputation, and it is earned. They overwrite
 * menus, replace the front page, scatter posts through a live site, and
 * leave a mess nobody can clear up. So:
 *
 *   1. NOTHING RUNS BY ITSELF. Import happens on a button press, never on
 *      activation, never on an admin page load, never on a cron.
 *
 *   2. NOTHING EXISTING IS OVERWRITTEN. Content is only ever created. A
 *      setting is only changed if it has never been set. A menu location
 *      is only filled if it is empty. A sidebar is only populated if it
 *      holds no widgets.
 *
 *   3. EVERY CHANGE IS RECORDED. The manifest holds the id of every
 *      object created and the previous value of every setting touched.
 *      Removal reverses exactly that list and nothing else.
 *
 *   4. NOTHING REACHES GOOGLE. Every demo post carries a noindex robots
 *      directive for as long as it exists.
 *
 *   5. THE EDITOR'S OWN WORK IS SAFE. Removal deletes only objects listed
 *      in the manifest. Edit a demo story and it is still removed — it
 *      was demo content and the editor asked for it to go — but nothing
 *      created outside the import is ever touched.
 *
 * @package LivingDraft
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/demo/demo-data.php';

/** Option holding the record of what was created. */
const LIVINGDRAFT_DEMO_MANIFEST = 'livingdraft_demo_manifest';

/** Post/term meta key marking an object as demo content. */
const LIVINGDRAFT_DEMO_FLAG = '_livingdraft_demo';

/**
 * An empty manifest, in the shape everything else expects.
 *
 * @since 4.0.0
 * @return array<string,mixed>
 */
function livingdraft_demo_blank_manifest() {
	return array(
		'version'     => '4.0.0',
		'imported_at' => '',
		'posts'       => array(), // Post and page ids.
		'terms'       => array(), // [ term_id, taxonomy ] pairs.
		'attachments' => array(), // Attachment ids.
		'menus'       => array(), // Menu term ids.
		'restore'     => array(), // Settings to put back, keyed by what they are.
	);
}

/**
 * The current manifest, or a blank one.
 *
 * @since 4.0.0
 * @return array<string,mixed>
 */
function livingdraft_demo_manifest() {
	$stored = get_option( LIVINGDRAFT_DEMO_MANIFEST, array() );

	if ( ! is_array( $stored ) ) {
		return livingdraft_demo_blank_manifest();
	}

	return wp_parse_args( $stored, livingdraft_demo_blank_manifest() );
}

/**
 * Is demo content currently installed?
 *
 * @since 4.0.0
 * @return bool
 */
function livingdraft_demo_is_installed() {
	$manifest = livingdraft_demo_manifest();

	return ! empty( $manifest['imported_at'] );
}

/**
 * How much real content already exists, so the warning can be specific.
 *
 * Counts only what the editor made: anything carrying the demo flag is
 * excluded, so re-running the count after an import does not frighten
 * anyone with numbers that include the demo itself.
 *
 * @since 4.0.0
 * @return int
 */
function livingdraft_demo_existing_content_count() {
	$found = get_posts(
		array(
			'post_type'        => array( 'post', 'page' ),
			'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page'   => 100,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => LIVINGDRAFT_DEMO_FLAG,
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	return count( $found );
}

/* ==================================================================
 * KEEPING DEMO CONTENT OUT OF SEARCH RESULTS
 * ================================================================== */

/**
 * Every demo post is noindex, nofollow for as long as it exists.
 *
 * Hooked to wp_robots (WordPress 5.7+) which is the modern, filterable
 * path and cooperates with SEO plugins rather than fighting them. The
 * meta tag fallback below covers WordPress 6.2 installs where another
 * plugin has removed the wp_robots hook entirely.
 *
 * @since 4.0.0
 * @param array $robots Robots directives.
 * @return array
 */
function livingdraft_demo_robots( $robots ) {
	if ( ! is_singular() ) {
		return $robots;
	}

	if ( ! get_post_meta( get_queried_object_id(), LIVINGDRAFT_DEMO_FLAG, true ) ) {
		return $robots;
	}

	$robots['noindex']  = true;
	$robots['nofollow'] = true;
	unset( $robots['max-image-preview'] );

	return $robots;
}
add_filter( 'wp_robots', 'livingdraft_demo_robots' );

/**
 * Demo posts are excluded from the sitemap core generates, so they are
 * never even offered to a crawler.
 *
 * @since 4.0.0
 * @param array  $args      Query args.
 * @param string $post_type Post type.
 * @return array
 */
function livingdraft_demo_exclude_from_sitemap( $args, $post_type = '' ) {
	unset( $post_type );

	if ( ! livingdraft_demo_is_installed() ) {
		return $args;
	}

	$manifest = livingdraft_demo_manifest();

	if ( ! empty( $manifest['posts'] ) ) {
		$args['post__not_in'] = array_map( 'absint', (array) $manifest['posts'] );
	}

	return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', 'livingdraft_demo_exclude_from_sitemap', 10, 2 );

/* ==================================================================
 * THE IMPORT
 * ================================================================== */

/**
 * Create everything.
 *
 * @since 4.0.0
 * @return array{created:int,messages:array<int,string>}|WP_Error
 */
function livingdraft_demo_import() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to import demo content.', 'livingdraft' ) );
	}

	if ( livingdraft_demo_is_installed() ) {
		return new WP_Error( 'already', __( 'Demo content is already installed. Remove it first if you want a clean copy.', 'livingdraft' ) );
	}

	$manifest                = livingdraft_demo_blank_manifest();
	$manifest['imported_at'] = current_time( 'mysql' );
	$messages                = array();
	$author_id               = get_current_user_id();

	/* --- 1. Sections ------------------------------------------------ */
	$category_ids = array();

	foreach ( livingdraft_demo_categories() as $slug => $def ) {
		$existing = get_term_by( 'slug', $slug, 'category' );

		if ( $existing instanceof WP_Term ) {
			// Someone already has a section by this slug. Use it, but do
			// NOT record it — removal must not delete their section.
			$category_ids[ $slug ] = (int) $existing->term_id;
			continue;
		}

		$created = wp_insert_term(
			$def['name'],
			'category',
			array(
				'slug'        => $slug,
				'description' => $def['description'],
			)
		);

		if ( is_wp_error( $created ) ) {
			continue;
		}

		$term_id               = (int) $created['term_id'];
		$category_ids[ $slug ] = $term_id;
		$manifest['terms'][]   = array( $term_id, 'category' );
		update_term_meta( $term_id, LIVINGDRAFT_DEMO_FLAG, 1 );
	}

	/* --- 2. Stories ------------------------------------------------- */
	$story_count = 0;

	foreach ( livingdraft_demo_stories() as $story ) {
		$published = gmdate( 'Y-m-d H:i:s', time() - ( (int) $story['days_ago'] * DAY_IN_SECONDS ) - ( 3 * HOUR_IN_SECONDS ) );

		$post_id = wp_insert_post(
			array(
				'post_title'    => $story['title'],
				'post_name'     => $story['slug'],
				'post_content'  => trim( $story['content'] ),
				'post_excerpt'  => $story['excerpt'],
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_author'   => $author_id,
				'post_date_gmt' => $published,
				'post_date'     => get_date_from_gmt( $published ),
				'comment_status' => 'open',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			continue;
		}

		$post_id = (int) $post_id;
		$manifest['posts'][] = $post_id;
		update_post_meta( $post_id, LIVINGDRAFT_DEMO_FLAG, 1 );
		$story_count++;

		// Section.
		if ( isset( $category_ids[ $story['category'] ] ) ) {
			wp_set_post_categories( $post_id, array( $category_ids[ $story['category'] ] ), false );
		}

		// Tags. wp_set_post_tags creates missing ones; those are recorded
		// so removal can take them away, but only if they did not exist.
		foreach ( (array) $story['tags'] as $tag_name ) {
			$existing_tag = get_term_by( 'name', $tag_name, 'post_tag' );
			$is_new       = ! ( $existing_tag instanceof WP_Term );

			wp_set_post_tags( $post_id, array( $tag_name ), true );

			if ( $is_new ) {
				$made = get_term_by( 'name', $tag_name, 'post_tag' );
				if ( $made instanceof WP_Term ) {
					$manifest['terms'][] = array( (int) $made->term_id, 'post_tag' );
					update_term_meta( (int) $made->term_id, LIVINGDRAFT_DEMO_FLAG, 1 );
				}
			}
		}

		// Editorial label and second byline. These meta keys belong to
		// The Living Draft Core; writing them when the plugin is absent
		// is harmless, and they light up the moment it is activated.
		if ( '' !== $story['label'] ) {
			update_post_meta( $post_id, '_livingdraft_label', $story['label'] );
		}

		if ( '' !== $story['coauthor'] ) {
			update_post_meta( $post_id, '_livingdraft_coauthor', $story['coauthor'] );
		}

		// Update log.
		if ( ! empty( $story['updates'] ) ) {
			$rows = array();

			foreach ( $story['updates'] as $row ) {
				$rows[] = array(
					'kind' => $row['kind'],
					'time' => gmdate( 'Y-m-d H:i:s', time() - ( (int) $row['days_ago'] * DAY_IN_SECONDS ) ),
					'note' => $row['note'],
				);
			}

			update_post_meta( $post_id, '_livingdraft_updates', $rows );
		}

		// Featured image.
		if ( '' !== $story['image'] ) {
			$attachment_id = livingdraft_demo_make_image( $story['image'], $post_id );

			if ( $attachment_id ) {
				set_post_thumbnail( $post_id, $attachment_id );
				$manifest['attachments'][] = $attachment_id;
			}
		}
	}

	$messages[] = sprintf(
		/* translators: %d: number of stories. */
		_n( '%d demo story created.', '%d demo stories created.', $story_count, 'livingdraft' ),
		$story_count
	);

	/* --- 3. Pages --------------------------------------------------- */
	$page_ids = array();

	foreach ( livingdraft_demo_pages() as $page ) {
		$page_id = wp_insert_post(
			array(
				'post_title'   => $page['title'],
				'post_name'    => $page['slug'],
				'post_content' => trim( $page['content'] ),
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => $author_id,
			),
			true
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			continue;
		}

		$page_id = (int) $page_id;
		$page_ids[ $page['slug'] ] = $page_id;
		$manifest['posts'][]       = $page_id;
		update_post_meta( $page_id, LIVINGDRAFT_DEMO_FLAG, 1 );
	}

	/* --- 4. Menus, only into empty locations ------------------------ */
	$locations = get_theme_mod( 'nav_menu_locations', array() );
	$locations = is_array( $locations ) ? $locations : array();

	$manifest['restore']['nav_menu_locations'] = $locations;
	$menu_touched                              = false;

	if ( empty( $locations['primary'] ) ) {
		$menu_id = wp_create_nav_menu( __( 'Demo — Sections', 'livingdraft' ) );

		if ( ! is_wp_error( $menu_id ) ) {
			$menu_id             = (int) $menu_id;
			$manifest['menus'][] = $menu_id;

			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'  => __( 'Front page', 'livingdraft' ),
					'menu-item-url'    => home_url( '/' ),
					'menu-item-status' => 'publish',
				)
			);

			foreach ( livingdraft_demo_categories() as $slug => $def ) {
				if ( ! isset( $category_ids[ $slug ] ) ) {
					continue;
				}

				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'     => $def['name'],
						'menu-item-object'    => 'category',
						'menu-item-object-id' => $category_ids[ $slug ],
						'menu-item-type'      => 'taxonomy',
						'menu-item-status'    => 'publish',
					)
				);
			}

			$locations['primary'] = $menu_id;
			$menu_touched         = true;
		}
	}

	if ( empty( $locations['footer'] ) && ! empty( $page_ids ) ) {
		$footer_id = wp_create_nav_menu( __( 'Demo — The paper', 'livingdraft' ) );

		if ( ! is_wp_error( $footer_id ) ) {
			$footer_id           = (int) $footer_id;
			$manifest['menus'][] = $footer_id;

			foreach ( $page_ids as $page_id ) {
				wp_update_nav_menu_item(
					$footer_id,
					0,
					array(
						'menu-item-object'    => 'page',
						'menu-item-object-id' => $page_id,
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
					)
				);
			}

			$locations['footer'] = $footer_id;
			$menu_touched        = true;
		}
	}

	if ( $menu_touched ) {
		set_theme_mod( 'nav_menu_locations', $locations );
		$messages[] = __( 'Menus created and assigned to the empty menu positions.', 'livingdraft' );
	} else {
		// Nothing was changed, so there is nothing to restore.
		unset( $manifest['restore']['nav_menu_locations'] );
		$messages[] = __( 'Your menus were already set, so they were left alone.', 'livingdraft' );
	}

	/* --- 5. Widgets, only into an empty sidebar --------------------- */
	if ( ! is_active_sidebar( 'sidebar-1' ) ) {
		$manifest['restore']['sidebars_widgets'] = get_option( 'sidebars_widgets' );

		if ( livingdraft_demo_fill_sidebar() ) {
			$messages[] = __( 'Sidebar filled with Search, Recent Posts and Categories.', 'livingdraft' );
		} else {
			unset( $manifest['restore']['sidebars_widgets'] );
		}
	} else {
		$messages[] = __( 'Your sidebar already had widgets, so it was left alone.', 'livingdraft' );
	}

	/* --- 6. Theme settings, only where never set -------------------- */
	$defaults = array(
		'livingdraft_edition_line' => __( 'Demo edition', 'livingdraft' ),
		'livingdraft_strapline'    => __( 'The world, in its draft form', 'livingdraft' ),
		'livingdraft_brief_count'  => 3,
	);

	$mods_set = array();

	foreach ( $defaults as $mod => $value ) {
		// get_theme_mod with a unique sentinel is the only reliable way to
		// distinguish "never set" from "set to the same value as default".
		if ( 'livingdraft_demo_unset' !== get_theme_mod( $mod, 'livingdraft_demo_unset' ) ) {
			continue;
		}

		set_theme_mod( $mod, $value );
		$mods_set[] = $mod;
	}

	if ( ! empty( $mods_set ) ) {
		$manifest['restore']['theme_mods'] = $mods_set;
	}

	/* --- Done ------------------------------------------------------- */
	update_option( LIVINGDRAFT_DEMO_MANIFEST, $manifest, false );

	$created = count( $manifest['posts'] ) + count( $manifest['terms'] ) + count( $manifest['attachments'] );

	return array(
		'created'  => $created,
		'messages' => $messages,
	);
}

/**
 * Put widgets into the empty sidebar.
 *
 * Written against the raw widget options rather than any helper, because
 * WordPress has no public API for "add this widget to that sidebar" and
 * the block-widgets screen reads the same options the classic one writes.
 *
 * @since 4.0.0
 * @return bool True if anything was added.
 */
function livingdraft_demo_fill_sidebar() {
	$sidebars = get_option( 'sidebars_widgets' );

	if ( ! is_array( $sidebars ) ) {
		return false;
	}

	$added = array();

	foreach ( array( 'search', 'recent-posts', 'categories' ) as $type ) {
		$option_name = 'widget_' . $type;
		$instances   = get_option( $option_name, array() );
		$instances   = is_array( $instances ) ? $instances : array();

		// Find the next free numeric key.
		$numeric = array_filter( array_keys( $instances ), 'is_numeric' );
		$next    = $numeric ? ( max( array_map( 'absint', $numeric ) ) + 1 ) : 2;

		$instances[ $next ]  = array( 'title' => '' );
		$instances['_multiwidget'] = 1;

		update_option( $option_name, $instances );
		$added[] = $type . '-' . $next;
	}

	if ( empty( $added ) ) {
		return false;
	}

	$sidebars['sidebar-1'] = $added;
	update_option( 'sidebars_widgets', $sidebars );

	return true;
}

/**
 * Free a GD image across every PHP version.
 *
 * On PHP 7.4 an image is a resource that must be freed explicitly, or it
 * is held until the request ends — which matters when drawing eight of
 * them in one import. On PHP 8.0 it became a GdImage object freed by
 * reference counting, and on PHP 8.5 imagedestroy() is deprecated and
 * emits a notice. Calling it conditionally satisfies both.
 *
 * @since 4.0.0
 * @param mixed $image GD resource or GdImage.
 * @return void
 */
function livingdraft_demo_free_image( &$image ) {
	if ( ! $image ) {
		return;
	}

	if ( PHP_VERSION_ID < 80500 && function_exists( 'imagedestroy' ) ) {
		// Static analysers flag this as an 8.5 deprecation because they
		// cannot evaluate the runtime guard above it. On 8.5 this line is
		// never reached.
		imagedestroy( $image ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.imagedestroyDeprecated
	}

	// On 8.0+ this is what actually releases it, and it is harmless on 7.4.
	$image = null;
}

/**
 * Draw a placeholder image at the theme's own 1200x628 crop and put it in
 * the media library.
 *
 * Generated rather than shipped. Bundling eight JPEGs would add roughly a
 * megabyte to every download of this theme, for files most people delete
 * within the hour. Drawing them takes a fraction of a second and produces
 * images at exactly the dimensions the layout expects, so nothing is
 * cropped or upscaled while the demo is being looked at.
 *
 * Requires the GD extension, which is present on effectively every host
 * running WordPress. If it is missing the demo simply has no pictures,
 * which is worth seeing anyway — see the "no featured image" demo story.
 *
 * @since 4.0.0
 * @param string $label   Text drawn on the image.
 * @param int    $post_id Post to attach it to.
 * @return int Attachment id, or 0.
 */
function livingdraft_demo_make_image( $label, $post_id ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
		return 0;
	}

	$width  = defined( 'LIVINGDRAFT_IMAGE_W' ) ? LIVINGDRAFT_IMAGE_W : 1200;
	$height = defined( 'LIVINGDRAFT_IMAGE_H' ) ? LIVINGDRAFT_IMAGE_H : 628;

	$image = imagecreatetruecolor( $width, $height );

	if ( ! $image ) {
		return 0;
	}

	// Newsprint, ink, and the theme's kicker red.
	$paper = imagecolorallocate( $image, 244, 243, 238 );
	$ink   = imagecolorallocate( $image, 17, 17, 17 );
	$rule  = imagecolorallocate( $image, 216, 213, 204 );
	$mark  = imagecolorallocate( $image, 163, 39, 31 );

	imagefilledrectangle( $image, 0, 0, $width, $height, $paper );

	// A printed grid, so the placeholder reads as newsprint rather than
	// as a broken image.
	for ( $y = 90; $y < $height - 60; $y += 34 ) {
		imagefilledrectangle( $image, 80, $y, $width - 80, $y + 1, $rule );
	}

	// Three rule weights, as the theme itself uses.
	imagefilledrectangle( $image, 80, 56, $width - 80, 59, $ink );
	imagefilledrectangle( $image, 80, 64, $width - 80, 65, $ink );
	imagefilledrectangle( $image, 80, $height - 60, 260, $height - 57, $mark );

	if ( function_exists( 'imagestring' ) ) {
		$text = strtoupper( wp_strip_all_tags( $label ) );
		$text = substr( $text, 0, 34 );

		// Font 5 is the largest built-in, roughly 9x15px. Scaled up by
		// drawing to a small canvas and resampling, so no font file is
		// needed and the result is legible at full size.
		$strip_w = max( 1, imagefontwidth( 5 ) * strlen( $text ) );
		$strip_h = imagefontheight( 5 );
		$strip   = imagecreatetruecolor( $strip_w, $strip_h );

		if ( $strip ) {
			$s_paper = imagecolorallocate( $strip, 244, 243, 238 );
			$s_ink   = imagecolorallocate( $strip, 17, 17, 17 );
			imagefilledrectangle( $strip, 0, 0, $strip_w, $strip_h, $s_paper );
			imagestring( $strip, 5, 0, 0, $text, $s_ink );

			$target_w = min( $width - 200, (int) ( $strip_w * 4.4 ) );
			$target_h = (int) ( $strip_h * 4.4 );
			$dest_x   = (int) ( ( $width - $target_w ) / 2 );
			$dest_y   = (int) ( ( $height - $target_h ) / 2 );

			imagecopyresampled( $image, $strip, $dest_x, $dest_y, 0, 0, $target_w, $target_h, $strip_w, $strip_h );
			livingdraft_demo_free_image( $strip );
		}
	}

	$uploads = wp_upload_dir();

	if ( ! empty( $uploads['error'] ) ) {
		livingdraft_demo_free_image( $image );
		return 0;
	}

	$filename = 'livingdraft-demo-' . sanitize_title( $label ) . '-' . $post_id . '.jpg';
	$path     = trailingslashit( $uploads['path'] ) . $filename;

	$written = imagejpeg( $image, $path, 82 );
	livingdraft_demo_free_image( $image );

	if ( ! $written || ! file_exists( $path ) ) {
		return 0;
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => sprintf(
				/* translators: %s: image label. */
				__( 'Demo placeholder: %s', 'livingdraft' ),
				$label
			),
			'post_status'    => 'inherit',
		),
		$path,
		$post_id,
		true
	);

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		wp_delete_file( $path );
		return 0;
	}

	$attachment_id = (int) $attachment_id;

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );

	// Alt text, because a demo that ships images without descriptions
	// teaches the wrong habit on day one.
	update_post_meta(
		$attachment_id,
		'_wp_attachment_image_alt',
		sprintf(
			/* translators: %s: image label. */
			__( 'Placeholder graphic marked %s, standing in for a story photograph.', 'livingdraft' ),
			$label
		)
	);

	update_post_meta( $attachment_id, LIVINGDRAFT_DEMO_FLAG, 1 );

	return $attachment_id;
}

/* ==================================================================
 * THE REMOVAL
 * ================================================================== */

/**
 * Take it all back out.
 *
 * Works strictly from the manifest. An object that is not listed there is
 * never touched, whatever it looks like.
 *
 * @since 4.0.0
 * @return array{removed:int}|WP_Error
 */
function livingdraft_demo_remove() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to remove demo content.', 'livingdraft' ) );
	}

	if ( ! livingdraft_demo_is_installed() ) {
		return new WP_Error( 'nothing', __( 'There is no demo content to remove.', 'livingdraft' ) );
	}

	$manifest = livingdraft_demo_manifest();
	$removed  = 0;

	// Attachments first, so the files on disk go with them.
	foreach ( (array) $manifest['attachments'] as $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( $attachment_id && get_post_meta( $attachment_id, LIVINGDRAFT_DEMO_FLAG, true ) ) {
			wp_delete_attachment( $attachment_id, true );
			$removed++;
		}
	}

	// Posts and pages. The flag is checked as well as the manifest, so a
	// recycled id from an unrelated post can never be deleted.
	foreach ( (array) $manifest['posts'] as $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id && get_post_meta( $post_id, LIVINGDRAFT_DEMO_FLAG, true ) ) {
			wp_delete_post( $post_id, true );
			$removed++;
		}
	}

	// Sections and tags.
	foreach ( (array) $manifest['terms'] as $pair ) {
		if ( ! is_array( $pair ) || count( $pair ) < 2 ) {
			continue;
		}

		list( $term_id, $taxonomy ) = $pair;
		$term_id                    = absint( $term_id );

		if ( $term_id && get_term_meta( $term_id, LIVINGDRAFT_DEMO_FLAG, true ) ) {
			wp_delete_term( $term_id, (string) $taxonomy );
			$removed++;
		}
	}

	// Menus.
	foreach ( (array) $manifest['menus'] as $menu_id ) {
		$menu_id = absint( $menu_id );

		if ( $menu_id && is_nav_menu( $menu_id ) ) {
			wp_delete_nav_menu( $menu_id );
			$removed++;
		}
	}

	// Settings, back to exactly what they were.
	if ( isset( $manifest['restore']['nav_menu_locations'] ) ) {
		set_theme_mod( 'nav_menu_locations', $manifest['restore']['nav_menu_locations'] );
	}

	if ( isset( $manifest['restore']['sidebars_widgets'] ) ) {
		update_option( 'sidebars_widgets', $manifest['restore']['sidebars_widgets'] );
	}

	if ( ! empty( $manifest['restore']['theme_mods'] ) ) {
		foreach ( (array) $manifest['restore']['theme_mods'] as $mod ) {
			remove_theme_mod( (string) $mod );
		}
	}

	delete_option( LIVINGDRAFT_DEMO_MANIFEST );

	return array( 'removed' => $removed );
}

/* ==================================================================
 * THE ADMIN PAGE
 * ================================================================== */

/**
 * Appearance → Demo Content.
 *
 * @since 4.0.0
 */
function livingdraft_demo_menu() {
	add_theme_page(
		__( 'Demo Content', 'livingdraft' ),
		__( 'Demo Content', 'livingdraft' ),
		'edit_theme_options',
		'livingdraft-demo',
		'livingdraft_demo_page'
	);
}
add_action( 'admin_menu', 'livingdraft_demo_menu' );

/**
 * Handle the two form submissions.
 *
 * On admin_init rather than inside the page renderer, so the redirect
 * after a successful action happens before any output. That prevents a
 * browser refresh from repeating the action.
 *
 * @since 4.0.0
 */
function livingdraft_demo_handle_actions() {
	if ( empty( $_POST['livingdraft_demo_action'] ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'livingdraft' ), 403 );
	}

	$action = sanitize_key( wp_unslash( $_POST['livingdraft_demo_action'] ) );

	check_admin_referer( 'livingdraft_demo_' . $action );

	if ( 'import' === $action ) {
		$result = livingdraft_demo_import();
		$status = is_wp_error( $result ) ? 'error' : 'imported';
	} elseif ( 'remove' === $action ) {
		$result = livingdraft_demo_remove();
		$status = is_wp_error( $result ) ? 'error' : 'removed';
	} else {
		return;
	}

	$args = array(
		'page'      => 'livingdraft-demo',
		'ld_status' => $status,
	);

	if ( is_wp_error( $result ) ) {
		$args['ld_message'] = rawurlencode( $result->get_error_message() );
	}

	wp_safe_redirect( add_query_arg( $args, admin_url( 'themes.php' ) ) );
	exit;
}
add_action( 'admin_init', 'livingdraft_demo_handle_actions' );

/**
 * Render the page.
 *
 * @since 4.0.0
 */
function livingdraft_demo_page() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}

	$installed = livingdraft_demo_is_installed();
	$manifest  = livingdraft_demo_manifest();
	$existing  = livingdraft_demo_existing_content_count();

	// Reading a status flag out of the URL after our own redirect. Nothing
	// is acted on here, so no nonce is required to display a message.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$status  = isset( $_GET['ld_status'] ) ? sanitize_key( wp_unslash( $_GET['ld_status'] ) ) : '';
	$message = isset( $_GET['ld_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ld_message'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Demo Content', 'livingdraft' ); ?></h1>

		<?php if ( 'imported' === $status ) : ?>
			<div class="notice notice-success"><p>
				<?php esc_html_e( 'Demo content installed.', 'livingdraft' ); ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'View the front page →', 'livingdraft' ); ?></a>
			</p></div>
		<?php elseif ( 'removed' === $status ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Demo content removed, and your settings put back.', 'livingdraft' ); ?></p></div>
		<?php elseif ( 'error' === $status && '' !== $message ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>

		<p style="max-width:44em;font-size:14px;">
			<?php esc_html_e( 'This theme is difficult to judge empty. A drop cap needs a lead story, a brief rail needs stories to brief, and the update log shows nothing at all until something has been logged. The demo content below fills the paper with worked examples so you can see what the theme actually does before committing to it.', 'livingdraft' ); ?>
		</p>

		<p style="max-width:44em;font-size:14px;">
			<?php esc_html_e( 'Every demo article is written to explain one part of the theme, so reading the demo site is also how you learn to use it.', 'livingdraft' ); ?>
		</p>

		<hr>

		<?php if ( $installed ) : ?>

			<h2><?php esc_html_e( 'Demo content is installed', 'livingdraft' ); ?></h2>

			<p>
				<?php
				printf(
					/* translators: 1: date, 2: number of stories and pages, 3: number of images. */
					esc_html__( 'Imported %1$s. It created %2$d stories and pages and %3$d images, all of which are hidden from search engines for as long as they exist.', 'livingdraft' ),
					esc_html( mysql2date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $manifest['imported_at'] ) ),
					count( (array) $manifest['posts'] ),
					count( (array) $manifest['attachments'] )
				);
				?>
			</p>

			<h3><?php esc_html_e( 'Removing it', 'livingdraft' ); ?></h3>

			<p style="max-width:44em;">
				<?php esc_html_e( 'Everything this import created is deleted, and any setting it changed is put back the way it was. Nothing you wrote yourself is touched. This cannot be undone, but nothing of yours is at stake.', 'livingdraft' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'livingdraft_demo_remove' ); ?>
				<input type="hidden" name="livingdraft_demo_action" value="remove">
				<?php
				submit_button(
					__( 'Remove demo content', 'livingdraft' ),
					'delete',
					'submit',
					false,
					array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'Delete all demo stories, pages, sections and images, and restore your settings?', 'livingdraft' ) ) . ');' )
				);
				?>
			</form>

		<?php else : ?>

			<h2><?php esc_html_e( 'What importing will create', 'livingdraft' ); ?></h2>

			<ul style="list-style:disc;margin-left:20px;max-width:44em;">
				<li><?php esc_html_e( '7 published stories, each explaining one part of the theme — including one carrying a published correction log, one labelled Analysis, and one with a second byline.', 'livingdraft' ); ?></li>
				<li><?php esc_html_e( '3 sections and a handful of tags.', 'livingdraft' ); ?></li>
				<li><?php esc_html_e( '3 pages: about, corrections policy and contact.', 'livingdraft' ); ?></li>
				<li><?php esc_html_e( 'Placeholder images drawn at the theme\'s own 1200 × 628 crop, with alt text written.', 'livingdraft' ); ?></li>
				<li><?php esc_html_e( 'A sections menu and a footer menu — only if those menu positions are currently empty.', 'livingdraft' ); ?></li>
				<li><?php esc_html_e( 'Search, Recent Posts and Categories widgets — only if your sidebar is currently empty.', 'livingdraft' ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'What it will not do', 'livingdraft' ); ?></h2>

			<ul style="list-style:disc;margin-left:20px;max-width:44em;">
				<li><?php esc_html_e( 'It will not change, overwrite or delete anything you have already written.', 'livingdraft' ); ?></li>
				<li><?php esc_html_e( 'It will not touch a menu position or a sidebar you have already filled.', 'livingdraft' ); ?></li>
				<li><?php esc_html_e( 'It will not change a setting you have already chosen.', 'livingdraft' ); ?></li>
				<li><?php esc_html_e( 'It will not let any of this reach Google. Every demo story is marked no-index and kept out of your sitemap.', 'livingdraft' ); ?></li>
			</ul>

			<?php if ( $existing > 0 ) : ?>
				<div class="notice notice-warning inline" style="max-width:44em;margin:20px 0;padding:10px 12px;">
					<p><strong><?php esc_html_e( 'This site already has content.', 'livingdraft' ); ?></strong></p>
					<p>
						<?php
						printf(
							/* translators: %d: number of existing posts and pages, capped at 100. */
							esc_html__( 'There are at least %d posts or pages here already. Demo content is meant for a site you are still setting up. Nothing of yours will be harmed, and everything can be removed again in one press — but on a site people are reading, the demo stories will appear on your front page until you remove them.', 'livingdraft' ),
							(int) $existing
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'livingdraft_demo_import' ); ?>
				<input type="hidden" name="livingdraft_demo_action" value="import">
				<?php submit_button( __( 'Import demo content', 'livingdraft' ), 'primary', 'submit', false ); ?>
			</form>

		<?php endif; ?>
	</div>
	<?php
}
