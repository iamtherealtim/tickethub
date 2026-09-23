<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * First-run bootstrap for a real deployment: creates (or, with --force, resets)
 * the first Administrator without ever touching the demo seeder.
 *
 *   php spark tickethub:setup --email you@company.com --name "Your Name"
 *
 * The password is printed exactly once and must be changed at first sign-in.
 */
class Setup extends BaseCommand
{
    protected $group       = 'TicketHub';
    protected $name        = 'tickethub:setup';
    protected $description = 'Creates the first Administrator account for a fresh installation.';
    protected $usage       = 'tickethub:setup --email <email> [--name <name>] [--password <password>] [--url <address>] [--force]';
    protected $options     = [
        '--url'      => 'Site address people will use, e.g. https://helpdesk.example.com/ (same as tickethub:url). Asked for interactively when still localhost.',
        '--email'    => 'Email address the administrator signs in with (required).',
        '--name'     => 'Display name. Defaults to the part of the email before @.',
        '--password' => 'Initial password (min 12 chars). Omit to have a random one generated and printed once.',
        '--force'    => 'Proceed even if an Administrator already exists (updates the account with that email, or creates it).',
    ];

    public function run(array $params)
    {
        $db = db_connect();

        if (! $db->tableExists('users')) {
            CLI::error('The users table does not exist. Run "php spark migrate" first.');

            return EXIT_ERROR;
        }

        $email = strtolower(trim((string) ($params['email'] ?? CLI::getOption('email') ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            CLI::error('A valid --email is required.');
            CLI::write('Usage: ' . $this->usage, 'yellow');

            return EXIT_USER_INPUT;
        }

        $force = array_key_exists('force', $params) || CLI::getOption('force') !== null;
        $admins = $db->table('users')->where('role', 'Administrator')->where('active', 1)->countAllResults();
        if ($admins > 0 && ! $force) {
            CLI::error('An active Administrator already exists (' . $admins . ' found). Nothing changed.');
            CLI::write('Re-run with --force to create/update the account for ' . $email . ' anyway.', 'yellow');

            return EXIT_ERROR;
        }

        $name = trim((string) ($params['name'] ?? CLI::getOption('name') ?? ''));
        if ($name === '') {
            $name = ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $email)[0]));
        }

        $generated = false;
        $password  = (string) ($params['password'] ?? CLI::getOption('password') ?? '');
        if ($password === '') {
            $password  = $this->randomPassword();
            $generated = true;
        } elseif ($problem = \App\Controllers\BaseController::passwordProblem($password, $email)) {
            CLI::error('Password rejected: ' . $problem);

            return EXIT_USER_INPUT;
        }

        // Site address: links in every email and the SSO callbacks depend on it,
        // so settle it here rather than leave the installer on localhost.
        $url = trim((string) SiteUrl::option('url', $params));
        $docker = getenv('TICKETHUB_DOCKER') === '1';
        if ($url === '' && ! $docker && ENVIRONMENT === 'production' && function_exists('stream_isatty') && stream_isatty(STDIN)
            && \App\Libraries\SiteAddress::isLocalHost(\App\Libraries\SiteAddress::parse(config('App')->baseURL)['host'])) {
            $url = trim(CLI::prompt('Address people will use (e.g. https://helpdesk.example.com/), blank to keep ' . config('App')->baseURL));
        }
        if ($url !== '') {
            if ($docker) {
                CLI::error('--url is not used in Docker: set TICKETHUB_DOMAIN in .env.docker, then docker compose up -d.');

                return EXIT_USER_INPUT;
            }
            if ($this->call('tickethub:url', [$url]) !== EXIT_SUCCESS) {
                return EXIT_USER_INPUT;
            }
        }

        $now  = date('Y-m-d H:i:s');
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $existing = $db->table('users')->where('email', $email)->get()->getRowArray();
        if ($existing) {
            $db->table('users')->where('id', $existing['id'])->update([
                'name' => $name, 'password_hash' => $hash, 'role' => 'Administrator', 'active' => 1,
                'must_change_password' => 1,
                'remember_selector' => null, 'remember_validator' => null, 'remember_expires' => null,
                'updated_at' => $now,
            ]);
            // Kick out any session that was using the old credentials.
            $db->table('users')->where('id', $existing['id'])->set('session_epoch', 'session_epoch + 1', false)->update();
            $verb = 'Updated';
        } else {
            $db->table('users')->insert([
                'name' => $name, 'email' => $email, 'password_hash' => $hash,
                'role' => 'Administrator', 'title' => 'Administrator', 'group_id' => null,
                'color' => 'brand', 'active' => 1, 'must_change_password' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $verb = 'Created';
        }

        CLI::newLine();
        CLI::write($verb . ' Administrator: ' . $name . ' <' . $email . '>', 'green');
        if ($generated) {
            CLI::write('Temporary password (shown once, not stored anywhere else):', 'yellow');
            CLI::write('    ' . $password, 'white');
        } else {
            CLI::write('Password set from --password.', 'yellow');
        }
        CLI::write('They must choose a new password at first sign-in.');

        CLI::newLine();
        CLI::write('Next steps', 'cyan');
        $siteUrl = $url !== '' ? \App\Libraries\SiteAddress::normalise($url) : (string) config('App')->baseURL;
        CLI::write('  1. Sign in at ' . rtrim($siteUrl, '/') . '/login and set your own password.');
        CLI::write('     Admin → Address & HTTPS checks the address and certificate.');
        CLI::write('  2. Admin → Groups: create your support teams, then Admin → Routing: pick the default team.');
        CLI::write('  3. Admin → Email settings: SMTP (needed for invites and password resets).');
        CLI::write('  4. Admin → Single sign-on: optional Microsoft Entra ID; Admin → Integrations: API tokens, PDQ.');
        CLI::write('  5. Cron: */5 * * * * php ' . ROOTPATH . 'spark tickets:cron');
        if (trim((string) config('Encryption')->key) === '') {
            CLI::write('  !  encryption.key is not set — run "php spark key:generate" so stored secrets are encrypted.', 'red');
        }
        CLI::newLine();

        return EXIT_SUCCESS;
    }

    /** 16 characters from an unambiguous alphabet; satisfies the app password rules. */
    private function randomPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789-_!';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
