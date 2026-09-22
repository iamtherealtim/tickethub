<?php

namespace App\Commands;

use App\Libraries\AutomationEngine;
use App\Libraries\GraphMail;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TicketsCron extends BaseCommand
{
    protected $group       = 'TicketHub';
    protected $name        = 'tickets:cron';
    protected $description = 'Runs automations (auto-close, escalation, nudges, SLA warnings) and polls the Graph mailbox if enabled. Schedule every 5-15 minutes.';

    public function run(array $params)
    {
        helper('tickethub');

        // One pass at a time. A slow mailbox poll overlapping the next scheduled
        // run would evaluate the same rules and ingest the same mail twice.
        $lockPath = WRITEPATH . 'cache/tickets-cron.lock';
        if (! is_dir(dirname($lockPath))) {
            @mkdir(dirname($lockPath), 0775, true);
        }
        $lock = fopen($lockPath, 'c');
        if (! $lock) {
            CLI::error('Could not open ' . $lockPath . ' — check writable/cache permissions.');

            return;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            CLI::write('tickets:cron is already running — exiting.', 'yellow');

            return;
        }

        try {
            $actions = (new AutomationEngine())->run();
            if (GraphMail::configured()) {
                $actions = array_merge($actions, GraphMail::poll());
            }
            if ($pdq = \App\Libraries\PdqConnect::maybeAutoSync()) {
                $actions[] = $pdq;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        if (! $actions) {
            CLI::write('Nothing to do.', 'green');

            return;
        }
        foreach ($actions as $a) {
            CLI::write('• ' . $a, 'yellow');
        }
        CLI::write(count($actions) . ' line(s) reported.', 'green');
    }
}
