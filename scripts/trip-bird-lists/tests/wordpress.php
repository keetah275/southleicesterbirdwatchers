<?php
/** Run with: wp eval-file /absolute/path/to/scripts/trip-bird-lists/tests/wordpress.php */

use function SLB\TripBirdLists\table;

if ( wp_get_environment_type() !== 'local' ) {
    WP_CLI::error( 'Integration tests may run only with WP_ENVIRONMENT_TYPE=local.' );
}

if ( ! function_exists( 'SLB\\TripBirdLists\\state' ) ) {
    WP_CLI::error( 'Activate slb-trip-bird-lists before running integration tests.' );
}

$original_user = get_current_user_id();
$master_before = get_option( 'slb_bird_list_master', null );
$category_before = get_option( 'slb_bird_list_category', null );
$event_id = 0;
$subscriber = 0;
$report_ids = array();
$GLOBALS['slb_checks'] = 0;

function slb_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    $GLOBALS['slb_checks']++;
}

function slb_request( $method, $event_id, $body = null, $publish = false ) {
    $request = new WP_REST_Request( $method, '/slb-birds/v1/trips/' . $event_id . ( $publish ? '/publish' : '' ) );
    if ( $body !== null ) {
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $request );
}

try {
    $admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
    slb_assert( ! empty( $admin ), 'An administrator is required.' );
    wp_set_current_user( $admin[0]->ID );
    $birds = array(
        array( 'id' => 'robin', 'name' => 'Robin' ),
        array( 'id' => 'wren', 'name' => 'Wren' ),
        array( 'id' => 'kite', 'name' => 'Red Kite' ),
        array( 'id' => 'owl', 'name' => 'Barn Owl' ),
    );
    $bundled = SLB\TripBirdLists\bundled_master();
    slb_assert( count( $bundled ) === 263, 'The supplied master list contains 263 unique birds.' );
    slb_assert( count( array_unique( array_column( $bundled, 'id' ) ) ) === 263 && count( array_unique( array_column( $bundled, 'name' ) ) ) === 263, 'Bundled identifiers and bird names are unique.' );
    delete_option( 'slb_bird_list_master' );
    SLB\TripBirdLists\install();
    slb_assert( get_option( 'slb_bird_list_master' ) === $bundled, 'An unconfigured installation receives the bundled master list.' );
    update_option( 'slb_bird_list_master', $birds, false );
    SLB\TripBirdLists\install();
    slb_assert( get_option( 'slb_bird_list_master' ) === $birds, 'Upgrades preserve an administrator-configured master list.' );
    $category = get_term_by( 'slug', 'trip-list', 'category' );
    slb_assert( (bool) $category, 'Existing Trip list category is available.' );
    update_option( 'slb_bird_list_category', $category->term_id, false );
    $event_id = wp_insert_post( array( 'post_type' => 'tribe_events', 'post_title' => 'SLB automated test trip', 'post_status' => 'private' ), true );
    slb_assert( ! is_wp_error( $event_id ), 'Create a temporary event.' );
    update_post_meta( $event_id, '_EventStartDate', '2026-10-28 09:00:00' );

    $initial = slb_request( 'GET', $event_id );
    slb_assert( $initial->get_status() === 200 && count( $initial->get_data()['draft']['species'] ) === 4, 'Load the full master list.' );
    slb_assert( SLB\TripBirdLists\row( $event_id ) === null, 'Reading an unsaved checklist does not write a record.' );
    $body = array( 'revision' => 0, 'seen' => array( 'robin', 'kite' ), 'title' => 'Test trip report', 'summary' => "A lovely trip.\nTwo birds recorded." );
    $saved = slb_request( 'POST', $event_id, $body );
    slb_assert( $saved->get_status() === 200 && $saved->get_data()['revision'] === 1, 'Save the first revision.' );
    slb_assert( $saved->get_data()['reportId'] === 0, 'Saving never creates a public report.' );
    $body['revision'] = 1;
    $same = slb_request( 'POST', $event_id, $body );
    slb_assert( $same->get_data()['revision'] === 1, 'Identical save is idempotent.' );
    $stale = $body;
    $stale['revision'] = 0;
    $conflict = slb_request( 'POST', $event_id, $stale );
    slb_assert( $conflict->get_status() === 409 && isset( $conflict->get_data()['data']['current'] ), 'Stale saves return the latest checklist for review.' );
    $bad = $body;
    $bad['seen'] = array( 'invented' );
    slb_assert( slb_request( 'POST', $event_id, $bad )->get_status() === 400, 'Reject unknown species.' );
    $bad['revision'] = '1';
    slb_assert( slb_request( 'POST', $event_id, $bad )->get_status() === 400, 'Reject invalid revision types.' );

    $published = slb_request( 'POST', $event_id, array( 'revision' => 1 ), true );
    slb_assert( $published->get_status() === 200, 'Publish a report.' );
    $report_id = $published->get_data()['reportId'];
    $report_ids[] = $report_id;
    slb_assert( get_post_status( $report_id ) === 'publish' && in_array( (int) $category->term_id, wp_get_post_categories( $report_id ), true ), 'Use the existing report category.' );
    $html = do_shortcode( get_post( $report_id )->post_content );
    slb_assert( substr_count( $html, '<th scope="row">' ) === 4 && substr_count( $html, '✓ Seen' ) === 2, 'Render every bird, including unrecorded species.' );
    $card_excerpt = (object) array( 'name' => 'core/post-excerpt', 'context' => array( 'postId' => $report_id ) );
    $card_date = (object) array( 'name' => 'core/post-date', 'context' => array( 'postId' => $report_id ) );
    $card = SLB\TripBirdLists\listing_block( '', $card_excerpt );
    slb_assert( str_contains( $card, '2 seen · 0 heard · 2 recorded' ) && str_contains( $card, 'A lovely trip.' ), 'Cards show published totals and written summary.' );
    $date_card = SLB\TripBirdLists\listing_block( '<div><time datetime="2026-10-29">Added date</time></div>', $card_date );
    slb_assert( str_contains( $date_card, 'Trip date:' ) && str_contains( $date_card, 'datetime="2026-10-28"' ) && ! str_contains( $date_card, 'Added date' ), 'Cards use the event date instead of publication date.' );
    slb_assert( SLB\TripBirdLists\listing_block( 'Old PDF excerpt', (object) array( 'name' => 'core/post-excerpt', 'context' => array( 'postId' => 0 ) ) ) === 'Old PDF excerpt', 'Unlinked older posts remain unchanged.' );
    $again = slb_request( 'POST', $event_id, array( 'revision' => 1 ), true );
    slb_assert( $again->get_data()['reportId'] === $report_id, 'Publication retry reuses the same post.' );

    update_option( 'slb_bird_list_master', array( array( 'id' => 'new', 'name' => 'New bird' ) ), false );
    slb_assert( count( slb_request( 'GET', $event_id )->get_data()['draft']['species'] ) === 4, 'Master changes preserve historical snapshots.' );
    $body['seen'] = array( 'wren' );
    $body['summary'] = '<script>alert(1)</script>Updated trip summary';
    $changed = slb_request( 'POST', $event_id, $body );
    slb_assert( $changed->get_data()['unpublishedChanges'] === true, 'Draft corrections are marked unpublished.' );
    $after_draft = do_shortcode( get_post( $report_id )->post_content );
    slb_assert( $after_draft === $html, 'Draft corrections do not change the public report.' );
    slb_assert( SLB\TripBirdLists\listing_block( '', $card_excerpt ) === $card, 'Draft corrections do not leak into list cards.' );
    wp_update_post( array( 'ID' => $report_id, 'post_content' => "Manual introduction.\n" . get_post( $report_id )->post_content ) );
    slb_request( 'POST', $event_id, array( 'revision' => 2 ), true );
    slb_assert( str_contains( get_post( $report_id )->post_content, 'Manual introduction.' ), 'Report updates preserve manual content.' );
    $new_html = do_shortcode( get_post( $report_id )->post_content );
    slb_assert( substr_count( $new_html, '✓ Seen' ) === 1 && ! str_contains( $new_html, '<script>' ), 'Explicit update publishes the new snapshot with escaped content.' );

    wp_trash_post( $report_id );
    slb_assert( slb_request( 'POST', $event_id, array( 'revision' => 2 ), true )->get_status() === 409, 'Trashed reports require explicit recovery.' );
    $restored = slb_request( 'POST', $event_id, array( 'revision' => 2, 'recover' => true ), true );
    slb_assert( $restored->get_status() === 200 && $restored->get_data()['reportId'] === $report_id, 'Recovery restores the existing report.' );
    wp_delete_post( $report_id, true );
    slb_assert( slb_request( 'POST', $event_id, array( 'revision' => 2 ), true )->get_status() === 409, 'Deleted reports require explicit replacement.' );
    $replacement = slb_request( 'POST', $event_id, array( 'revision' => 2, 'recover' => true ), true );
    $report_ids[] = $replacement->get_data()['reportId'];
    slb_assert( $replacement->get_status() === 200 && end( $report_ids ) !== $report_id, 'Create an explicitly requested replacement.' );
    $heard_body = array( 'revision' => 2, 'title' => 'Heard report', 'summary' => '', 'seen' => array( 'wren' ), 'heard' => array( 'robin', 'wren' ) );
    $heard_saved = slb_request( 'POST', $event_id, $heard_body );
    slb_assert( $heard_saved->get_status() === 200 && $heard_saved->get_data()['draft']['heard'] === array( 'robin', 'wren' ), 'Save heard-only and both-seen-and-heard records.' );
    slb_assert( $heard_saved->get_data()['draft']['seen'] === array( 'wren' ), 'Hearing a bird does not mark it as seen.' );
    $replacement_id = end( $report_ids );
    slb_assert( ! str_contains( do_shortcode( get_post( $replacement_id )->post_content ), '✓ Heard' ), 'Heard draft changes remain private.' );
    slb_request( 'POST', $event_id, array( 'revision' => 3 ), true );
    $heard_html = do_shortcode( get_post( $replacement_id )->post_content );
    slb_assert( substr_count( $heard_html, '✓ Heard' ) === 2 && substr_count( $heard_html, '✓ Seen' ) === 1 && str_contains( $heard_html, '2 recorded out of 4' ), 'Publish separate heard and seen columns without double-counting species.' );
    $card_excerpt->context['postId'] = $replacement_id;
    slb_assert( str_contains( SLB\TripBirdLists\listing_block( '', $card_excerpt ), '1 seen · 2 heard · 2 recorded' ), 'Card totals do not double-count birds that are seen and heard, including reports without a written summary.' );
    $heard_body['revision'] = 3;
    $heard_body['heard'] = array( 'invented' );
    slb_assert( slb_request( 'POST', $event_id, $heard_body )->get_status() === 400, 'Reject unknown heard species.' );
    unset( $heard_body['heard'] );
    slb_assert( slb_request( 'POST', $event_id, $heard_body )->get_data()['draft']['heard'] === array( 'robin', 'wren' ), 'Older clients omitting heard preserve existing heard records.' );
    global $wpdb;
    $legacy = json_decode( SLB\TripBirdLists\row( $event_id )->draft, true );
    unset( $legacy['heard'] );
    $wpdb->update( table(), array( 'draft' => wp_json_encode( $legacy ), 'published' => wp_json_encode( $legacy ) ), array( 'event_id' => $event_id ) );
    slb_assert( slb_request( 'GET', $event_id )->get_data()['draft']['heard'] === array(), 'Legacy checklists load with heard unchecked.' );
    slb_assert( substr_count( do_shortcode( get_post( $replacement_id )->post_content ), '✓ Seen' ) === 1, 'Legacy published reports retain their seen records.' );
    wp_set_current_user( 0 );
    slb_assert( slb_request( 'GET', $event_id )->get_status() === 403, 'Anonymous users cannot read admin checklist data.' );
    slb_assert( slb_request( 'POST', $event_id, $body )->get_status() === 403, 'Anonymous users cannot save sightings.' );
    $subscriber = wp_insert_user( array( 'user_login' => 'slb_test_' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 30 ), 'role' => 'subscriber' ) );
    slb_assert( ! is_wp_error( $subscriber ), 'Create a temporary subscriber.' );
    wp_set_current_user( $subscriber );
    slb_assert( slb_request( 'GET', $event_id )->get_status() === 403, 'Subscribers cannot access trip editing.' );
    slb_assert( slb_request( 'POST', $event_id, array( 'revision' => 2 ), true )->get_status() === 403, 'Subscribers cannot publish reports.' );
    WP_CLI::success( $GLOBALS['slb_checks'] . ' integration checks passed.' );
} finally {
    wp_set_current_user( $original_user );
    foreach ( $report_ids as $report ) {
        if ( $report ) wp_delete_post( $report, true );
    }
    if ( $event_id && ! is_wp_error( $event_id ) ) {
        wp_delete_post( $event_id, true );
        global $wpdb;
        $wpdb->delete( table(), array( 'event_id' => $event_id ) );
    }
    if ( $subscriber && ! is_wp_error( $subscriber ) ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $subscriber );
    }
    if ( $master_before === null ) delete_option( 'slb_bird_list_master' );
    else update_option( 'slb_bird_list_master', $master_before, false );
    if ( $category_before === null ) delete_option( 'slb_bird_list_category' );
    else update_option( 'slb_bird_list_category', $category_before, false );
}
