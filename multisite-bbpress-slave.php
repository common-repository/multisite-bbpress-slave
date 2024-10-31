<?php
/*
Plugin Name: Multisite bbPress Slave
Description: Make sub sites use the main site's bbPress forums
Plugin URI: http://wordpress.org/extend/plugins/multisite-bbpress-slave
Author: Markus Echterhoff
Author URI: http://www.markusechterhoff.com
Version: 1.0
License: GPLv3 or later
*/

register_activation_hook( __FILE__, 'mbbps_activate' );
function mbbps_activate() {
	if ( is_main_site() ) {
		mbbps_flush_them_all();
	} else {
		flush_rewrite_rules();
	}
}

register_deactivation_hook( __FILE__, 'mbbps_deactivate' );
function mbbps_deactivate() {
	if ( is_main_site() ) {
		mbbps_flush_them_all();
	} else {
		flush_rewrite_rules();
	}
}

// when updating master forums's settings, we have to flush all sub site rewrite rules
// so that the slave forums can be reached with the new slugs
add_filter( 'bbp_admin_get_settings_sections', 'mbbps_inject_settings_callback' );
function mbbps_inject_settings_callback( $sections ) {
	$sections['bbp_settings_root_slugs']['callback'] = 'mbbps_the_settings_callback';
	return $sections;
}
function mbbps_the_settings_callback() {
	if ( isset( $_GET['settings-updated'] ) && isset( $_GET['page'] ) ) {
		mbbps_flush_them_all();
	}
	
	echo '<p>' . esc_html_e( 'Customize your Forums root. Partner with a WordPress Page and use Shortcodes for more flexibility.', 'bbpress' ) . '</p>';
}

function mbbps_flush_them_all() {
	global $wpdb, $wp_rewrite;
	$sites = $wpdb->get_results( "select * from $wpdb->blogs" );
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		$wp_rewrite->init();
		// at somepoint this seemed necessary... then it didn't.. but then it did.. and then it did not.. but then it DID!
		bbPress::register_post_types();
		bbPress::register_post_statuses();
		bbPress::register_taxonomies();
		bbPress::register_views();
		bbPress::add_rewrite_tags();
		bbPress::add_rewrite_rules();
		bbPress::add_permastructs();
		$wp_rewrite->flush_rules();
		restore_current_blog();
	}
}

// on sub site rewrite rule loading, copy sub site forum rules and replace root slugs
add_filter( 'rewrite_rules_array', 'mbbps_insert_rewrite_rules', 11 );
function mbbps_insert_rewrite_rules( $rules ) {

	if ( is_main_site() ) {
		return $rules;
	}

	$oldforums = bbp_get_root_slug();
	$oldtopics = bbp_get_topic_archive_slug();
	$newforums = get_blog_option( 1, '_bbp_root_slug', 'forums' );
	$newtopics = get_blog_option( 1, '_bbp_topic_archive_slug', 'topics' );

	$newrules = array();
	foreach ( array( $oldforums => $newforums, $oldtopics => $newtopics ) as $oldslug => $newslug ) {
		if ( $oldslug == $newslug ) {
			continue;
		}
		$oldslug = trailingslashit( $oldslug );
		$newslug = trailingslashit( $newslug );
		$oldlen = strlen( $oldslug );
				
		foreach ( $rules as $pattern => $query_string ) {
			if ( 0 === strncmp( $pattern, $oldslug, $oldlen ) ) {
				$newpattern = $newslug . substr( $pattern, $oldlen, strlen( $pattern ) - $oldlen );
				$newrules[$newpattern] = $query_string;
			}
		}
	}

	return array_merge( $newrules, $rules );
}

// only activate this plugin when the master forum's slug is opened on the sub site
add_action( 'plugins_loaded', 'mbbps_activate_on_slave_forum_urls', -1 );
function mbbps_activate_on_slave_forum_urls() {

	if ( is_main_site() ) {
		return;
	}

	$parts = parse_url( untrailingslashit( home_url() ) );
	if ( !isset( $parts['path'] ) ) {
		$component_request_uri = $_SERVER['REQUEST_URI'];
	} else {
		$component_request_uri = substr( $_SERVER['REQUEST_URI'], strlen( $parts['path'] ), strlen( $_SERVER['REQUEST_URI'] ) - strlen( $parts['path'] ) );
	}

	$bbp_root_slug = '/' . get_blog_option( 1, '_bbp_root_slug', 'forums' )  . '/';
	$bbp_topic_archive_slug = '/' . get_blog_option( 1, '_bbp_topic_archive_slug', 'topics' ) . '/';

	if ( 0 === strncmp( $component_request_uri, $bbp_root_slug, strlen( $bbp_root_slug ) ) ||
			0 === strncmp( $component_request_uri, $bbp_topic_archive_slug, strlen( $bbp_topic_archive_slug ) ) ||
			isset( $_GET['bbp-ajax'] ) ) {
		add_filter( 'query', 'mbbps_query' );
		mbbps_slug_it_up();
		mbbps_apply_monkey_patches();
	}
}

// redirect forum relevant queries from the sub site to the master site
function mbbps_query( $query ) {
	global $wpdb;

	$query = preg_replace(
		array(
			'/([ `(]{1})'.$wpdb->prefix.'((?:posts|postmeta)[ `.,\n]{1})/',	// post and postmeta tables
			'/'.$wpdb->prefix.'(options.*\'_bbp_)/',						// bbp options queries
			'/\''.$wpdb->prefix.'_bbp_/',									// updating usermeta, e.g. subscriptions and favorites
		),
		array(
			 '$1'.$wpdb->base_prefix.'$2',
			 $wpdb->base_prefix.'$1',
			 '\''.$wpdb->base_prefix.'_bbp_',
		),
		$query
	);
	
	return $query;
}

//logging is a really good thing for debugging messy plugins like this one
/*
function mbbps_log( $msg ) {
	$time = date( 'H:i:s', $_SERVER['REQUEST_TIME'] ); 
	$callers = debug_backtrace();
	$file = isset( $callers[1]['file'] ) ? basename( $callers[1]['file'] ) : 'unknown';
	$line = isset( $callers[1]['line'] ) ? basename( $callers[1]['line'] ) : 'unknown';
	if ( !is_scalar( $msg ) ) {
		$msg = var_export( $msg, true );
	}
	$log_line = "$time $msg ($file:$line)" . PHP_EOL;
	file_put_contents( '/tmp/wpdebug.log', $log_line, FILE_APPEND );
}
*/

function mbbps_apply_monkey_patches() {
	// so.. on the slave forums the BP profile link
	// points to some wrong page, rather than to 'members'
	// I'm just going to monkey patch this for now
	add_filter( 'bp_get_members_root_slug', function( $lies_all_lies ) {
		return 'members';
	});
}

// on sub site slave forums hook into all those bbp slug filters and return the master forum's slugs
function mbbps_slug_it_up() {

	//commented out for workaround, see below
	//add_filter( 'bbp_get_root_slug', function() {
	//	return get_blog_option( 1, '_bbp_root_slug', 'forums' );
	//});

	add_filter( 'bbp_include_root_slug', function() {
		return (bool) get_blog_option( 1, '_bbp_include_root', 1 );
	});

	add_filter( 'bbp_show_on_root', function() {
		return get_blog_option( 1, '_bbp_show_on_root', 'forums' );
	});

	// buggy bbpress is buggy (bug is in 2.5.5 stable, but fixed in svn trunk)
	// faulty code (applies root slug filter to forum slug get)
	// function bbp_get_forum_slug( $default = 'forum' ) {;
	//	return apply_filters( 'bbp_get_root_slug', bbp_maybe_get_root_slug() . get_option( '_bbp_forum_slug', $default ) );
	// }
	// for fixed bbpress versions it should look like this: ( uncommented to support the bugfix )
	add_filter( 'bbp_get_forum_slug', function() {
		return bbp_maybe_get_root_slug() . get_blog_option( 1, '_bbp_forum_slug', 'forum');
	});
	// for buggy versions we have to work around the issue
	add_filter( 'bbp_get_root_slug', function( $maybe_root ) {
		$newroot = get_blog_option( 1, '_bbp_root_slug', 'forums' );
		$oldroot = get_option( '_bbp_root_slug', 'forums' );
		return preg_replace( '/^'.$oldroot.'(\/.+)?$/', $newroot.'$1', $maybe_root );
	});

	add_filter( 'bbp_get_topic_archive_slug', function() {
		return get_blog_option( 1, '_bbp_topic_archive_slug', 'topics' );
	});

	add_filter( 'bbp_get_reply_archive_slug', function() {
		return get_blog_option( 1, '_bbp_reply_archive_slug', 'replies' );
	});

	add_filter( 'bbp_get_topic_slug', function() {
		return bbp_maybe_get_root_slug() . get_blog_option( 1, '_bbp_topic_slug', 'topic' );
	});

	add_filter( 'bbp_get_topic_tag_tax_slug', function() {
		return bbp_maybe_get_root_slug() . get_blog_option( 1, '_bbp_topic_tag_slug', 'topic-tag' );
	});

	add_filter( 'bbp_get_reply_slug', function() {
		return bbp_maybe_get_root_slug() . get_blog_option( 1, '_bbp_reply_slug', 'reply' );
	});

	add_filter( 'bbp_get_user_slug', function() {
		return bbp_maybe_get_root_slug() . get_blog_option( 1, '_bbp_user_slug', 'user' );
	});

	add_filter( 'bbp_get_user_favorites_slug', function() {
		return get_blog_option( 1, '_bbp_user_favs_slug', 'favorites' );
	});

	add_filter( 'bbp_get_user_subscriptions_slug', function() {
		return get_blog_option( 1, '_bbp_user_subs_slug', 'subscriptions' );
	});

	add_filter( 'bbp_get_view_slug', function() {
		return bbp_maybe_get_root_slug() . get_blog_option( 1, '_bbp_view_slug', 'view' );
	});

	add_filter( 'bbp_get_search_slug', function() {
		return bbp_maybe_get_root_slug() . get_blog_option( 1, '_bbp_search_slug', 'search' );
	});

	add_filter( 'bbp_get_user_subscribed_forum_ids', function( $s, $u ) {
		global $wpdb;
		$subscriptions = get_user_meta( $u, $wpdb-> base_prefix . '_bbp_forum_subscriptions', true );
		$subscriptions = array_filter( wp_parse_id_list( $subscriptions ) );
		return $subscriptions;
	}, 10, 2 );
		
	add_filter( 'bbp_get_user_subscribed_topic_ids', function( $s, $u ) {
		global $wpdb;
		$subscriptions = get_user_meta( $u, $wpdb-> base_prefix . '_bbp_subscriptions', true );
		$subscriptions = array_filter( wp_parse_id_list( $subscriptions ) );
		return $subscriptions;
	}, 10, 2 );
		
	add_filter( 'bbp_get_user_favorites_topic_ids', function( $s, $u ) {
		global $wpdb;
		$favorites = get_user_meta( $u, $wpdb-> base_prefix . '_bbp_favorites', true );
		$favorites = array_filter( wp_parse_id_list( $favorites ) );
		return $favorites;
	}, 10, 2 );
}

?>
