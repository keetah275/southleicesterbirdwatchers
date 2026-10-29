<?php
/** Run through wp eval-file on the local installation only. */
if ( wp_get_environment_type() !== 'local' ) WP_CLI::error( 'Local testing only.' );
$original_user = get_current_user_id();
$created = array();
$created_term = false;
$checks = 0;
$assert = function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) throw new RuntimeException( $message );
    $checks++;
};
$make = function ( $label, $date, $category = true, $status = 'publish' ) use ( &$created ) {
    $end = ( new DateTimeImmutable( $date, wp_timezone() ) )->modify( '+1 hour' )->format( 'Y-m-d H:i:s' );
    $event = tribe_events()->set_args( array( 'title' => 'SLB dashboard test ' . $label, 'status' => $status,
        'start_date' => $date, 'end_date' => $end, 'timezone' => wp_timezone_string() ) )->create();
    if ( ! $event instanceof WP_Post ) throw new RuntimeException( 'Unable to create calendar test event.' );
    $id = $event->ID;
    $created[] = $id;
    if ( $category ) {
        $term = get_term_by( 'slug', 'saturday-trip', 'tribe_events_cat' );
        wp_set_object_terms( $id, array( (int) $term->term_id ), 'tribe_events_cat' );
    }
    return $id;
};
try {
    $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
    wp_set_current_user( $admins[0]->ID );
    $term = get_term_by( 'slug', 'saturday-trip', 'tribe_events_cat' );
    if ( ! $term ) {
        $new_term = wp_insert_term( 'Saturday trip', 'tribe_events_cat', array( 'slug' => 'saturday-trip' ) );
        $created_term = $new_term['term_id'];
    }
    $today = new DateTimeImmutable( '2026-10-29 12:00:00', wp_timezone() );
    $ids = array(
        'today' => $make( 'today', '2026-10-29 23:59:59' ),
        'yesterday' => $make( 'yesterday', '2026-10-28 09:00:00' ),
        'boundary' => $make( 'boundary', '2026-10-15 00:00:00' ),
        'too_old' => $make( 'too old', '2026-10-14 23:59:59' ),
        'future' => $make( 'tomorrow', '2026-10-30 00:00:00' ),
        'meeting' => $make( 'not a trip', '2026-10-29 09:00:00', false ),
        'draft' => $make( 'unpublished', '2026-10-29 09:00:00', true, 'draft' ),
        'existing' => $make( 'existing checklist', '2026-10-29 10:00:00' ),
    );
    global $wpdb;
    $wpdb->insert( SLB\TripBirdLists\table(), array( 'event_id' => $ids['existing'], 'revision' => 1,
        'draft' => wp_json_encode( SLB\TripBirdLists\initial_draft( $ids['existing'] ) ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
    $visible = array_map( 'intval', wp_list_pluck( SLB\TripBirdLists\dashboard_trips( $today ), 'ID' ) );
    foreach ( array( 'today', 'yesterday', 'boundary' ) as $key ) $assert( in_array( $ids[$key], $visible, true ), "$key trip is included" );
    foreach ( array( 'too_old', 'future', 'meeting', 'draft', 'existing' ) as $key ) $assert( ! in_array( $ids[$key], $visible, true ), "$key event is excluded" );
    $wpdb->delete( SLB\TripBirdLists\table(), array( 'event_id' => $ids['existing'] ) );
    $visible = array_map( 'intval', wp_list_pluck( SLB\TripBirdLists\dashboard_trips( $today ), 'ID' ) );
    $assert( in_array( $ids['existing'], $visible, true ), 'A trip without a saved checklist can reappear' );
    wp_set_current_user( 0 );
    $assert( SLB\TripBirdLists\dashboard_trips( $today ) === array(), 'Non-administrators cannot access the dashboard trip list' );
    WP_CLI::success( "$checks dashboard checks passed." );
} finally {
    global $wpdb;
    foreach ( $created as $id ) {
        $wpdb->delete( SLB\TripBirdLists\table(), array( 'event_id' => $id ) );
        wp_delete_post( $id, true );
    }
    if ( $created_term ) wp_delete_term( $created_term, 'tribe_events_cat' );
    wp_set_current_user( $original_user );
}
