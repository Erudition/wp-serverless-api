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
- **Customizable Output**: Rename JSON keys for each endpoint.
- **Granular Field Filtering**: Exclude specific fields (e.g., `guid`, `_links`) from the final output. The field list automatically updates based on selected paths.
- **Accessibility Verification**: Automatically identifies and flags endpoints that are not publicly accessible.
- **Admin UI**: Filterable path list with item counts and direct preview links.

## CHANGELOG

### 0.5.1

- Improved Friendly Name discovery: Now fetches and displays names for Taxonomies and core listing endpoints (`types`, `taxonomies`).
- Fixed "Reset to Defaults" behavior to correctly restore all checkboxes and clear discovery cache.
- Added `wp/v2/navigation` and `wp/v2/blocks` to default exclusions.
- Redirect after reset to ensure UI state is refreshed correctly.

### 0.5.0

### 0.4.0

- Added a comprehensive Admin Settings page.
- Added "Output Path" customization to rename JSON keys.
- Added field-level exclusions with visual nested indentation.
- Added "Index Now" and "Reset to Defaults" buttons.
- Improved filtering: Only considers GET endpoints with no required arguments.
- Added "Public Only" and "Named Only" UI filters for discovered paths.
- Display live item counts in the discovery table.

### 0.3.0

- Added dynamic discovery of all public REST API collection routes.
- Added support for Custom Post Types (CPT) and plugin-provided endpoints (Pods, Elementor, etc.) without manual configuration.
- Switched to WordPress HTTP API (`wp_remote_get`) for better reliability and access checking.
- Offloaded indexing to a background WP-Cron task for non-blocking post saves.

### 0.2.1

- Removed environment determination to generate db.json even in local environment [#2](https://github.com/getshifter/wp-serverless-api/pull/2)

### 0.2.0

- [BREAKING CHANGE] Change save path from `/wp-content/uploads/wp-sls-api/db.json` to `/wp-content/wp-sls-api/db.json`
