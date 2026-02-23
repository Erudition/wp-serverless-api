<?php

/*
Plugin Name: WP Serverless API
Plugin URI: https://github.com/getshifter/wp-serverless-api
Description: WordPress REST API to JSON File
Version: 1.0.0
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

    $rest_bases = array();

    // Fetch Post Types
    $types_url = esc_url( home_url( '/' ) ) . 'wp-json/wp/v2/types/';
    $types_response = wp_remote_get( $types_url );
    if ( ! is_wp_error( $types_response ) && wp_remote_retrieve_response_code($types_response) === 200 ) {
        $types = json_decode( wp_remote_retrieve_body( $types_response ), true );
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
    }

    // Fetch Taxonomies
    $tax_url = esc_url( home_url( '/' ) ) . 'wp-json/wp/v2/taxonomies/';
    $tax_response = wp_remote_get( $tax_url );
    if ( ! is_wp_error( $tax_response ) && wp_remote_retrieve_response_code($tax_response) === 200 ) {
        $taxonomies = json_decode( wp_remote_retrieve_body( $tax_response ), true );
        if ( is_array($taxonomies) ) {
            foreach ( $taxonomies as $tax_slug => $tax_info ) {
                if ( isset( $tax_info['rest_base'] ) ) {
                    $rest_bases[$tax_info['rest_base']] = array(
                        'name' => isset($tax_info['name']) ? $tax_info['name'] : $tax_slug,
                        'slug' => $tax_slug,
                    );
                }
            }
        }
    }
    
    $groups = array();

    if ( isset( $data['routes'] ) ) {
        foreach ( $data['routes'] as $path => $details ) {
            // Skip root
            if ( $path === '/' ) continue;
            // Skip namespace roots
            if ( isset( $details['namespace'] ) && $path === '/' . $details['namespace'] ) continue;
            // Skip routes with regex parameters
            if ( strpos( $path, '(?P<' ) !== false ) continue;
            // Skip schema endpoints
            if ( substr( $path, -7 ) === '/schema' ) continue;

            // Only include routes that support GET
            if ( ! isset($details['endpoints']) || ! is_array($details['endpoints']) ) continue;
            
            $valid_get_endpoint = false;
            foreach ($details['endpoints'] as $endpoint) {
                if ( in_array('GET', $endpoint['methods']) ) {
                    $has_required_args = false;
                    if ( isset($endpoint['args']) && is_array($endpoint['args']) ) {
                        foreach ($endpoint['args'] as $arg_details) {
                            if ( isset($arg_details['required']) && $arg_details['required'] === true ) {
                                $has_required_args = true;
                                break;
                            }
                        }
                    }
                    if ( ! $has_required_args ) {
                        $valid_get_endpoint = true;
                        break;
                    }
                }
            }

            if ( ! $valid_get_endpoint ) continue;

            $clean_path = ltrim( $path, '/' );
            $base_name = basename( $clean_path );
            
            // Determine Group
            $parts = explode('/', $clean_path);
            if (count($parts) >= 2) {
                $group_key = $parts[0] . '/' . $parts[1];
                $group_label = $parts[0] . ' ' . $parts[1];
            } else {
                $group_key = 'other';
                $group_label = 'Other';
            }

            $endpoint_url = esc_url( home_url( '/' ) ) . 'wp-json/' . $clean_path . '?per_page=1';
            $endpoint_response = wp_remote_get( $endpoint_url );
            $is_accessible = false;
            $total_items = 0;
            $total_fields = 0;
            $is_list = false;
            $path_fields = array();
            
            if ( ! is_wp_error( $endpoint_response ) ) {
                if ( wp_remote_retrieve_response_code( $endpoint_response ) === 200 ) {
                    $is_accessible = true;
                    $sample_body = wp_remote_retrieve_body($endpoint_response);
                    $sample_data = json_decode($sample_body, true);
                    
                    if ( is_array($sample_data) && !empty($sample_data) ) {
                        $is_list = isset($sample_data[0]);
                        $first_item = array();
                        
                        if ( $is_list ) {
                            $total_items = (int) wp_remote_retrieve_header( $endpoint_response, 'x-wp-total' );
                            $first_item = $sample_data[0];
                        } else {
                            $is_list = false;
                            $total_fields = count($sample_data);
                            $first_item = $sample_data;
                        }

                        if ( is_array($first_item) ) {
                            foreach ($first_item as $field_key => $field_val) {
                                $path_fields[$field_key] = true;
                                if ( is_array($field_val) && $field_key === '_links' ) {
                                    foreach ($field_val as $sub_key => $sub_val) {
                                        $path_fields[$field_key . '/' . $sub_key] = true;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $is_default_checked = false;
            $friendly_name = isset($rest_bases[$base_name]['name']) ? $rest_bases[$base_name]['name'] : '';
            
            if ( $clean_path === 'wp/v2/types' ) $friendly_name = 'Post Types';
            if ( $clean_path === 'wp/v2/taxonomies' ) $friendly_name = 'Taxonomies';

            if ( $group_key === 'wp/v2' ) {
                if ( !empty($friendly_name) || isset( $rest_bases[$base_name] ) ) {
                    $is_default_checked = true;
                }
                
                if ( $base_name === 'nav_menu_item' || 
                     $base_name === 'navigation' ||
                     $base_name === 'blocks' ||
                     $base_name === 'types' ||
                     $base_name === 'taxonomies' ||
                     $base_name === 'media' ||
                     strpos($base_name, 'wp_') === 0 || 
                     strpos($base_name, 'e-') === 0 || 
                     strpos($base_name, 'elementor_') === 0 ) {
                    $is_default_checked = false;
                }
            }

            if (!isset($groups[$group_key])) {
                $groups[$group_key] = array(
                    'label' => $group_label,
                    'paths' => array(),
                    'fields' => array()
                );
            }

            $groups[$group_key]['paths'][$clean_path] = array(
                'accessible' => $is_accessible,
                'name' => $friendly_name,
                'default_checked' => $is_default_checked,
                'url' => esc_url( home_url( '/' ) ) . 'wp-json/' . $clean_path,
                'total_items' => $total_items,
                'total_fields' => $total_fields,
                'is_list' => $is_list,
                'base_name' => $base_name,
                'fields' => array_keys($path_fields)
            );
            
            foreach ($path_fields as $f => $v) {
                $groups[$group_key]['fields'][$f] = true;
            }
        }
    }

    // Ensure wp/v2 is first
    if (isset($groups['wp/v2'])) {
        $wp_v2 = array('wp/v2' => $groups['wp/v2']);
        unset($groups['wp/v2']);
        $groups = array_merge($wp_v2, $groups);
    }

    return array(
        'groups' => $groups
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
    $custom_output_paths = get_option('wp_sls_api_output_paths', array());
    $excluded_fields = get_option('wp_sls_api_excluded_fields', array('guid', '_links/curies'));

    if ( empty( $routes ) ) {
        $discovery = wp_sls_api_get_discovery();
        $routes = array();
        foreach ( $discovery['groups'] as $group_key => $group ) {
            foreach ( $group['paths'] as $path => $info ) {
                if ( ! $info['accessible'] ) continue;
                $is_checked = $has_saved_paths ? !in_array($path, $excluded_paths) : $info['default_checked'];
                if ( $is_checked ) {
                    $routes[] = $path;
                }
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
                    if ( is_array($item) ) {
                        $item = wp_sls_api_filter_fields($item, $excluded_fields);
                    }
                }
            }

            $key = isset($custom_output_paths[$route]) && !empty($custom_output_paths[$route]) ? $custom_output_paths[$route] : basename( $route );
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

function wp_sls_api_admin_menu_styles() {
    echo '<style>
        .sls-api-filter-row { margin: 10px 0; background: #f6f7f7; padding: 10px; border: 1px solid #ccd0d4; }
        .sls-api-path-hidden { display: none !important; }
        .sls-api-field-nested { margin-left: 25px; border-left: 2px solid #eee; padding-left: 10px; }
        .sls-api-group-header { background: #333; color: #fff; padding: 10px; margin: 30px 0 0 0; }
        .sls-api-fields-grid { column-count: 3; background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
    </style>';
}
add_action('admin_head', 'wp_sls_api_admin_menu_styles');

function wp_sls_api_settings_page() {
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'paths';

    if ( isset( $_POST['wp_sls_api_build_now'] ) && check_admin_referer( 'wp_sls_api_save_action' ) ) {
        build_db();
        $db_url = content_url('/wp-sls-api/db.json');
        echo '<div class="notice notice-success is-dismissible"><p>Database built successfully. <a href="' . esc_url($db_url) . '" target="_blank">View in browser</a></p></div>';
    }

    if ( isset( $_POST['wp_sls_api_rediscover'] ) && check_admin_referer( 'wp_sls_api_save_action' ) ) {
        delete_transient( 'wp_sls_api_discovery' );
        echo '<div class="notice notice-success is-dismissible"><p>Discovery cache refreshed.</p></div>';
    }

    if ( isset( $_POST['wp_sls_api_reset'] ) && check_admin_referer( 'wp_sls_api_save_action' ) ) {
        delete_option( 'wp_sls_api_excluded_paths' );
        delete_option( 'wp_sls_api_excluded_fields' );
        delete_option( 'wp_sls_api_output_paths' );
        delete_transient( 'wp_sls_api_discovery' );
        echo '<script>window.location.href="?page=wp-sls-api&tab=' . esc_attr($current_tab) . '";</script>';
        return;
    }

    if ( isset( $_POST['wp_sls_api_save'] ) && check_admin_referer( 'wp_sls_api_save_action' ) ) {
        $discovery = wp_sls_api_get_discovery();
        $groups = $discovery['groups'];

        if ( $current_tab === 'paths' ) {
            $submitted_included_paths = isset($_POST['included_paths']) ? array_map('sanitize_text_field', $_POST['included_paths']) : array();
            $submitted_output_paths = isset($_POST['output_paths']) ? $_POST['output_paths'] : array();

            $excluded_paths = array();
            foreach ( $groups as $group ) {
                foreach ($group['paths'] as $path => $info) {
                    if ( !in_array($path, $submitted_included_paths) ) {
                        $excluded_paths[] = $path;
                    }
                }
            }

            $output_paths = array();
            foreach ( $submitted_output_paths as $path => $val ) {
                $output_paths[sanitize_text_field($path)] = sanitize_text_field($val);
            }

            update_option( 'wp_sls_api_excluded_paths', $excluded_paths );
            update_option( 'wp_sls_api_output_paths', $output_paths );
        } else {
            $submitted_included_fields = isset($_POST['included_fields']) ? array_map('sanitize_text_field', $_POST['included_fields']) : array();
            
            $all_fields = array();
            foreach ( $groups as $group ) {
                foreach ($group['fields'] as $f => $v) $all_fields[$f] = true;
            }
            $all_field_keys = array_keys($all_fields);

            $excluded_fields = array();
            foreach ( $all_field_keys as $field ) {
                if ( !in_array($field, $submitted_included_fields) ) {
                    $excluded_fields[] = $field;
                }
            }
            update_option( 'wp_sls_api_excluded_fields', $excluded_fields );
        }
        
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }

    $discovery = wp_sls_api_get_discovery();
    $groups = $discovery['groups'];

    $has_saved_paths = get_option('wp_sls_api_excluded_paths') !== false;
    $saved_excluded_paths = get_option('wp_sls_api_excluded_paths', array());
    $saved_excluded_fields = get_option('wp_sls_api_excluded_fields', array('guid', '_links/curies'));
    $saved_output_paths = get_option('wp_sls_api_output_paths', array());

    $active_fields = array();
    foreach ( $groups as $group ) {
        foreach ( $group['paths'] as $path => $info ) {
            $is_checked = $has_saved_paths ? !in_array($path, $saved_excluded_paths) : $info['default_checked'];
            if ( $is_checked && $info['accessible'] ) {
                foreach ($info['fields'] as $f) $active_fields[$f] = true;
            }
        }
    }
    $display_fields = array_keys($active_fields);
    sort($display_fields);

    ?>
    <div class="wrap">
        <h1>WP Serverless API Settings</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=wp-sls-api&tab=paths" class="nav-tab <?php echo $current_tab == 'paths' ? 'nav-tab-active' : ''; ?>">Paths</a>
            <a href="?page=wp-sls-api&tab=fields" class="nav-tab <?php echo $current_tab == 'fields' ? 'nav-tab-active' : ''; ?>">Fields</a>
        </h2>

        <form method="post" action="">
            <?php wp_nonce_field( 'wp_sls_api_save_action' ); ?>
            
            <div class="sls-api-filter-row">
                <input type="submit" name="wp_sls_api_rediscover" class="button" value="Re-discover" style="float: right;" />
                <?php if ($current_tab === 'paths'): ?>
                    <strong>Filter List:</strong>
                    <label style="margin-left: 10px;"><input type="checkbox" id="filter-public" checked> Public Only</label>
                    <label style="margin-left: 10px;"><input type="checkbox" id="filter-named" checked> Named Only</label>
                <?php else: ?>
                    <strong>Fields grouped by component</strong>
                <?php endif; ?>
            </div>

            <?php if ($current_tab === 'paths'): ?>
                <?php foreach ( $groups as $group_key => $group ): ?>
                    <h3 class="sls-api-group-header"><?php echo esc_html($group['label']); ?></h3>
                    <table class="wp-list-table widefat fixed striped paths-table">
                        <thead>
                            <tr>
                                <th class="manage-column column-cb check-column"><input type="checkbox" class="cb-select-group"></th>
                                <th class="manage-column">Input Path</th>
                                <th class="manage-column">Output Path</th>
                                <th class="manage-column">Friendly Name</th>
                                <th class="manage-column">Preview</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $group['paths'] as $path => $info ): 
                                $is_checked = $has_saved_paths ? !in_array($path, $saved_excluded_paths) : $info['default_checked'];
                                $accessible = $info['accessible'];
                                $disabled_attr = ! $accessible ? 'disabled' : '';
                                if ( ! $accessible ) $is_checked = false; 
                                $out_val = isset($saved_output_paths[$path]) ? $saved_output_paths[$path] : $info['base_name'];
                                $has_name = !empty($info['name']);
                                
                                $row_classes = array();
                                if ( !$accessible ) $row_classes[] = 'row-private';
                                if ( !$has_name ) $row_classes[] = 'row-unnamed';

                                // Strip prefix implied by section
                                $display_input_path = ltrim(substr($path, strlen($group_key)), '/');
                                if ( empty($display_input_path) ) $display_input_path = $path; // fallback
                            ?>
                            <tr class="<?php echo implode(' ', $row_classes); ?>">
                                <th class="check-column">
                                    <input type="checkbox" name="included_paths[]" value="<?php echo esc_attr($path); ?>" <?php checked($is_checked); ?> <?php echo $disabled_attr; ?> />
                                </th>
                                <td><code <?php if(!$accessible) echo 'style="color:#999;"'; ?>><?php echo esc_html($display_input_path); ?></code></td>
                                <td>
                                    <input type="text" name="output_paths[<?php echo esc_attr($path); ?>]" value="<?php echo esc_attr($out_val); ?>" class="regular-text" style="width:100%;" />
                                </td>
                                <td><?php echo esc_html($info['name']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($info['url']); ?>" target="_blank" <?php if(!$accessible) echo 'style="color:#999;"'; ?>>
                                        <?php if ( $accessible ): 
                                            if ( $info['is_list'] && $info['total_items'] > 0 ) {
                                                echo sprintf('View %d items', $info['total_items']);
                                            } else if ( !$info['is_list'] && $info['total_fields'] > 0 ) {
                                                echo sprintf('View %d fields', $info['total_fields']);
                                            } else {
                                                echo 'View';
                                            }
                                        else: ?>
                                            Not public
                                        <?php endif; ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ( $groups as $group_key => $group ): 
                    // Only show fields for paths that are currently selected AND accessible
                    $display_group_fields = array();
                    foreach ($group['paths'] as $path => $info) {
                        $is_checked = $has_saved_paths ? !in_array($path, $saved_excluded_paths) : $info['default_checked'];
                        if ($is_checked && $info['accessible']) {
                            foreach ($info['fields'] as $f) $display_group_fields[$f] = true;
                        }
                    }
                    if (empty($display_group_fields)) continue;
                    $sorted_fields = array_keys($display_group_fields);
                    sort($sorted_fields);
                ?>
                    <h3 class="sls-api-group-header"><?php echo esc_html($group['label']); ?></h3>
                    <div class="sls-api-fields-grid">
                        <?php foreach ( $sorted_fields as $field ): 
                            $is_checked = !in_array($field, $saved_excluded_fields);
                            $is_nested = strpos($field, '/') !== false;
                            $field_class = $is_nested ? 'sls-api-field-nested' : '';
                        ?>
                        <div style="margin-bottom: 5px;" class="<?php echo $field_class; ?>">
                            <label>
                                <input type="checkbox" name="included_fields[]" value="<?php echo esc_attr($field); ?>" <?php checked($is_checked); ?> />
                                <code><?php echo esc_html($field); ?></code>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <p class="submit">
                <input type="submit" name="wp_sls_api_save" class="button button-primary" value="Save Changes" />
                <input type="submit" name="wp_sls_api_build_now" class="button" value="Build Database Now" />
                <input type="submit" name="wp_sls_api_reset" class="button" value="Reset to Defaults" onclick="return confirm('Are you sure you want to reset all settings to defaults?');" />
            </p>
        </form>
    </div>
    <script>
        (function() {
            var publicFilter = document.getElementById('filter-public');
            var namedFilter = document.getElementById('filter-named');
            
            function applyFilters() {
                var tables = document.querySelectorAll('.paths-table');
                tables.forEach(function(table) {
                    var rows = table.querySelectorAll('tbody tr');
                    var visibleRows = 0;
                    rows.forEach(function(row) {
                        var isPrivate = row.classList.contains('row-private');
                        var isUnnamed = row.classList.contains('row-unnamed');
                        
                        var hide = false;
                        if (publicFilter && publicFilter.checked && isPrivate) hide = true;
                        if (namedFilter && namedFilter.checked && isUnnamed) hide = true;
                        
                        if (hide) {
                            row.classList.add('sls-api-path-hidden');
                        } else {
                            row.classList.remove('sls-api-path-hidden');
                            visibleRows++;
                        }
                    });
                    
                    var header = table.previousElementSibling;
                    if (header && header.classList.contains('sls-api-group-header')) {
                        header.style.display = (visibleRows === 0) ? 'none' : 'block';
                        table.style.display = (visibleRows === 0) ? 'none' : 'table';
                    }
                });
            }
            
            if (publicFilter) publicFilter.addEventListener('change', applyFilters);
            if (namedFilter) namedFilter.addEventListener('change', applyFilters);
            applyFilters(); 

            document.querySelectorAll('.cb-select-group').forEach(function(selectAll) {
                selectAll.addEventListener('change', function(e) {
                    var table = e.target.closest('table');
                    var checkboxes = table.querySelectorAll('tbody input[type="checkbox"]:not(:disabled)');
                    checkboxes.forEach(function(cb) {
                        cb.checked = e.target.checked;
                    });
                });
            });
        })();
    </script>
    <?php
}
