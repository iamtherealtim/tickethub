<?php

namespace App\Commands;

use App\Libraries\Webhooks;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Retries webhook deliveries whose backoff has elapsed.
 *
 *   php spark tickethub:webhooks
 *
 * Schedule it alongside tickets:cron (every 1-5 minutes). tickets:cron can
 * also call \App\Libraries\Webhooks::retryDue() directly.
 */
class WebhooksRetry extends BaseCommand
{
    protected $group       = 'TicketHub';
    protected $name        = 'tickethub:webhooks';
    protected $description = 'Retries failed outbound webhook deliveries that are due (backoff 1m, 5m, 30m, 2h, 12h).';
    protected $usage       = 'tickethub:webhooks';

    public function run(array $params)
    {
        helper('tickethub');
        $n = Webhooks::retryDue();
        CLI::write($n === 0 ? 'No webhook deliveries due.' : $n . ' delivery attempt(s) made.', 'green');
    }
}
