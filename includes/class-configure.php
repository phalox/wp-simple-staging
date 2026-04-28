<?php
namespace SimpleStaging;

class Configure extends Job {
    public function run(): array {
        $prefix     = $this->state['prefix']      ?? '';
        $stagingDir = $this->state['staging_dir'] ?? '';
        $stagingUrl = rtrim($this->state['staging_url'] ?? '', '/');

        if (!$prefix || !$stagingDir || !$stagingUrl) {
            return $this->error('Configure: missing required state values.');
        }

        // 1. Update siteurl and home in staging options table
        $optTable = "`{$prefix}options`";
        $this->db->query(
            $this->db->prepare(
                "UPDATE {$optTable} SET option_value = %s WHERE option_name = 'siteurl'",
                $stagingUrl
            )
        );
        $this->db->query(
            $this->db->prepare(
                "UPDATE {$optTable} SET option_value = %s WHERE option_name = 'home'",
                $stagingUrl
            )
        );

        // 2. Write staging wp-config.php
        $cfgResult = $this->writeWpConfig($stagingDir, $prefix, $stagingUrl);
        if ($cfgResult !== true) {
            return $this->error($cfgResult);
        }

        // 3. Rename prefixed option_names: e.g. wp_user_roles → stgXXXX_user_roles
        $this->updatePrefixedRows($prefix . 'options', 'option_name', $this->db->prefix, $prefix);

        // 4. Rename prefixed usermeta keys: e.g. wp_capabilities → stgXXXX_capabilities
        $this->updatePrefixedRows($prefix . 'usermeta', 'meta_key', $this->db->prefix, $prefix);

        // 5. Write staging .htaccess
        $this->writeHtaccess($stagingDir);

        // 6. Clear Elementor Pro license from staging options
        if (!empty($this->state['clear_elementor_license'])) {
            $this->db->query(
                "DELETE FROM `{$prefix}options`
                 WHERE option_name IN (
                     '_elementor_pro_license_v2_data',
                     '_elementor_pro_license_v2_data_fallback'
                 )"
            );
        }

        // 7. Write staging mu-plugin (noindex, admin bar color, plugin disable, access restriction)
        $this->writeStagingMuPlugin($stagingDir);

        $this->state['status'] = 'done';
        $this->saveState();

        return $this->result(true, 'Configuration complete.', 100);
    }

    /** @return true|string True on success, error message on failure. */
    private function writeWpConfig(string $stagingDir, string $prefix, string $stagingUrl) {
        $srcConfig = ABSPATH . 'wp-config.php';
        if (!file_exists($srcConfig)) {
            $srcConfig = dirname(ABSPATH) . '/wp-config.php';
        }
        if (!file_exists($srcConfig)) {
            return 'Cannot find wp-config.php.';
        }

        $content = file_get_contents($srcConfig);
        if ($content === false) {
            return 'Cannot read wp-config.php.';
        }

        // Replace table prefix
        $content = preg_replace(
            '/\$table_prefix\s*=\s*[\'"][^\'"]*[\'"];/',
            "\$table_prefix = '" . $prefix . "';",
            $content
        );

        // Replace or inject WP_HOME and WP_SITEURL
        $content = $this->replaceOrAddDefine($content, 'WP_HOME',    $stagingUrl);
        $content = $this->replaceOrAddDefine($content, 'WP_SITEURL', $stagingUrl);

        $dst = rtrim($stagingDir, '/\\') . '/wp-config.php';
        if (file_put_contents($dst, $content) === false) {
            return 'Cannot write staging wp-config.php.';
        }

        return true;
    }

    private function replaceOrAddDefine(string $content, string $name, string $value): string {
        $pattern     = "/define\s*\(\s*['\"]" . preg_quote($name, '/') . "['\"],\s*['\"][^'\"]*['\"]\s*\);/";
        $replacement = "define('" . $name . "', '" . addslashes($value) . "');";

        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $replacement, $content);
        }

        // Inject after opening <?php tag
        return preg_replace('/(<\?php\s)/', '$1' . $replacement . "\n", $content, 1);
    }

    private function updatePrefixedRows(string $table, string $column, string $livePrefix, string $stagingPrefix): void {
        if ($livePrefix === $stagingPrefix) {
            return;
        }
        $like = $this->db->esc_like($livePrefix) . '%';
        $sql  = "UPDATE `{$table}` SET `{$column}` = CONCAT(%s, SUBSTRING(`{$column}`, %d)) WHERE `{$column}` LIKE %s";
        $this->db->query(
            $this->db->prepare($sql, $stagingPrefix, strlen($livePrefix) + 1, $like)
        );
    }

    private function writeStagingMuPlugin(string $stagingDir): void {
        $muDir = rtrim($stagingDir, '/\\') . '/wp-content/mu-plugins';
        if (!wp_mkdir_p($muDir)) {
            return;
        }
        @chmod($muDir, 0755);

        $disabledPlugins = array_values((array) ($this->state['disabled_plugins'] ?? []));
        $restrictAccess  = !empty($this->state['restrict_access']);

        // Build inline PHP array literal
        $items    = array_map(fn($v) => "'" . addslashes($v) . "'", $disabledPlugins);
        $phpArray = '[' . implode(', ', $items) . ']';

        $restrictBlock = $restrictAccess
            ? "\n// Restrict access to administrators only\n"
                . "add_action('template_redirect', function () {\n"
                . "    if (is_user_logged_in() && current_user_can('manage_options')) { return; }\n"
                . "    if (!is_user_logged_in()) {\n"
                . "        wp_redirect(wp_login_url(\$_SERVER['REQUEST_URI'] ?? '/'));\n"
                . "        exit;\n"
                . "    }\n"
                . "    wp_die('This staging site is restricted to administrators.');\n"
                . "});\n"
            : '';

        $content = "<?php\n"
            . "/**\n"
            . " * Simple Staging Mode — auto-generated. Do not edit.\n"
            . " */\n"
            . "defined('ABSPATH') || exit;\n\n"
            . "// Block search engine indexing\n"
            . "add_filter('pre_option_blog_public', fn() => '0');\n\n"
            . "// Admin bar staging indicator\n"
            . "add_action('wp_head',    'smsng_staging_bar_css');\n"
            . "add_action('admin_head', 'smsng_staging_bar_css');\n"
            . "function smsng_staging_bar_css(): void {\n"
            . "    echo '<style>#wpadminbar{background:#b45309!important}"
            .          "#wp-admin-bar-site-name>.ab-item::before{content:\"[STAGING] \";}</style>';\n"
            . "}\n\n"
            . "// Disable selected plugins\n"
            . "add_filter('option_active_plugins', function (\$p) {\n"
            . "    return array_values(array_diff((array) \$p, {$phpArray}));\n"
            . "});\n"
            . $restrictBlock;

        @file_put_contents($muDir . '/simple-staging-mode.php', $content);
        @chmod($muDir . '/simple-staging-mode.php', 0644);
    }

    private function writeHtaccess(string $stagingDir): void {
        // The staging site is a root installation on its own subdomain,
        // so RewriteBase is / just like a standard WordPress install.
        $htaccess = "# BEGIN WordPress\n"
            . "<IfModule mod_rewrite.c>\n"
            . "RewriteEngine On\n"
            . "RewriteBase /\n"
            . "RewriteRule ^index\\.php$ - [L]\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule . /index.php [L]\n"
            . "</IfModule>\n"
            . "# END WordPress\n";

        @file_put_contents(rtrim($stagingDir, '/\\') . '/.htaccess', $htaccess);
    }
}
