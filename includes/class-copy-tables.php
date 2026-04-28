<?php
namespace SimpleStaging;

class CopyTables extends Job {
    private string $srcPrefix;
    private string $dstPrefix;

    public function __construct() {
        parent::__construct();
        $this->srcPrefix = $this->db->prefix;
        $this->dstPrefix = $this->state['prefix'] ?? 'stg_';
    }

    public function run(): array {
        if (!isset($this->state['tables_pending'])) {
            $this->buildTableList();
        }

        $pending =& $this->state['tables_pending'];
        $total   = $this->state['tables_total'] ?? count($pending);
        $done    = $total - count($pending);

        while (!empty($pending)) {
            if ($this->isOverThreshold()) {
                $this->saveState();
                return $this->result(false,
                    sprintf('Copying tables… (%d / %d)', $done, $total),
                    (int) (($done / max($total, 1)) * 100)
                );
            }

            $table = array_shift($pending);
            $this->copyTable($table);
            $done++;
            $this->saveState();
        }

        unset($this->state['tables_pending'], $this->state['tables_total']);
        $this->saveState();

        return $this->result(true, sprintf('All %d tables copied.', $done));
    }

    private function buildTableList(): void {
        $like   = $this->db->esc_like($this->srcPrefix) . '%';
        $tables = $this->db->get_col(
            $this->db->prepare('SHOW TABLES LIKE %s', $like)
        );
        $this->state['tables_pending'] = $tables;
        $this->state['tables_total']   = count($tables);
        $this->saveState();
    }

    private function copyTable(string $src): void {
        $suffix = substr($src, strlen($this->srcPrefix));
        $dst    = $this->dstPrefix . $suffix;

        $this->db->query("DROP TABLE IF EXISTS `{$dst}`");
        $this->db->query("CREATE TABLE `{$dst}` LIKE `{$src}`");

        $offset = 0;
        $batch  = 1000;
        do {
            $rows = (int) $this->db->query(
                $this->db->prepare(
                    "INSERT INTO `{$dst}` SELECT * FROM `{$src}` LIMIT %d OFFSET %d",
                    $batch,
                    $offset
                )
            );
            $offset += $rows;
        } while ($rows === $batch);
    }
}
