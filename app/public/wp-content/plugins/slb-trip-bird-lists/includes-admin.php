<?php
namespace SLB\TripBirdLists;

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
    $parent = available() ? 'edit.php?post_type=tribe_events' : 'tools.php';
    add_submenu_page( $parent, 'Bird lists', 'Bird lists', 'manage_options', 'slb-bird-lists', __NAMESPACE__ . '\\admin_screen' );
    add_submenu_page( $parent, 'Bird list settings', 'Bird list settings', 'manage_options', 'slb-bird-settings', __NAMESPACE__ . '\\settings_screen' );
} );

add_action( 'add_meta_boxes_tribe_events', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    add_meta_box( 'slb-bird-list', 'Trip bird list', function ( $post ) {
        if ( $post->post_status === 'auto-draft' ) {
            echo '<p>Save this event before creating its bird checklist.</p>';
            return;
        }
        $record = row( $post->ID );
        echo '<p>Record sightings and publish a complete HTML bird list.</p><a class="button" href="' . esc_url( editor_url( $post->ID ) ) . '">' .
            ( $record ? 'Edit bird list' : 'Create bird list' ) . '</a>';
        if ( $record && get_post_status( $record->report_id ) === 'publish' ) {
            echo '<p><a href="' . esc_url( get_permalink( $record->report_id ) ) . '">View trip report</a></p>';
        }
    }, 'tribe_events', 'side' );
} );

add_filter( 'post_row_actions', function ( $actions, $post ) {
    if ( $post->post_type === 'tribe_events' && event_allowed( $post->ID ) ) {
        $actions['slb_birds'] = '<a href="' . esc_url( editor_url( $post->ID ) ) . '">Bird list</a>';
    }
    return $actions;
}, 10, 2 );

add_action( 'admin_notices', function () {
    if ( current_user_can( 'manage_options' ) && ! available() ) {
        echo '<div class="notice notice-warning"><p>SLB Trip Bird Lists requires The Events Calendar to create and edit trip checklists. Existing published reports remain available.</p></div>';
    }
} );

function settings_url() {
    $parent = available() ? 'edit.php?post_type=tribe_events' : 'tools.php';
    return add_query_arg( 'page', 'slb-bird-settings', admin_url( $parent ) );
}

function settings_screen() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You cannot manage bird lists.' );
    }
    $notice = '';
    $invalid = false;
    $master = get_option( MASTER_OPTION, array() );
    $input = implode( "\n", array_column( $master, 'name' ) );
    $category = (int) get_option( CATEGORY_OPTION );
    if ( isset( $_POST['slb_save_master'] ) ) {
        check_admin_referer( 'slb_master' );
        $raw = isset( $_POST['birds'] ) && is_string( $_POST['birds'] ) ? wp_unslash( $_POST['birds'] ) : '';
        $input = $raw;
        $names = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\R/u', $raw ) ), 'strlen' ) );
        $category = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
        $folded = array_map( 'strtolower', $names );
        if ( ! $names || count( $names ) > 1500 || count( $folded ) !== count( array_unique( $folded ) ) || max( array_map( 'strlen', $names ) ) > 180 ) {
            $notice = 'Enter 1–1,500 unique bird names, one per line (up to 180 characters each).';
            $invalid = true;
        } elseif ( ! $category || ! term_exists( $category, 'category' ) ) {
            $notice = 'Choose the category for published trip reports.';
            $invalid = true;
        } else {
            $existing = array_column( $master, 'id', 'name' );
            $master = array_map( function ( $name ) use ( $existing ) {
                return array( 'id' => $existing[ $name ] ?? wp_generate_uuid4(), 'name' => $name );
            }, $names );
            update_option( MASTER_OPTION, $master, false );
            update_option( CATEGORY_OPTION, $category, false );
            $notice = 'Settings saved. Existing trip checklists keep their original bird names and order.';
        }
    }
    echo '<div class="wrap"><h1>Bird list settings</h1>';
    if ( $notice ) {
        echo '<div class="notice ' . ( $invalid ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice ) . '</p></div>';
    }
    echo '<p>Paste the master checklist, one bird per line, in the order it should appear. New trips take their own copy; later changes here do not change existing checklists.</p><form method="post">';
    wp_nonce_field( 'slb_master' );
    echo '<p><label for="slb-category"><strong>Report category</strong></label></p>';
    wp_dropdown_categories( array( 'hide_empty' => false, 'name' => 'category', 'id' => 'slb-category', 'selected' => $category, 'show_option_none' => 'Choose a category', 'option_none_value' => 0 ) );
    echo '<p><label for="slb-master"><strong>Master bird list</strong></label></p><textarea id="slb-master" name="birds" rows="22" class="large-text" style="max-width:650px">' . esc_textarea( $input ) . '</textarea>';
    submit_button( 'Save bird list settings', 'primary', 'slb_save_master' );
    echo '</form><p><a href="' . esc_url( editor_url() ) . '">Back to bird lists</a></p></div>';
}

function admin_screen() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You cannot manage bird lists.' );
    }
    echo '<div class="wrap slb-app"><h1>Trip bird lists</h1>';
    if ( ! available() ) {
        echo '<p>Activate The Events Calendar to manage trip checklists.</p></div>';
        return;
    }
    $id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
    if ( $id ) {
        if ( ! event_allowed( $id ) ) {
            wp_die( 'This event is unavailable or you do not have access to edit it.' );
        }
        if ( ! row( $id ) && ! get_option( MASTER_OPTION ) ) {
            echo '<p>The master bird list has not been set up yet.</p><p><a class="button button-primary" href="' . esc_url( settings_url() ) . '">Set up the master bird list</a></p></div>';
            return;
        }
        wp_enqueue_style( 'slb-bird-editor', plugins_url( 'assets/editor.css', __FILE__ ), array(), VERSION );
        wp_enqueue_script( 'slb-bird-editor', plugins_url( 'assets/editor.js', __FILE__ ), array(), VERSION, true );
        wp_add_inline_script( 'slb-bird-editor', 'window.slbBirdLists=' . wp_json_encode( array(
            'endpoint' => rest_url( 'slb-birds/v1/trips/' . $id ), 'nonce' => wp_create_nonce( 'wp_rest' ),
            'eventId' => $id, 'userId' => get_current_user_id(), 'initial' => state( $id ),
            'listUrl' => editor_url(), 'loginUrl' => wp_login_url( editor_url( $id ) ),
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
        echo '<div id="slb-editor"><p>Loading checklist…</p></div><noscript><p>JavaScript is required to record and save sightings. Published reports work without JavaScript.</p></noscript></div>';
        return;
    }
    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
    $events = new \WP_Query( array( 'post_type' => 'tribe_events', 'post_status' => array( 'publish', 'draft', 'future', 'private', 'pending' ),
        'posts_per_page' => 30, 'paged' => $page, 's' => $search, 'orderby' => 'meta_value', 'meta_key' => '_EventStartDate', 'order' => 'DESC' ) );
    echo '<p>Open a trip to record sightings. Saving a checklist does not publish a report.</p><p><a href="' . esc_url( settings_url() ) . '">Bird list settings</a></p>';
    echo '<form method="get"><input type="hidden" name="post_type" value="tribe_events"><input type="hidden" name="page" value="slb-bird-lists"><label class="screen-reader-text" for="slb-trip-search">Find a trip</label><input id="slb-trip-search" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Find a trip"> <button class="button">Search trips</button></form>';
    echo '<ul class="slb-trip-index">';
    foreach ( $events->posts as $event ) {
        if ( ! event_allowed( $event->ID ) ) {
            continue;
        }
        $record = row( $event->ID );
        $status = ! $record ? 'Not started' : ( $record->published ? ( $record->draft === $record->published ? 'Published' : 'Unpublished changes' ) : 'Draft checklist' );
        echo '<li style="padding:14px 0;border-bottom:1px solid #ccc"><a style="display:inline-block;min-height:44px" href="' . esc_url( editor_url( $event->ID ) ) . '"><strong>' . esc_html( $event->post_title ) . '</strong><br>' . esc_html( event_date( $event->ID ) ) . '</a> — ' . esc_html( $status ) . '</li>';
    }
    echo '</ul>';
    if ( ! $events->post_count ) {
        echo '<p>No matching events found.</p>';
    }
    $pagination_base = str_replace( '999999999', '%#%', add_query_arg( array( 'paged' => 999999999, 's' => $search ), editor_url() ) );
    echo wp_kses_post( paginate_links( array( 'base' => $pagination_base, 'total' => $events->max_num_pages, 'current' => $page ) ) );
    echo '</div>';
}
