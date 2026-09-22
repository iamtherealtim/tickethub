<?php

namespace App\Commands;

use App\Libraries\PdqConnect;
use App\Libraries\Settings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class PdqTest extends BaseCommand
{
    protected $group       = 'TicketHub';
    protected $name        = 'pdq:test';
    protected $description = 'Tests the PDQ Connect connection with the saved settings.';

    public function run(array $params)
    {
        helper('tickethub');
        if (! PdqConnect::configured()) {
            CLI::write('PDQ Connect is not enabled or has no API key.', 'yellow');

            return;
        }
        $res = PdqConnect::testConnection();
        CLI::write($res['message'], $res['ok'] ? 'green' : 'red');
        if ($last = Settings::get('pdq_last_sync')) {
            CLI::write('Last sync: ' . $last, 'dark_gray');
        }
        if ($err = Settings::get('pdq_last_error')) {
            CLI::write('Last error: ' . $err, 'red');
        }
        if ($next = Settings::get('pdq_next_page')) {
            CLI::write('Partial run pending — cron resumes from page ' . $next, 'yellow');
        }
    }
}
