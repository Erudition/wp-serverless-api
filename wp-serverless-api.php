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

function wp_sls_api_discover_all() {
    $url = esc_url( home_url( '/' ) ) . 'wp-json/';
    $response = wp_remote_get( $url );
    $data = array();
    if ( ! is_wp_error( $response ) ) {
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
    }

    $types_url = esc_url( home_url( '/' ) ) . 'wp-json/wp/v2/types/';
    $types_response = wp_remote_get( $types_url );
    $types = array();
    if ( ! is_wp_error( $types_response ) && wp_remote_retrieve_response_code($types_response) === 200 ) {
        $types_body = wp_remote_retrieve_body( $types_response );
        $types = json_decode( $types_body, true );
    }
    
    $rest_bases = array();
    if ( is_array($types) ) {
        foreach ( $types as $type_slug => $type_info ) {
            if ( isset( $type_info['rest_base'] ) ) {
                $rest_bases[$type_info['rest_base']] = array(
                    'name' => isset($type_info['name']) ? $type_info['name'] : $type_slug,
                    'slug' => $type_slug,
                );
            }
        }
    }

    $paths = array();
    $all_fields = array();

    if ( isset( $data['routes'] ) ) {
        foreach ( $data['routes'] as $path => $details ) {
            // Only include standard wp/v2 routes
            if ( strpos( $path, '/wp/v2/' ) !== 0 ) continue;
            // Skip routes with regex parameters
            if ( strpos( $path, '(?P<' ) !== false ) continue;
            // Skip schema endpoints
            if ( substr( $path, -7 ) === '/schema' ) continue;

            $methods = isset( $details['methods'] ) ? $details['methods'] : array();
            if ( ! in_array( 'GET', $methods ) ) continue;

            $clean_path = ltrim( $path, '/' );
            $base_name = basename( $clean_path );
            
            $endpoint_url = esc_url( home_url( '/' ) ) . 'wp-json/' . $clean_path . '?per_page=1';
            $endpoint_response = wp_remote_get( $endpoint_url );
            $is_accessible = false;
            
            if ( ! is_wp_error( $endpoint_response ) && wp_remote_retrieve_response_code( $endpoint_response ) === 200 ) {
                $is_accessible = true;
                $sample_body = wp_remote_retrieve_body($endpoint_response);
                $sample_data = json_decode($sample_body, true);
                if ( is_array($sample_data) && !empty($sample_data) ) {
                    $first_item = array();
                    if ( isset($sample_data[0]) ) {
                        $first_item = $sample_data[0];
                    } else if ( array_keys($sample_data) !== range(0, count($sample_data) - 1) ) {
                        $first_item = $sample_data;
                    }

                    if ( is_array($first_item) ) {
                        foreach ($first_item as $field_key => $field_val) {
                            $all_fields[$field_key] = true;
                            if ( is_array($field_val) && $field_key === '_links' ) {
                                foreach ($field_val as $sub_key => $sub_val) {
                                    $all_fields[$field_key . '/' . $sub_key] = true;
                                }
                            }
                        }
                    }
                }
            }

            $is_default_checked = false;
            $type_slug = '';
            if ( isset( $rest_bases[$base_name] ) ) {
                $is_default_checked = true;
                $type_slug = $rest_bases[$base_name]['slug'];
            }
            
            if ( $type_slug === 'nav_menu_item' || 
                 $base_name === 'nav_menu_item' || 
                 strpos($base_name, 'wp_') === 0 || 
                 strpos($base_name, 'e-') === 0 || 
                 strpos($base_name, 'elementor_') === 0 ) {
                $is_default_checked = false;
            }

            $paths[$clean_path] = array(
                'accessible' => $is_accessible,
                'name' => isset($rest_bases[$base_name]['name']) ? $rest_bases[$base_name]['name'] : '',
                'default_checked' => $is_default_checked,
                'url' => esc_url( home_url( '/' ) ) . 'wp-json/' . $clean_path
            );
        }
    }

    return array(
        'paths' => $paths,
        'fields' => array_keys($all_fields)
    );
}

function wp_sls_api_get_discovery() {
    $cache = get_transient('wp_sls_api_discovery');
    if ( $cache !== false ) {
        return $cache;
    }
    $discovery = wp_sls_api_discover_all();
    set_transient('wp_sls_api_discovery', $discovery, HOUR_IN_SECONDS);
    return $discovery;
}

function wp_sls_api_filter_fields($item, $excluded_fields) {
    if ( !is_array($item) ) return $item;
    foreach ($excluded_fields as $field) {
        if ( strpos($field, '/') !== false ) {
            $parts = explode('/', $field, 2);
            $parent = $parts[0];
            $child = $parts[1];
            if ( isset($item[$parent]) && is_array($item[$parent]) ) {
                unset($item[$parent][$child]);
                if ( empty($item[$parent]) ) {
                    unset($item[$parent]);
                }
            }
        } else {
            unset($item[$field]);
        }
    }
    return $item;
}

function compile_db( $routes = array() ) {
    $has_saved_paths = get_option('wp_sls_api_excluded_paths') !== false;
    $excluded_paths = get_option('wp_sls_api_excluded_paths', array());
    $excluded_fields = get_option('wp_sls_api_excluded_fields', array('guid', '_links/curies'));

    if ( empty( $routes ) ) {
        $discovery = wp_sls_api_get_discovery();
        $routes = array();
        foreach ( $discovery['paths'] as $path => $info ) {
            if ( ! $info['accessible'] ) continue;
            
            $is_checked = $has_saved_paths ? !in_array($path, $excluded_paths) : $info['default_checked'];
            if ( $is_checked ) {
                $routes[] = $path;
            }
        }
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
            if ( !empty($excluded_fields) ) {
                foreach ( $jsonData as &$item ) {
                    $item = wp_sls_api_filter_fields($item, $excluded_fields);
                }
            }

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

// --- Admin Settings Page ---

add_action( 'admin_menu', 'wp_sls_api_admin_menu' );

function wp_sls_api_admin_menu() {
    add_options_page( 'WP Serverless API Settings', 'WP Serverless API', 'manage_options', 'wp-sls-api', 'wp_sls_api_settings_page' );
}

function wp_sls_api_settings_page() {
    if ( isset( $_POST['wp_sls_api_save'] ) && check_admin_referer( 'wp_sls_api_save_action' ) ) {
        if ( isset($_POST['wp_sls_api_refresh_cache']) ) {
            delete_transient('wp_sls_api_discovery');
        }
        
        $discovery = wp_sls_api_get_discovery();
        $paths = $discovery['paths'];
        $fields = $discovery['fields'];

        $submitted_included_paths = isset($_POST['included_paths']) ? array_map('sanitize_text_field', $_POST['included_paths']) : array();
        $submitted_included_fields = isset($_POST['included_fields']) ? array_map('sanitize_text_field', $_POST['included_fields']) : array();

        $excluded_paths = array();
        foreach ( $paths as $path => $info ) {
            if ( !in_array($path, $submitted_included_paths) ) {
                $excluded_paths[] = $path;
            }
        }

        $excluded_fields = array();
        foreach ( $fields as $field ) {
            if ( !in_array($field, $submitted_included_fields) ) {
                $excluded_fields[] = $field;
            }
        }

        update_option( 'wp_sls_api_excluded_paths', $excluded_paths );
        update_option( 'wp_sls_api_excluded_fields', $excluded_fields );
        
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }

    $discovery = wp_sls_api_get_discovery();
    $paths = $discovery['paths'];
    $fields = $discovery['fields'];
    sort($fields);

    $has_saved_paths = get_option('wp_sls_api_excluded_paths') !== false;
    $saved_excluded_paths = get_option('wp_sls_api_excluded_paths', array());
    $saved_excluded_fields = get_option('wp_sls_api_excluded_fields', array('guid', '_links/curies'));

    ?>
    <div class="wrap">
        <h1>WP Serverless API Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'wp_sls_api_save_action' ); ?>
            
            <h2>Discovered Paths (/wp/v2/)</h2>
            <p>Uncheck paths to exclude them from the generated JSON.</p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></th>
                        <th class="manage-column">Path</th>
                        <th class="manage-column">Friendly Name</th>
                        <th class="manage-column">Preview</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $paths as $path => $info ): 
                        $is_checked = $has_saved_paths ? !in_array($path, $saved_excluded_paths) : $info['default_checked'];
                        $disabled = ! $info['accessible'] ? 'disabled' : '';
                        if ( $disabled ) $is_checked = false; 
                    ?>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" name="included_paths[]" value="<?php echo esc_attr($path); ?>" <?php checked($is_checked); ?> <?php echo $disabled; ?> />
                        </th>
                        <td><code <?php if($disabled) echo 'style="color:#999;"'; ?>><?php echo esc_html($path); ?></code></td>
                        <td><?php echo esc_html($info['name']); ?></td>
                        <td>
                            <?php if ( $info['accessible'] ): ?>
                                <a href="<?php echo esc_url($info['url']); ?>" target="_blank">Preview</a>
                            <?php else: ?>
                                <span style="color:#999;">Not accessible</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Discovered Fields</h2>
            <p>Uncheck fields to exclude them from all endpoint responses.</p>
            <div style="column-count: 3; background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <?php foreach ( $fields as $field ): 
                    $is_checked = !in_array($field, $saved_excluded_fields);
                ?>
                <div style="margin-bottom: 5px;">
                    <label>
                        <input type="checkbox" name="included_fields[]" value="<?php echo esc_attr($field); ?>" <?php checked($is_checked); ?> />
                        <code><?php echo esc_html($field); ?></code>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>

            <p class="submit">
                <input type="submit" name="wp_sls_api_save" class="button button-primary" value="Save Changes" />
                <label style="margin-left: 15px;">
                    <input type="checkbox" name="wp_sls_api_refresh_cache" value="1" /> Force Refresh Discovery Cache
                </label>
            </p>
        </form>
    </div>
    <script>
        document.getElementById('cb-select-all-1').addEventListener('change', function(e) {
            var checkboxes = document.querySelectorAll('input[name="included_paths[]"]:not(:disabled)');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = e.target.checked;
            }
        });
    </script>
    <?php
}
