# wp-serverless-api

Explore WordPress data via the WP REST API as a JSON file for static WordPress Hosting on [Shifter](https://getshifter.io).

This plugin dynamically discovers all publicly available REST API collections (posts, pages, media, custom post types, etc.) and compiles them into a single JSON file.

1. Install as a WordPress Plugin
2. Activate and save or create a new post or page
3. Create a new static Artifact on Shifter
4. Visit your new WP Serverless API endpoint at `example.com/wp-content/wp-sls-api/db.json`

## CHANGELOG

### 0.3.0

- Added dynamic discovery of all public REST API collection routes.
- Added support for Custom Post Types (CPT) and plugin-provided endpoints (Pods, Elementor, etc.) without manual configuration.
- Switched to WordPress HTTP API (`wp_remote_get`) for better reliability and access checking.

### 0.2.1

- Removed environment determination to generate db.json even in local environment [#2](https://github.com/getshifter/wp-serverless-api/pull/2)

### 0.2.0

- [BREAKING CHANGE] Change save path from `/wp-content/uploads/wp-sls-api/db.json` to `/wp-content/wp-sls-api/db.json`
