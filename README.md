# Simple Staging

[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-pink?logo=github)](https://github.com/sponsors/phalox)

Create and manage a staging copy of your WordPress site in a subdirectory or subdomain — no external services, no subscriptions.

<img width="865" height="638" alt="image" src="https://github.com/user-attachments/assets/262a2ebd-544c-4595-8a89-49c899c92c2e" />


## Features

- Clones the live database into a staging-specific table prefix (but in the same database for higher performance)
- Copies all WordPress files into a subdirectory of your choosing
- Works with subdomains (staging.website.be)
- Rewrites URLs, paths, and configuration so the staging site works out of the box
- Runs in small batches so it won't time out on large sites
- One-click deletion removes all staging files and database tables
- Zero external dependencies — pure PHP/WordPress APIs
- Blazing fast on shared hosting

## Requirements

- WordPress 5.6 or later
- PHP 7.4 or later
- Write permission on the WordPress root directory

## Installation

1. Upload the `wp-simple-staging` folder to `wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Navigate to **Tools → Simple Staging**.

## Usage

The clone wizard runs three sequential steps:

| Step | What happens |
|------|--------------|
| **Tables** | Each live table is copied to a new table with the staging prefix. |
| **Files** | All WordPress files are copied to the staging subdirectory (excluded dirs are skipped — see below). |
| **Configure** | `wp-config.php`, `.htaccess`, and database rows are rewritten for the staging URL and table prefix. |

Once complete, your staging site is available at `https://your-site.com/staging/` (or the subdirectory you chose), or if your hosting allows it `https://staging.your-site.com/`

## Excluded directories

The following directories are never copied into staging:

| Path | Reason |
|------|--------|
| `.git`, `.svn` | Version-control metadata |
| `node_modules` | Build-time dependencies |
| `wp-content/cache` | Ephemeral cache files |
| `wp-content/upgrade` | WordPress core upgrade scratch space |
| `wp-content/wflogs` | Wordfence log files |
| `wp-content/ai1wm-backups` | All-in-One WP Migration backups |
| `wp-content/updraft` | UpdraftPlus backups |
| `wp-content/backup-guard` | BackupGuard backups |
| `wp-content/wpstg` | WP Staging Pro data |
| `wp-content/backups` | Generic backup directories |

The staging directory itself is also always skipped, so re-cloning never copies a previous staging clone into the new one.

## Deleting the staging site

Go to **Tools → Simple Staging** and click **Delete Staging**. This removes:

- All files inside the staging subdirectory
- All database tables with the staging prefix

## Uninstalling the plugin

Deleting the plugin via **Plugins → Delete** triggers `uninstall.php`, which removes the `smsng_clone` and `smsng_settings` options from `wp_options`.

## Tested

This plugin was tested on a shared hosting website:
* WP version 6.9.4 with >15 active plugins
* 100+ tables
* 20k+ files

Creating the staging takes less than half a minute

## License

[GNU General Public License v2.0 or later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
