<?php
namespace SimpleStaging;

class CopyFiles extends Job {
    private string $srcDir;
    private string $dstDir;
    private string $listFile;
    private array  $createdDirs = [];

    private const EXCLUDED_DIRS = [
        '.git', '.svn', 'node_modules',
        'wp-content/cache', 'wp-content/upgrade', 'wp-content/wflogs',
        'wp-content/ai1wm-backups', 'wp-content/updraft', 'wp-content/backup-guard',
        'wp-content/wpstg', // WP Staging Pro data — intentionally excluded
        'wp-content/backups',
    ];

    public function __construct() {
        parent::__construct();
        $this->srcDir   = rtrim(ABSPATH, '/\\');
        $this->dstDir   = rtrim($this->state['staging_dir'] ?? ($this->srcDir . '/staging'), '/\\');
        $this->listFile = get_temp_dir() . 'smsng_files.txt';
    }

    public function run(): array {
        if (!isset($this->state['files_total'])) {
            return $this->buildFileList();
        }
        return $this->copyBatch();
    }

    private function buildFileList(): array {
        if (!wp_mkdir_p($this->dstDir)) {
            return $this->error('Cannot create staging directory: ' . $this->dstDir);
        }

        $handle = @fopen($this->listFile, 'w');
        if (!$handle) {
            return $this->error('Cannot write file list to temp dir.');
        }

        $count = 0;

        $this->scanDir($this->srcDir, $handle, $count);
        fclose($handle);

        $this->state['files_total']  = $count;
        $this->state['files_done']   = 0;
        $this->state['files_offset'] = 0;
        $this->saveState();

        return $this->result(false, sprintf('Found %d files. Starting copy…', $count), 0);
    }

    private function scanDir(string $dir, $handle, int &$count): void {
        $items = @scandir($dir);
        if (!$items) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            $rel  = ltrim(str_replace($this->srcDir, '', $path), '/\\');

            if (is_dir($path)) {
                if (rtrim($path, '/\\') === $this->dstDir) {
                    continue;
                }
                foreach (self::EXCLUDED_DIRS as $excl) {
                    if ($rel === $excl || strpos($rel, $excl . '/') === 0) {
                        continue 2;
                    }
                }
                $this->scanDir($path, $handle, $count);
            } elseif (is_file($path)) {
                fwrite($handle, $path . "\n");
                $count++;
            }
        }
    }

    private function copyBatch(): array {
        $total  = (int) ($this->state['files_total'] ?? 0);
        $done   = (int) ($this->state['files_done']   ?? 0);
        $offset = (int) ($this->state['files_offset'] ?? 0);

        $handle = @fopen($this->listFile, 'r');
        if (!$handle) {
            return $this->error('Cannot open file list for reading.');
        }

        // Set umask once so copy() creates files at 0644 and wp_mkdir_p() creates
        // dirs at 0755 automatically — no per-file or per-dir chmod needed.
        $prevUmask = umask(0022);
        fseek($handle, $offset);

        while (!feof($handle)) {
            if ($this->isOverThreshold()) {
                $this->state['files_done']   = $done;
                $this->state['files_offset'] = ftell($handle);
                fclose($handle);
                umask($prevUmask);
                $this->saveState();
                $pct = $total > 0 ? (int) (($done / $total) * 100) : 0;
                return $this->result(false, sprintf('Copying files… (%d / %d)', $done, $total), $pct);
            }

            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $src = rtrim($line, "\r\n");
            if (empty($src)) {
                $done++;
                continue;
            }

            $rel = ltrim(str_replace($this->srcDir, '', $src), '/\\');
            $dst = $this->dstDir . '/' . $rel;

            $dstParent = dirname($dst);
            if (!isset($this->createdDirs[$dstParent])) {
                if (!wp_mkdir_p($dstParent)) {
                    $done++;
                    continue;
                }
                $this->createdDirs[$dstParent] = true;
            }

            @copy($src, $dst);
            $done++;
        }

        fclose($handle);
        umask($prevUmask);
        @unlink($this->listFile);

        unset(
            $this->state['files_total'],
            $this->state['files_done'],
            $this->state['files_offset']
        );
        $this->saveState();

        return $this->result(true, sprintf('All %d files copied.', $done));
    }
}
