<?php
namespace SimpleStaging;

class Delete {
    private \wpdb  $db;
    private array  $state;

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->state = (array) get_option(SMSNG_STATE, []);
    }

    public function run(): array {
        $prefix     = $this->state['prefix']      ?? '';
        $stagingDir = $this->state['staging_dir'] ?? '';

        $errors = [];

        if ($prefix) {
            $errors = array_merge($errors, $this->dropTables($prefix));
        }

        if ($stagingDir && is_dir($stagingDir)) {
            $this->deleteDir($stagingDir);
        }

        delete_option(SMSNG_STATE);

        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Deleted with warnings: ' . implode('; ', $errors)];
        }

        return ['success' => true, 'message' => 'Staging site deleted.'];
    }

    private function dropTables(string $prefix): array {
        $errors = [];
        $like   = $this->db->esc_like($prefix) . '%';
        $tables = $this->db->get_col(
            $this->db->prepare('SHOW TABLES LIKE %s', $like)
        );

        foreach ($tables as $table) {
            if ($this->db->query("DROP TABLE IF EXISTS `{$table}`") === false) {
                $errors[] = "Could not drop `{$table}`";
            }
        }

        return $errors;
    }

    private function deleteDir(string $dir): void {
        $realDir    = realpath($dir);
        $realBase   = realpath(rtrim(ABSPATH, '/\\'));
        $realParent = $realBase ? realpath(dirname($realBase)) : false;

        if ($realDir === false || $realBase === false || $realParent === false) {
            return;
        }

        // Refuse to delete ABSPATH itself, any parent of ABSPATH,
        // or anything outside the parent directory of ABSPATH.
        if ($realDir === $realBase) {
            return;
        }
        if (strpos($realBase, $realDir . '/') === 0) {
            return; // $realDir is a parent of ABSPATH
        }
        if (strpos($realDir, $realParent . '/') !== 0) {
            return; // outside the shared parent
        }

        $items = @scandir($dir);
        if (!$items) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
