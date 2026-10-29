<?php
/** Local-only fixture for the real WordPress browser test. */
if ( wp_get_environment_type() !== 'local' || ! function_exists( 'SLB\\TripBirdLists\\save' ) ) {
    WP_CLI::error( 'Activate the plugin on a local environment first.' );
}
$fixture_file = dirname( ABSPATH, 2 ) . '/.local-migration/live-browser-fixture.json';
$mode = $args[0] ?? 'setup';
if ( $mode === 'cleanup' ) {
    if ( ! file_exists( $fixture_file ) ) {
        WP_CLI::success( 'No browser fixture to remove.' );
        return;
    }
    $fixture = json_decode( file_get_contents( $fixture_file ), true );
    foreach ( array_filter( array( $fixture['eventId'], $fixture['dashboardEventId'] ?? null ) ) as $fixture_event ) {
    if ( get_post_meta( $fixture_event, '_slb_browser_fixture', true ) === 'yes' ) {
        $record = SLB\TripBirdLists\row( $fixture_event );
        if ( $record && $record->report_id ) wp_delete_post( $record->report_id, true );
        wp_delete_post( $fixture_event, true );
        global $wpdb;
        $wpdb->delete( SLB\TripBirdLists\table(), array( 'event_id' => $fixture_event ) );
    }
    }
    if ( get_user_meta( $fixture['userId'], '_slb_browser_fixture', true ) === 'yes' ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $fixture['userId'] );
    }
    unlink( $fixture_file );
    WP_CLI::success( 'Temporary browser fixture removed.' );
    return;
}
if ( file_exists( $fixture_file ) ) {
    WP_CLI::error( 'Clean up the previous browser fixture before setting up another.' );
}
$user = wp_insert_user( array( 'user_login' => 'slb_browser_' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 40 ), 'role' => 'administrator' ) );
if ( is_wp_error( $user ) ) WP_CLI::error( $user->get_error_message() );
update_user_meta( $user, '_slb_browser_fixture', 'yes' );
wp_set_current_user( $user );
$today = current_datetime()->format( 'Y-m-d' );
$create_event = function ( $title ) use ( $today ) {
    $event = tribe_events()->set_args( array( 'title' => $title, 'status' => 'publish',
        'start_date' => $today . ' 09:00:00', 'end_date' => $today . ' 17:00:00',
        'timezone' => wp_timezone_string() ) )->create();
    if ( ! $event instanceof WP_Post ) throw new RuntimeException( 'Unable to create browser fixture event.' );
    update_post_meta( $event->ID, '_slb_browser_fixture', 'yes' );
    $term = get_term_by( 'slug', 'saturday-trip', 'tribe_events_cat' );
    if ( $term ) wp_set_object_terms( $event->ID, array( (int) $term->term_id ), 'tribe_events_cat' );
    return $event->ID;
};
$event = $create_event( 'SLB mobile browser test trip' );
$dashboard_event = $create_event( 'SLB dashboard browser test trip' );
file_put_contents( $fixture_file, wp_json_encode( array( 'eventId' => $event, 'dashboardEventId' => $dashboard_event, 'userId' => $user ) ) );
chmod( $fixture_file, 0600 );
$master = get_option( 'slb_bird_list_master', null );
$birds = array( array( 'id' => 'test-robin', 'name' => 'Robin' ), array( 'id' => 'test-wren', 'name' => 'Wren' ) );
for ( $i = 3; $i <= 60; $i++ ) $birds[] = array( 'id' => 'test-' . $i, 'name' => 'Test bird ' . $i );
try {
    update_option( 'slb_bird_list_master', $birds, false );
    $request = new WP_REST_Request( 'POST', '/slb-birds/v1/trips/' . $event );
    $request->set_param( 'id', $event );
    $request->set_param( 'revision', 0 );
    $request->set_param( 'seen', array() );
    $request->set_param( 'title', 'SLB mobile browser test report' );
    $request->set_param( 'summary', '' );
    $result = SLB\TripBirdLists\save( $request );
    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
} finally {
    if ( $master === null ) delete_option( 'slb_bird_list_master' );
    else update_option( 'slb_bird_list_master', $master, false );
}
$expiry = time() + 3600;
$token = WP_Session_Tokens::get_instance( $user )->create( $expiry );
$fixture = array(
    'eventId' => $event, 'dashboardEventId' => $dashboard_event, 'userId' => $user,
    'editorUrl' => SLB\TripBirdLists\editor_url( $event ),
    'settingsUrl' => SLB\TripBirdLists\settings_url(),
    'origin' => home_url(),
    'masterNames' => array_column( $master ?? array(), 'name' ),
    'cookies' => array(
        array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user, $expiry, 'auth', $token ), 'url' => home_url() ),
        array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user, $expiry, 'logged_in', $token ), 'url' => home_url() ),
    ),
);
file_put_contents( $fixture_file, wp_json_encode( $fixture ) );
chmod( $fixture_file, 0600 );
WP_CLI::success( 'Temporary browser fixture ready.' );
