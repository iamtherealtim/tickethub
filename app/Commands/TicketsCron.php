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

        $actions = (new AutomationEngine())->run();
        if (GraphMail::configured()) {
            $actions = array_merge($actions, GraphMail::poll());
        }
        if ($pdq = \App\Libraries\PdqConnect::maybeAutoSync()) {
            $actions[] = $pdq;
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
