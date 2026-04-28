<?php
namespace SimpleStaging;

class Admin {
    public static function boot(): void {
        $self = new self();
        add_action('admin_menu',            [$self, 'addMenu']);
        add_action('admin_enqueue_scripts', [$self, 'enqueueAssets']);
        add_action('wp_ajax_smsng_create',  [$self, 'ajaxCreate']);
        add_action('wp_ajax_smsng_step',    [$self, 'ajaxStep']);
        add_action('wp_ajax_smsng_delete',  [$self, 'ajaxDelete']);
    }

    public function addMenu(): void {
        add_menu_page(
            __('Simple Staging', 'simple-staging'),
            __('Staging', 'simple-staging'),
            'manage_options',
            'simple-staging',
            [$this, 'renderPage'],
            'dashicons-hammer',
            75
        );
    }

    public function enqueueAssets(string $hook): void {
        if ($hook !== 'toplevel_page_simple-staging') {
            return;
        }
        wp_enqueue_script(
            'smsng-admin',
            SMSNG_URL . 'assets/admin.js',
            ['jquery'],
            SMSNG_VERSION,
            true
        );
        wp_localize_script('smsng-admin', 'smsngData', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce(SMSNG_NONCE),
            'state'      => (array) get_option(SMSNG_STATE, []),
            'stagingUrl' => $this->deriveStagingUrl(),
        ]);
    }

    public function renderPage(): void {
        $state    = (array) get_option(SMSNG_STATE, []);
        $status   = $state['status'] ?? 'idle';
        $settings = (array) get_option(SMSNG_SETTINGS, []);

        $savedUrl              = $settings['staging_url']              ?? $this->deriveStagingUrl();
        $savedDir              = $settings['staging_dir']              ?? $this->deriveStagingDir($savedUrl);
        $savedPlugins          = implode("\n", (array) ($settings['disabled_plugins'] ?? []));
        $savedRestrict         = !empty($settings['restrict_access']);
        $savedClearElementor   = !empty($settings['clear_elementor_license']);
        ?>
        <div class="wrap" id="smsng-wrap">
            <h1><?php esc_html_e('Simple Staging', 'simple-staging'); ?></h1>

            <div id="smsng-panel-idle" style="<?php echo $status !== 'idle' ? 'display:none' : ''; ?>">
                <p><?php esc_html_e('Create a staging copy of your site on a subdomain, using the same database with a separate table prefix.', 'simple-staging'); ?></p>
                <p>
                    <label for="smsng-staging-url"><strong><?php esc_html_e('Staging URL:', 'simple-staging'); ?></strong></label><br>
                    <input type="text" id="smsng-staging-url" value="<?php echo esc_attr($savedUrl); ?>" style="width:100%;max-width:500px;margin-top:4px">
                </p>
                <p>
                    <label for="smsng-staging-dir"><strong><?php esc_html_e('Staging directory:', 'simple-staging'); ?></strong><br>
                    <em style="font-weight:normal;font-size:12px"><?php esc_html_e('Absolute server path where staging files will be placed.', 'simple-staging'); ?></em></label><br>
                    <input type="text" id="smsng-staging-dir" value="<?php echo esc_attr($savedDir); ?>" style="width:100%;max-width:600px;margin-top:4px;font-family:monospace;font-size:12px">
                </p>
                <div style="margin-top:16px">
                    <label for="smsng-disabled-plugins">
                        <strong><?php esc_html_e('Plugins to disable on staging', 'simple-staging'); ?></strong><br>
                        <em style="font-weight:normal;font-size:12px"><?php esc_html_e('One per line, e.g. woocommerce/woocommerce.php', 'simple-staging'); ?></em>
                    </label><br>
                    <textarea id="smsng-disabled-plugins" style="width:100%;max-width:600px;height:80px;margin-top:4px;font-family:monospace;font-size:12px"><?php echo esc_textarea($savedPlugins); ?></textarea>
                </div>
                <div style="margin-top:8px">
                    <label>
                        <input type="checkbox" id="smsng-restrict-access"<?php checked($savedRestrict); ?>>
                        <?php esc_html_e('Restrict staging site to administrators only', 'simple-staging'); ?>
                    </label>
                </div>
                <div style="margin-top:8px;margin-bottom:16px">
                    <label>
                        <input type="checkbox" id="smsng-clear-elementor-license"<?php checked($savedClearElementor); ?>>
                        <?php esc_html_e('Clear Elementor Pro license on staging', 'simple-staging'); ?>
                    </label>
                </div>
                <button id="smsng-btn-create" class="button button-primary button-large">
                    <?php esc_html_e('Create Staging Site', 'simple-staging'); ?>
                </button>
            </div>

            <div id="smsng-panel-running" style="<?php echo $status !== 'running' ? 'display:none' : ''; ?>">
                <p id="smsng-status-msg"><?php esc_html_e('Processing…', 'simple-staging'); ?></p>
                <div style="background:#e0e0e0;border-radius:3px;height:22px;width:100%;max-width:600px;overflow:hidden">
                    <div id="smsng-progress-fill" style="background:#0073aa;height:100%;width:0%;transition:width .3s ease"></div>
                </div>
                <p><em><?php esc_html_e('Please keep this page open until the process completes.', 'simple-staging'); ?></em></p>
            </div>

            <div id="smsng-panel-done" style="<?php echo $status !== 'done' ? 'display:none' : ''; ?>">
                <div class="notice notice-success inline" style="margin:0 0 16px">
                    <p>
                        <?php esc_html_e('Staging site is ready.', 'simple-staging'); ?>
                        <a id="smsng-staging-link" href="<?php echo esc_url($state['staging_url'] ?? $this->deriveStagingUrl()); ?>" target="_blank">
                            <?php echo esc_html($state['staging_url'] ?? $this->deriveStagingUrl()); ?>
                        </a>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Files copied to:', 'simple-staging'); ?></strong>
                        <code><?php echo esc_html($state['staging_dir'] ?? $this->deriveStagingDir($state['staging_url'] ?? $this->deriveStagingUrl())); ?></code>
                    </p>
                </div>
                <a href="<?php echo esc_url(($state['staging_url'] ?? $this->deriveStagingUrl()) . '/wp-admin/'); ?>" target="_blank" class="button button-primary" style="margin-right:8px">
                    <?php esc_html_e('Go to Staging Admin', 'simple-staging'); ?>
                </a>
                <button id="smsng-btn-delete" class="button button-secondary">
                    <?php esc_html_e('Delete Staging Site', 'simple-staging'); ?>
                </button>
            </div>

            <div id="smsng-panel-error" style="display:none">
                <div class="notice notice-error inline" style="margin:0 0 16px">
                    <p id="smsng-error-msg"></p>
                </div>
                <button id="smsng-btn-reset" class="button button-secondary">
                    <?php esc_html_e('Reset', 'simple-staging'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    public function ajaxCreate(): void {
        check_ajax_referer(SMSNG_NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $stagingUrl = esc_url_raw(wp_unslash($_POST['staging_url'] ?? ''));
        $stagingDir = sanitize_text_field(wp_unslash($_POST['staging_dir'] ?? ''));

        if (empty($stagingUrl) || empty($stagingDir)) {
            wp_send_json_error('Staging URL and directory are required.');
            return;
        }

        // Protect against accidentally targeting the live site directory
        if (rtrim($stagingDir, '/\\') === rtrim(ABSPATH, '/\\')) {
            wp_send_json_error('Staging directory cannot be the same as the live site directory.');
            return;
        }

        if (is_dir($stagingDir) && $this->isDirNonEmpty($stagingDir)) {
            wp_send_json_error('Staging directory already exists and is not empty. Delete the existing staging site first.');
            return;
        }

        $prefix = 'stg' . substr(md5(home_url()), 0, 4) . '_';

        // Ensure prefix doesn't clash with live prefix
        if ($prefix === $this->db()->prefix) {
            $prefix = 'staging_';
        }

        $rawPlugins            = sanitize_textarea_field(wp_unslash($_POST['disabled_plugins'] ?? ''));
        $disabledPlugins       = array_values(array_filter(array_map('trim', explode("\n", $rawPlugins))));
        $restrictAccess        = isset($_POST['restrict_access'])        && (int) $_POST['restrict_access']        === 1;
        $clearElementorLicense = isset($_POST['clear_elementor_license']) && (int) $_POST['clear_elementor_license'] === 1;

        update_option(SMSNG_SETTINGS, [
            'staging_url'             => $stagingUrl,
            'staging_dir'             => $stagingDir,
            'disabled_plugins'        => $disabledPlugins,
            'restrict_access'         => $restrictAccess,
            'clear_elementor_license' => $clearElementorLicense,
        ], false);

        update_option(SMSNG_STATE, [
            'status'                  => 'running',
            'current_step'            => 'tables',
            'prefix'                  => $prefix,
            'staging_dir'             => $stagingDir,
            'staging_url'             => $stagingUrl,
            'created_at'              => time(),
            'disabled_plugins'        => $disabledPlugins,
            'restrict_access'         => $restrictAccess,
            'clear_elementor_license' => $clearElementorLicense,
        ], false);

        wp_send_json_success(['message' => 'Initialized. Starting…']);
    }

    public function ajaxStep(): void {
        check_ajax_referer(SMSNG_NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $state = (array) get_option(SMSNG_STATE, []);

        if (empty($state) || ($state['status'] ?? '') !== 'running') {
            wp_send_json_error('No active staging job.');
            return;
        }

        $step   = $state['current_step'] ?? 'tables';
        $result = [];

        switch ($step) {
            case 'tables':
                $result = (new CopyTables())->run();
                if (empty($result['error']) && !empty($result['finished'])) {
                    $this->advanceStep('files');
                }
                break;

            case 'files':
                $result = (new CopyFiles())->run();
                if (empty($result['error']) && !empty($result['finished'])) {
                    $this->advanceStep('configure');
                }
                break;

            case 'configure':
                $result = (new Configure())->run();
                if (empty($result['error']) && !empty($result['finished'])) {
                    $this->advanceStep('done');
                    $fresh = (array) get_option(SMSNG_STATE, []);
                    $result['staging_url'] = $fresh['staging_url'] ?? '';
                }
                break;

            default:
                wp_send_json_error('Unknown step: ' . esc_html($step));
                return;
        }

        if (!empty($result['error'])) {
            wp_send_json_error($result['message']);
            return;
        }

        wp_send_json_success($result);
    }

    public function ajaxDelete(): void {
        check_ajax_referer(SMSNG_NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $result = (new Delete())->run();

        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    private function advanceStep(string $step): void {
        $state = (array) get_option(SMSNG_STATE, []);
        $state['current_step'] = $step;
        if ($step === 'done') {
            $state['status'] = 'done';
        }
        update_option(SMSNG_STATE, $state, false);
    }

    /**
     * Derives the staging URL by replacing (or prepending) the subdomain
     * with "staging". Examples:
     *   https://website.be      → https://staging.website.be
     *   https://wp.website.be   → https://staging.website.be
     */
    private function deriveStagingUrl(): string {
        $parsed = parse_url(home_url());
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? '';

        $parts = explode('.', $host);
        if (count($parts) > 2) {
            $parts[0] = 'staging'; // replace existing subdomain
        } else {
            array_unshift($parts, 'staging'); // prepend subdomain
        }

        return $scheme . '://' . implode('.', $parts);
    }

    /**
     * Derives the physical staging directory as a sibling folder next to
     * the WordPress root, named after the staging hostname.
     * e.g. /var/www/website.be/ → /var/www/staging.website.be/
     */
    private function deriveStagingDir(string $stagingUrl): string {
        $host   = parse_url($stagingUrl, PHP_URL_HOST) ?? 'staging';
        $parent = dirname(rtrim(ABSPATH, '/\\'));
        return $parent . '/' . $host;
    }

    private function isDirNonEmpty(string $dir): bool {
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        return count(array_diff($items, ['.', '..'])) > 0;
    }

    private function db(): \wpdb {
        global $wpdb;
        return $wpdb;
    }
}
