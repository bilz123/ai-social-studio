<?php

namespace App\Console\Commands;

use App\Support\Health\HealthReporter;
use Illuminate\Console\Command;

class HealthCheckCommand extends Command
{
    protected $signature = 'health:check {--json : Print the report as JSON}';

    protected $description = 'Check that the database and Redis are reachable (non-zero exit when degraded)';

    public function handle(HealthReporter $reporter): int
    {
        $report = $reporter->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray()));
        } else {
            foreach ($report->checks as $name => $result) {
                $this->line(sprintf('%-10s %s', $name, strtoupper($result['status'])));
            }
        }

        return $report->healthy() ? self::SUCCESS : self::FAILURE;
    }
}
