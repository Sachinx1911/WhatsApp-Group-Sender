<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignRunner;
use Illuminate\Console\Command;

/** Run at start-up (start.bat / composer dev) to continue campaigns interrupted by a restart. */
class RecoverCampaigns extends Command
{
    protected $signature = 'campaigns:recover';

    protected $description = 'Continue campaigns that were interrupted and mark unconfirmed sends for review';

    public function handle(CampaignRunner $runner): int
    {
        $result = $runner->recover();

        $this->info("Campaigns checked. Restarted: {$result['restarted']}. Unconfirmed sends marked for review: {$result['unconfirmed']}.");

        return self::SUCCESS;
    }
}
