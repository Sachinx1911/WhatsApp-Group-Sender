<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Run at start-up (start.bat / composer dev) to continue campaigns interrupted by a restart. */
class RecoverCampaigns extends Command
{
    protected $signature = 'campaigns:recover';

    protected $description = 'Continue campaigns that were interrupted and mark unconfirmed sends for review';

    public function handle(CampaignRunner $runner): int
    {
        // Normally run before the queue starts. If a queue worker is already holding a job
        // (the command was run by hand while start.bat is up), a "processing" row may
        // really be mid-send, so only rows older than the allowance are treated as stuck.
        $queueIsRunning = config('queue.default') === 'database'
            && DB::table(config('queue.connections.database.table', 'jobs'))->where('queue', 'whatsapp')->whereNotNull('reserved_at')->exists();

        $result = $runner->recover($queueIsRunning);

        $this->info("Campaigns checked. Restarted: {$result['restarted']}. Unconfirmed sends marked for review: {$result['unconfirmed']}.");

        return self::SUCCESS;
    }
}
