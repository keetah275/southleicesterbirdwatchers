<?php
/**
 * Plugin Name: SLB Trip Bird Lists
 * Description: Record a complete bird checklist against an event and publish a linked trip report.
 * Version: 0.1.9
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: South Leicester Birdwatchers
 * License: GPL-2.0-or-later
 */

namespace SLB\TripBirdLists;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.9';
const MASTER_OPTION = 'slb_bird_list_master';
const CATEGORY_OPTION = 'slb_bird_list_category';

function table() {
    global $wpdb;
    return $wpdb->prefix . 'slb_bird_lists';
}

function bundled_master() {
    $names = file( __DIR__ . '/data/master-birds.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    return array_map( function ( $name ) {
        return array( 'id' => 'slb-' . substr( hash( 'sha256', $name ), 0, 24 ), 'name' => $name );
    }, $names );
}

function install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = table();
    $collation = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE $table (
        event_id bigint(20) unsigned NOT NULL,
        revision bigint(20) unsigned NOT NULL DEFAULT 1,
        draft longtext NOT NULL,
        published longtext NULL,
        report_id bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (event_id)
    ) $collation;" );
    $category = get_term_by( 'slug', 'trip-list', 'category' );
    if ( $category ) {
        add_option( CATEGORY_OPTION, $category->term_id );
    }
    // Seed only an unconfigured installation; never replace an administrator's list.
    add_option( MASTER_OPTION, bundled_master(), '', false );
    update_option( 'slb_bird_lists_version', VERSION, false );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\install' );
add_action( 'plugins_loaded', function () {
    if ( get_option( 'slb_bird_lists_version' ) !== VERSION ) {
        install();
    }
} );

function editor_url( $id = 0 ) {
    return add_query_arg( array( 'page' => 'slb-bird-lists', 'event_id' => $id ), admin_url( 'edit.php?post_type=tribe_events' ) );
}

function available() {
    return post_type_exists( 'tribe_events' );
}

function event_allowed( $id ) {
    return available() && current_user_can( 'manage_options' ) &&
        get_post_type( $id ) === 'tribe_events' && current_user_can( 'edit_post', $id ) &&
        ! in_array( get_post_status( $id ), array( 'trash', 'auto-draft' ), true );
}

function event_date( $id ) {
    $date = get_post_meta( $id, '_EventStartDate', true );
    return $date ? mysql2date( get_option( 'date_format' ), $date ) : '';
}

function row( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . table() . ' WHERE event_id = %d', $id ) );
}

function plain_title( $title ) {
    return wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

function initial_draft( $id ) {
    return array(
        'title' => plain_title( get_post_field( 'post_title', $id ) ) . ' — ' . event_date( $id ) . ' — Trip list',
        'summary' => '',
        'species' => get_option( MASTER_OPTION, array() ),
        'seen' => array(),
        'heard' => array(),
    );
}

function state( $id ) {
    $record = row( $id );
    $draft = $record ? json_decode( $record->draft, true ) : initial_draft( $id );
    $draft['heard'] = $draft['heard'] ?? array();
    $draft['title'] = plain_title( $draft['title'] );
    $report = $record && $record->report_id ? get_post( $record->report_id ) : null;
    return array(
        'revision' => $record ? (int) $record->revision : 0,
        'draft' => $draft,
        'eventTitle' => plain_title( get_post_field( 'post_title', $id ) ),
        'eventDate' => event_date( $id ),
        'eventUrl' => get_permalink( $id ),
        'updatedAt' => $record ? str_replace( ' ', 'T', $record->updated_at ) . 'Z' : null,
        'published' => $record && $record->published ? true : false,
        'unpublishedChanges' => ! $record || $record->draft !== $record->published,
        'reportId' => $record ? (int) $record->report_id : 0,
        'reportStatus' => $report ? $report->post_status : ( $record && $record->report_id ? 'missing' : null ),
        'reportUrl' => $report && $report->post_status === 'publish' ? get_permalink( $report ) : null,
    );
}

function error( $code, $message, $status = 400, $extra = array() ) {
    return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
}

/** Serialize changes across tabs/devices, including publication retries. */
function locked( $id, $callback ) {
    global $wpdb;
    $key = 'slb_' . md5( DB_NAME . table() . ':' . $id );
    if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) !== 1 ) {
        return error( 'busy', 'Another save is in progress. Please try again.', 503 );
    }
    try {
        return $callback();
    } finally {
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
    }
}

function check_revision( $request, $record ) {
    $revision = $request->get_param( 'revision' );
    if ( ! is_int( $revision ) || $revision < 0 ) {
        return error( 'invalid_revision', 'A valid checklist revision is required.' );
    }
    if ( $revision !== ( $record ? (int) $record->revision : 0 ) ) {
        return error( 'conflict', 'This checklist changed on another screen. Review the latest version before saving.', 409,
            array( 'current' => state( (int) $request['id'] ) ) );
    }
    return true;
}

function save( $request ) {
    global $wpdb;
    $id = (int) $request['id'];
    return locked( $id, function () use ( $request, $id, $wpdb ) {
        $record = row( $id );
        $check = check_revision( $request, $record );
        if ( is_wp_error( $check ) ) {
            return $check;
        }
        $draft = $record ? json_decode( $record->draft, true ) : initial_draft( $id );
        if ( empty( $draft['species'] ) ) {
            return error( 'no_species', 'Set up the master bird list before creating a trip checklist.' );
        }
        $seen = $request->get_param( 'seen' );
        $heard = $request->has_param( 'heard' ) ? $request->get_param( 'heard' ) : ( $draft['heard'] ?? array() );
        $title = $request->get_param( 'title' );
        $summary = $request->get_param( 'summary' );
        if ( ! is_array( $seen ) || ! is_array( $heard ) || ! is_string( $title ) || ! is_string( $summary ) ||
            strlen( $title ) > 500 || strlen( $summary ) > 20000 || count( $seen ) > count( $draft['species'] ) || count( $heard ) > count( $draft['species'] ) ) {
            return error( 'invalid_input', 'Check the title, summary, and selected birds.' );
        }
        $allowed = array_column( $draft['species'], 'id' );
        foreach ( array_merge( $seen, $heard ) as $species_id ) {
            if ( ! is_string( $species_id ) || ! in_array( $species_id, $allowed, true ) ) {
                return error( 'invalid_species', 'The checklist contains an unknown bird.' );
            }
        }
        $draft['title'] = sanitize_text_field( plain_title( $title ) );
        $draft['summary'] = sanitize_textarea_field( $summary );
        $draft['seen'] = array_values( array_intersect( $allowed, $seen ) );
        $draft['heard'] = array_values( array_intersect( $allowed, $heard ) );
        if ( $draft['title'] === '' ) {
            return error( 'empty_title', 'Enter a report title.' );
        }
        $json = wp_json_encode( $draft );
        if ( $record && $record->draft === $json ) {
            return state( $id );
        }
        $data = array( 'draft' => $json, 'revision' => $record ? (int) $record->revision + 1 : 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
        $result = $record ? $wpdb->update( table(), $data, array( 'event_id' => $id ) ) :
            $wpdb->insert( table(), array_merge( $data, array( 'event_id' => $id ) ) );
        if ( false === $result ) {
            return error( 'save_failed', 'The checklist could not be saved. Your changes are still on this screen.', 500 );
        }
        return state( $id );
    } );
}

function publish( $request ) {
    global $wpdb;
    $id = (int) $request['id'];
    return locked( $id, function () use ( $request, $id, $wpdb ) {
        $record = row( $id );
        $check = check_revision( $request, $record );
        if ( is_wp_error( $check ) ) {
            return $check;
        }
        if ( ! $record ) {
            return error( 'save_first', 'Save the checklist before publishing.' );
        }
        if ( ! current_user_can( 'publish_posts' ) ) {
            return error( 'forbidden', 'You cannot publish reports.', 403 );
        }
        $category = (int) get_option( CATEGORY_OPTION );
        if ( ! $category || ! term_exists( $category, 'category' ) ) {
            return error( 'no_category', 'Choose a report category in Bird list settings before publishing.' );
        }
        $draft = json_decode( $record->draft, true );
        $report_id = (int) $record->report_id;
        // Recover an existing post if a previous request ended before its ID was stored.
        if ( ! $report_id ) {
            $report_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_slb_trip_event' AND meta_value = %s ORDER BY meta_id ASC LIMIT 1", (string) $id
            ) );
        }
        $report = $report_id ? get_post( $report_id ) : null;
        if ( $report && ( $report->post_type !== 'post' || (int) get_post_meta( $report_id, '_slb_trip_event', true ) !== $id ) ) {
            return error( 'invalid_report', 'The associated report needs administrator review.', 409 );
        }
        if ( $report && ! current_user_can( 'edit_post', $report_id ) ) {
            return error( 'forbidden', 'You cannot edit this report.', 403 );
        }
        if ( $report && $report->post_status === 'trash' ) {
            if ( $request->get_param( 'recover' ) !== true ) {
                return error( 'report_trashed', 'The report is in the bin. Restore it explicitly before updating.', 409 );
            }
            if ( ! wp_untrash_post( $report_id ) ) {
                return error( 'restore_failed', 'The report could not be restored.', 500 );
            }
        }
        if ( $report_id && ! $report && $request->get_param( 'recover' ) !== true ) {
            return error( 'report_missing', 'The report was deleted. Confirm creation of a replacement report.', 409 );
        }
        $post_data = array( 'post_title' => plain_title( $draft['title'] ), 'post_status' => 'publish', 'post_type' => 'post' );
        if ( $report ) {
            $post_data['ID'] = $report_id;
            $post_data['post_category'] = array_values( array_unique( array_merge( wp_get_post_categories( $report_id ), array( $category ) ) ) );
            if ( ! has_shortcode( $report->post_content, 'slb_trip_report' ) ) {
                $post_data['post_content'] = $report->post_content . "\n\n" . '[slb_trip_report event_id="' . $id . '"]';
            }
        } else {
            $post_data['post_category'] = array( $category );
            $post_data['post_content'] = '[slb_trip_report event_id="' . $id . '"]';
            $post_data['meta_input'] = array( '_slb_trip_event' => $id );
        }
        // Stage the published snapshot before post creation so content hooks can render it.
        // The lock keeps saves/publications serial; roll back the snapshot if publishing fails.
        $updated = $wpdb->update( table(), array( 'published' => $record->draft ), array( 'event_id' => $id ) );
        if ( $updated === false ) {
            return error( 'publish_failed', 'The report snapshot could not be saved.', 500 );
        }
        $result = $report ? wp_update_post( wp_slash( $post_data ), true ) : wp_insert_post( wp_slash( $post_data ), true );
        if ( is_wp_error( $result ) ) {
            $wpdb->update( table(), array( 'published' => $record->published ), array( 'event_id' => $id ) );
            return $result;
        }
        if ( false === $wpdb->update( table(), array( 'report_id' => $result ), array( 'event_id' => $id ) ) ) {
            return error( 'publish_link_failed', 'The post was created, but its link could not be saved. Retry to recover the existing post.', 500 );
        }
        clean_post_cache( $result );
        return state( $id );
    } );
}

add_action( 'rest_api_init', function () {
    $permission = function ( $request ) {
        if ( ! event_allowed( (int) $request['id'] ) ) {
            return error( 'forbidden', 'You cannot manage this trip checklist.', 403 );
        }
        return true;
    };
    register_rest_route( 'slb-birds/v1', '/trips/(?P<id>\d+)', array(
        array( 'methods' => 'GET', 'callback' => function ( $r ) { return state( (int) $r['id'] ); }, 'permission_callback' => $permission ),
        array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\save', 'permission_callback' => $permission ),
    ) );
    register_rest_route( 'slb-birds/v1', '/trips/(?P<id>\d+)/publish', array(
        'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\publish', 'permission_callback' => $permission,
    ) );
} );

function report_html( $id, $draft ) {
    $seen = array_fill_keys( $draft['seen'], true );
    $heard = array_fill_keys( $draft['heard'] ?? array(), true );
    $html = '<section class="slb-report" aria-label="Bird checklist"><header class="slb-report-header">' .
        '<p class="slb-report-eyebrow">South Leicester Birdwatchers · Trip bird list</p>' .
        '<h2>' . esc_html( plain_title( $draft['title'] ) ) . '</h2><p class="slb-report-date">' . esc_html( event_date( $id ) ) .
        ' <span aria-hidden="true">/</span> <a href="' . esc_url( get_permalink( $id ) ) . '">View trip event</a></p></header><div class="slb-report-body">';
    if ( $draft['summary'] !== '' ) {
        $html .= '<div class="slb-summary">' . wpautop( esc_html( $draft['summary'] ) ) . '</div>';
    }
    $html .= '<div class="slb-report-totals" aria-label="Trip totals"><p><strong>' . count( $seen ) . '</strong><span>species seen</span></p>' .
        '<p><strong>' . count( $heard ) . '</strong><span>species heard</span></p>' .
        '<p aria-label="' . count( $seen + $heard ) . ' recorded out of ' . count( $draft['species'] ) . '"><strong>' . count( $seen + $heard ) . '</strong><span>recorded / ' . count( $draft['species'] ) . ' listed</span></p></div>' .
        '<p class="slb-report-key">Seen and heard are recorded separately; a bird may be both. <span>— = not recorded in that column.</span></p>' .
        '<table><caption>Complete bird checklist</caption><thead><tr><th scope="col">Bird</th><th scope="col">Seen</th><th scope="col">Heard</th></tr></thead><tbody>';
    foreach ( $draft['species'] as $bird ) {
        $html .= '<tr' . ( isset( $seen[ $bird['id'] ] ) || isset( $heard[ $bird['id'] ] ) ? ' class="slb-recorded"' : '' ) . '><th scope="row">' . esc_html( $bird['name'] ) . '</th><td>' .
            ( isset( $seen[ $bird['id'] ] ) ? '<strong>✓ Seen</strong>' : '<span aria-label="Not recorded as seen">—</span>' ) . '</td><td>' .
            ( isset( $heard[ $bird['id'] ] ) ? '<strong>✓ Heard</strong>' : '<span aria-label="Not recorded as heard">—</span>' ) . '</td></tr>';
    }
    return $html . '</tbody></table><p class="slb-report-footer">Complete trip checklist · ' . count( $draft['species'] ) . ' species</p></div></section>';
}

add_shortcode( 'slb_trip_report', function ( $attrs ) {
    $attrs = shortcode_atts( array( 'event_id' => 0 ), $attrs, 'slb_trip_report' );
    $record = row( absint( $attrs['event_id'] ) );
    if ( ! $record || ! $record->published ) {
        return '';
    }
    return report_html( (int) $record->event_id, json_decode( $record->published, true ) );
} );

add_action( 'wp_enqueue_scripts', function () {
    if ( is_page( 'trip-lists' ) ) {
        wp_enqueue_style( 'slb-trip-list-cards', plugins_url( 'assets/listing.css', __FILE__ ), array(), VERSION );
    }
    if ( is_singular( 'post' ) && has_shortcode( get_post()->post_content, 'slb_trip_report' ) ) {
        wp_enqueue_style( 'slb-bird-report', plugins_url( 'assets/report.css', __FILE__ ), array(), VERSION );
    }
} );

add_filter( 'body_class', function ( $classes ) {
    if ( is_page( 'trip-lists' ) ) {
        $classes[] = 'slb-trip-lists-page';
    }
    return $classes;
} );

add_filter( 'the_content', function ( $content ) {
    if ( ! is_admin() && is_singular( 'tribe_events' ) && in_the_loop() && is_main_query() ) {
        $record = row( get_the_ID() );
        if ( $record && $record->published && get_post_status( $record->report_id ) === 'publish' ) {
            $content .= '<p><a href="' . esc_url( get_permalink( $record->report_id ) ) . '">View the complete trip bird list</a></p>';
        }
    }
    return $content;
} );

/** Use published checklist data for report cards, without exposing working edits. */
function listing_block( $html, $block ) {
    $post_id = (int) ( $block->context['postId'] ?? 0 );
    $event_id = (int) get_post_meta( $post_id, '_slb_trip_event', true );
    $record = $event_id ? row( $event_id ) : null;
    if ( ! $record || (int) $record->report_id !== $post_id || ! $record->published ) {
        return $html;
    }
    $snapshot = json_decode( $record->published, true );
    if ( ! is_array( $snapshot ) ) {
        return $html;
    }
    if ( $block->name === 'core/post-date' ) {
        $date = get_post_meta( $event_id, '_EventStartDate', true );
        if ( ! $date ) {
            return $html;
        }
        $time = 'Trip date: <time datetime="' . esc_attr( mysql2date( 'Y-m-d', $date, false ) ) . '">' . esc_html( event_date( $event_id ) ) . '</time>';
        return preg_replace_callback( '/<time\b[^>]*>.*?<\/time>/s', function () use ( $time ) { return $time; }, $html, 1 );
    }
    if ( $block->name === 'core/post-excerpt' ) {
        $seen = array_unique( $snapshot['seen'] ?? array() );
        $heard = array_unique( $snapshot['heard'] ?? array() );
        $total = count( array_unique( array_merge( $seen, $heard ) ) );
        $html = '<div class="wp-block-post-excerpt slb-trip-card-summary"><p class="slb-trip-card-totals"><strong>' .
            count( $seen ) . ' seen · ' . count( $heard ) . ' heard · ' . $total . ' recorded</strong></p>';
        if ( ! empty( $snapshot['summary'] ) ) {
            $html .= '<p>' . esc_html( wp_trim_words( $snapshot['summary'], 45 ) ) . '</p>';
        }
        return $html . '<p class="slb-trip-card-link"><a href="' . esc_url( get_permalink( $post_id ) ) . '">View full trip list <span aria-hidden="true">↗</span></a></p></div>';
    }
    return $html;
}

foreach ( array( 'core/post-date', 'core/post-excerpt' ) as $listing_block_name ) {
    add_filter( 'render_block_' . $listing_block_name, function ( $html, $parsed, $block ) {
        return ! is_admin() && is_page( 'trip-lists' ) ? listing_block( $html, $block ) : $html;
    }, 10, 3 );
}

require_once __DIR__ . '/includes-admin.php';
