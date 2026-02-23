<?php

/*
Plugin Name: WP Serverless API
Plugin URI: https://github.com/getshifter/wp-serverless-api
Description: WordPress REST API to JSON File
Version: 0.3.0
Author: Shifter
Author URI: https://getshifter.io
*/

function enable_permalinks_notice() {
    ?>
    <div class="notice notice-warning">
        <p><?php _e( 'WP Serverless Redirects requires Permalinks. <a href="/wp-admin/options-permalink.php">Enable Permalinks</a>'); ?></p>
    </div>
    <?php
}

if ( !get_option('permalink_structure') ) {
    add_action( 'admin_notices', 'enable_permalinks_notice' );
}

/**
 * Get available REST API routes dynamically
 */
function get_api_routes() {
    $url = esc_url( home_url( '/' ) ) . 'wp-json/';
    $response = wp_remote_get( $url );

    if ( is_wp_error( $response ) ) {
        return array( 'wp/v2/posts', 'wp/v2/pages', 'wp/v2/media' );
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( ! isset( $data['routes'] ) ) {
        return array( 'wp/v2/posts', 'wp/v2/pages', 'wp/v2/media' );
    }

    $found_routes = array();
    foreach ( $data['routes'] as $path => $details ) {
        // Only include standard wp/v2 routes
        if ( strpos( $path, '/wp/v2/' ) !== 0 ) {
            continue;
        }

        // Skip routes with regex parameters (individual items or sub-resources)
        if ( strpos( $path, '(?P<' ) !== false ) {
            continue;
        }

        // Skip schema endpoints
        if ( substr( $path, -7 ) === '/schema' ) {
            continue;
        }

        // Only include routes that support GET
        $methods = isset( $details['methods'] ) ? $details['methods'] : array();
        if ( ! in_array( 'GET', $methods ) ) {
            continue;
        }

        $found_routes[] = ltrim( $path, '/' );
    }

    return array_unique( $found_routes );
}

function compile_db( $routes = array() ) {

    if ( empty( $routes ) ) {
        $routes = get_api_routes();
    }

    $db_array = array();

    foreach ( $routes as $route ) {
        $url =  esc_url( home_url( '/' ) ) . 'wp-json/' . $route;
        
        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) {
            continue;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            continue;
        }

        $body = wp_remote_retrieve_body( $response );
        $jsonData = json_decode( $body, true );

        // Only include non-empty collections and simplify the key
        if ( is_array( $jsonData ) && ! empty( $jsonData ) ) {
            $key = basename( $route );
            $db_array[$key] = $jsonData;
        }
    }

    $db = json_encode($db_array);

    return $db;

}

function save_db(
        $db,
        $file_name = 'db.json'
    ) {
    $save_path = WP_CONTENT_DIR . '/wp-sls-api/' . $file_name;
    $dirname = dirname($save_path);

    if (!is_dir($dirname))
    {
        mkdir($dirname, 0755, true);
    }

    $f = fopen( $save_path , "w+" );
    fwrite($f , $db);
    fclose($f);
}

function build_db()
{
    $db = compile_db();
    save_db($db);
}
add_action( 'wp_serverless_api_build_db_worker', 'build_db' );

function schedule_build_db()
{
    if ( ! wp_next_scheduled( 'wp_serverless_api_build_db_worker' ) ) {
        wp_schedule_single_event( time(), 'wp_serverless_api_build_db_worker' );
    }
}

/**
 * Build on Post Save
 */
add_action( 'save_post', 'schedule_build_db' );
