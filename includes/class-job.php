<?php
namespace SimpleStaging;

abstract class Job {
    protected int   $timeBudget = 20;
    protected \wpdb $db;
    protected array $state;

    private float $startTime;
    private int   $memLimit;

    public function __construct() {
        global $wpdb;
        $this->db        = $wpdb;
        $this->startTime = microtime(true);
        $this->state     = (array) get_option(SMSNG_STATE, []);
        $this->memLimit  = $this->resolveMemoryLimit();

        @set_time_limit(0);
        @ignore_user_abort(true);
    }

    abstract public function run(): array;

    protected function isOverThreshold(): bool {
        return (microtime(true) - $this->startTime) > $this->timeBudget
            || memory_get_usage(true) > $this->memLimit;
    }

    protected function saveState(): void {
        update_option(SMSNG_STATE, $this->state, false);
    }

    protected function result(bool $finished, string $msg, int $pct = -1, array $extra = []): array {
        return array_merge(['finished' => $finished, 'message' => $msg, 'percent' => $pct], $extra);
    }

    protected function error(string $msg): array {
        $this->state['status'] = 'error';
        $this->state['error']  = $msg;
        $this->saveState();
        return ['error' => true, 'message' => $msg];
    }

    private function resolveMemoryLimit(): int {
        $raw = ini_get('memory_limit');
        if ($raw === '' || $raw === '-1') {
            return PHP_INT_MAX;
        }
        return (int) (wp_convert_hr_to_bytes($raw) * 0.75);
    }
}
