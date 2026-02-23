# wp-serverless-api

Explore WordPress data via the WP REST API as a JSON file for static WordPress Hosting on [Shifter](https://getshifter.io).

This plugin dynamically discovers all publicly available REST API collections (posts, pages, media, custom post types, etc.) and compiles them into a single JSON file. It includes a comprehensive tabbed settings page to filter endpoints and fields, customize output keys, and trigger manual indexing.

1. Install as a WordPress Plugin
2. Configure settings at **Settings -> WP Serverless API**
3. Save or create a new post or page (triggers background index)
4. Create a new static Artifact on Shifter
5. Visit your new WP Serverless API endpoint at `example.com/wp-content/wp-sls-api/db.json`

## FEATURES

- **Dynamic Discovery**: Automatically finds all valid REST API collection routes.
- **Asynchronous Processing**: Indexing runs in the background via WP-Cron to prevent UI blocking.
- **Tabbed Interface**: Organized settings for Paths and Fields.
- **Relative URL Support**: Automatically converts absolute staging URLs to relative paths, ensuring data works on production domains.
- **Component-Based Grouping**: Discovered paths and fields are organized by their source component (e.g., `wp v2`, `pods v1`).
- **Customizable Output**: Rename JSON keys for each endpoint.
- **Granular Field Filtering**: Exclude specific fields (e.g., `guid`, `_links`) from the final output. The field list automatically updates based on selected paths.
- **Accessibility Verification**: Automatically identifies and flags endpoints that are not publicly accessible.
- **Admin UI**: Filterable path list with item counts, object field counts, and direct preview links.

## CHANGELOG

### 1.1.0

- Added **Relative URL Support**: Automatically converts all `home_url` matches to relative paths in the JSON output. This fixes the issue where staging URLs would persist in production artifacts.
- Enabled relative URL conversion by default (toggleable in settings).
- Added "View in browser" link to the successful build notice for instant data verification.

### 1.0.0

- Stable release.
- Added "View in browser" link to the database build success notice.
- Refined input path display by stripping namespace prefixes.
- Enhanced preview links to distinguish between lists (items) and objects (fields).
- Finalized UI grouping and tab organization.

### 0.6.2

- UI Refinement: "Input Path" column now strips the namespace prefix already shown in the section header.
- Preview Link Enhancements: Lists show "View N items", Objects show "View N fields".
- Added `media` to the default exclusion list.

### 0.6.1

- UI Refinement: Moved "Build Database Now" button to the bottom and added a top-level "Re-discover" button.
- Updated preview links for private endpoints to show "Not public" in grey while remaining clickable.
- Expanded default exclusion list to include `wp/v2/types` and `wp/v2/taxonomies`.

### 0.6.0

- Organized Paths and Fields into grouped sections by component and version (e.g., `wp v2`, `elementor v1`).
- Enhanced Fields tab to show fields per component, filtered by currently selected paths.

### 0.5.1

- Improved Friendly Name discovery for Taxonomies and core listing endpoints.
- Fixed "Reset to Defaults" behavior to properly clear cache and refresh UI.

### 0.5.0

- Split settings into "Paths" and "Fields" tabs.
- Added `wp/v2/navigation` and `wp/v2/blocks` to default exclusions.

### 0.4.0

- Added comprehensive Admin Settings page with Output Path customization.
- Added field-level exclusions with visual nested indentation.
- Added "Index Now" and "Reset to Defaults" buttons.

### 0.3.0

- Added dynamic discovery of all public REST API collection routes.
- Added support for Custom Post Types (CPT) and plugin-provided endpoints.
- Switched to WordPress HTTP API (`wp_remote_get`) for better reliability and access checking.
- Offloaded indexing to a background WP-Cron task for non-blocking post saves.

### 0.2.1

- Removed environment determination to generate db.json even in local environment [#2](https://github.com/getshifter/wp-serverless-api/pull/2)

### 0.2.0

- [BREAKING CHANGE] Change save path from `/wp-content/uploads/wp-sls-api/db.json` to `/wp-content/wp-sls-api/db.json`
