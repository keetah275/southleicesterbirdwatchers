<?php
/**
 * Plugin Name: Local development safeguards
 * Description: Prevent outgoing WordPress email in the local environment.
 */

if ( wp_get_environment_type() !== 'local' ) {
    return;
}

add_filter( 'pre_wp_mail', '__return_true' );
