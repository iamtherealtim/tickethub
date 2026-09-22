<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

abstract class BaseController extends Controller
{
    /**
     * @var CLIRequest|IncomingRequest
     */
    protected $request;

    protected $helpers = ['tickethub', 'form', 'url'];

    protected \CodeIgniter\Database\BaseConnection $db;
    protected $session;
    protected ?array $me = null;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->db      = db_connect();
        $this->session = session();

        if (! $this->session->get('user_id')) {
            $this->tryRememberedLogin();
        }
        if ($uid = $this->session->get('user_id')) {
            $this->me = $this->db->table('users')->where('id', $uid)->get()->getRowArray();

            // Deactivation and role changes must bite immediately, not at next login.
            if (! $this->me || ! (int) $this->me['active']) {
                $this->clearRememberCookie();
                $this->session->destroy();
                $this->me = null;
            } elseif ($this->session->get('role') !== $this->me['role']) {
                $this->session->set('role', $this->me['role']);
            }
        }
    }

    /** A shared password rule for every place a password can be set. */
    public static function passwordProblem(string $password, string $email = ''): ?string
    {
        if (strlen($password) < 12) {
            return 'Use at least 12 characters.';
        }
        $lower = strtolower($password);
        $local = strtolower(explode('@', $email)[0] ?? '');
        $deny  = ['password', 'passw0rd', 'tickethub', 'letmein', 'welcome', 'qwerty', '123456'];
        foreach ($deny as $bad) {
            if (str_contains($lower, $bad)) {
                return 'That password is too easy to guess.';
            }
        }
        if ($local !== '' && strlen($local) > 3 && str_contains($lower, $local)) {
            return 'Do not use your email address in the password.';
        }

        return null;
    }

    /** Invalidate remembered devices and rotate the session after a credential change. */
    protected function invalidateOtherSessions(): void
    {
        if ($this->me) {
            $this->db->table('users')->where('id', $this->me['id'])->update([
                'remember_selector' => null, 'remember_validator' => null, 'remember_expires' => null,
            ]);
        }
        $this->response->deleteCookie('th_remember');
        $this->session->regenerate(true);
    }

    /** Log in from the remember-me cookie (selector:validator). */
    private function tryRememberedLogin(): void
    {
        $cookie = (string) ($this->request->getCookie('th_remember') ?? '');
        if ($cookie === '' || substr_count($cookie, ':') !== 1) {
            return;
        }
        [$selector, $validator] = explode(':', $cookie, 2);
        $user = $this->db->table('users')->where('remember_selector', $selector)->get()->getRowArray();
        if (! $user || ! (int) $user['active'] || ! hash_equals((string) $user['remember_validator'], hash('sha256', $validator))) {
            return;
        }
        // Server-side expiry: the cookie's own Max-Age is attacker-controllable.
        if (empty($user['remember_expires']) || strtotime($user['remember_expires']) < time()) {
            $this->db->table('users')->where('id', $user['id'])->update([
                'remember_selector' => null, 'remember_validator' => null, 'remember_expires' => null,
            ]);
            $this->response->deleteCookie('th_remember');

            return;
        }
        $this->session->regenerate();
        $this->session->set(['user_id' => (int) $user['id'], 'role' => $user['role'], 'name' => $user['name']]);
        $this->issueRememberCookie((int) $user['id']); // rotate the validator on every use
    }

    protected function issueRememberCookie(int $userId): void
    {
        $selector  = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(24));
        $this->db->table('users')->where('id', $userId)->update([
            'remember_selector'  => $selector,
            'remember_validator' => hash('sha256', $validator),
            'remember_expires'   => date('Y-m-d H:i:s', time() + 30 * 86400),
        ]);
        // Secure flag follows the actual request scheme so it is set once on HTTPS.
        $this->response->setCookie(
            'th_remember',
            $selector . ':' . $validator,
            30 * 86400,
            '',
            '/',
            '',
            $this->request->isSecure(),
            true,
            null,
            'Strict'
        );
    }

    protected function clearRememberCookie(): void
    {
        if ($this->me) {
            $this->db->table('users')->where('id', $this->me['id'])->update([
                'remember_selector' => null, 'remember_validator' => null, 'remember_expires' => null,
            ]);
        }
        $this->response->deleteCookie('th_remember');
    }

    /** Validate + store uploaded files; returns [{n: original, f: stored, s: bytes}, ...]. */
    protected function storeUploads(string $field = 'files'): array
    {
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'log', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'json', 'eml', 'msg'];
        $dir = WRITEPATH . 'uploads/tickets';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $stored = [];
        $files = $this->request->getFileMultiple($field) ?? [];
        if (count($files) > 10) {
            $this->toast('Only the first 10 files were kept', 'warn');
            $files = array_slice($files, 0, 10);
        }
        foreach ($files as $file) {
            if (! $file || ! $file->isValid() || $file->hasMoved()) {
                continue;
            }
            $ext = strtolower($file->getClientExtension() ?: $file->guessExtension());
            if (! in_array($ext, $allowed, true)) {
                $this->toast('Skipped ' . $file->getClientName() . ' — file type not allowed', 'warn');

                continue;
            }
            if ($file->getSize() > 10 * 1024 * 1024) {
                $this->toast('Skipped ' . $file->getClientName() . ' — larger than 10 MB', 'warn');

                continue;
            }
            $original = $file->getClientName();
            $size     = $file->getSize();
            $name     = bin2hex(random_bytes(16)) . '.' . $ext;
            $file->move($dir, $name);
            $stored[] = ['n' => $original, 'f' => $name, 's' => $size];
        }

        return $stored;
    }

    protected function isAgentRole(?array $u = null): bool
    {
        $u ??= $this->me;

        return $u && in_array($u['role'], ['Administrator', 'Supervisor', 'Agent'], true);
    }

    protected function isAdmin(): bool
    {
        return ($this->me['role'] ?? '') === 'Administrator';
    }

    /**
     * Visibility scope: Administrators see everything; agents and supervisors see
     * their own group's queue plus anything assigned directly to them.
     */
    protected function canSeeTicket(array $t): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return (int) $t['group_id'] === (int) ($this->me['group_id'] ?? 0)
            || (int) ($t['agent_id'] ?? 0) === (int) $this->me['id'];
    }

    /** Filter a ticket array down to what the current agent may see. */
    protected function scopeTickets(array $tickets): array
    {
        if ($this->isAdmin()) {
            return $tickets;
        }

        return array_values(array_filter($tickets, fn ($t) => $this->canSeeTicket($t)));
    }

    /** Agents this user may assign work to (own group for non-admins). */
    protected function assignableAgents(): array
    {
        $agents = $this->db->table('users')
            ->whereIn('role', ['Administrator', 'Supervisor', 'Agent'])
            ->where('active', 1)->orderBy('name')->get()->getResultArray();
        if ($this->isAdmin()) {
            return $agents;
        }

        return array_values(array_filter(
            $agents,
            fn ($a) => (int) $a['group_id'] === (int) ($this->me['group_id'] ?? 0) || (int) $a['id'] === (int) $this->me['id']
        ));
    }

    protected function toast(string $msg, string $kind = 'ok'): void
    {
        $this->session->setFlashdata('toast', ['msg' => $msg, 'kind' => $kind]);
    }

    /** Shared data every agent view needs (sidebar counts, current user). Counts respect the group scope. */
    protected function agentShared(): array
    {
        $awaiting = $this->db->table('changes')->where('state', 'Awaiting approval')->countAllResults();

        $open = $this->scopeTickets($this->db->table('tickets')->whereIn('status', TH_OPEN_STATES)->get()->getResultArray());
        $overdue = 0;
        foreach ($open as $t) {
            if (th_sla($t)['remaining'] <= 0) {
                $overdue++;
            }
        }

        return [
            'me'            => $this->me,
            'isAdmin'       => $this->isAdmin(),
            'assignableAgents' => $this->assignableAgents(),
            'navOpenCount'  => count($open),
            'navApprovals'  => $awaiting,
            'overdueCount'  => $overdue,
            'groups'        => $this->db->table('groups')->orderBy('id')->get()->getResultArray(),
            'agents'        => $this->db->table('users')->whereIn('role', ['Administrator', 'Supervisor', 'Agent'])->orderBy('id')->get()->getResultArray(),
            'requesters'    => $this->db->table('users')->where('role', 'Requester')->orderBy('name')->get()->getResultArray(),
            'cannedList'    => $this->db->table('canned_responses')->get()->getResultArray(),
            'ticketFields'  => $this->db->table('ticket_fields')->orderBy('id')->get()->getResultArray(),
        ];
    }

    protected function users(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            foreach ($this->db->table('users')->get()->getResultArray() as $u) {
                $cache[(int) $u['id']] = $u;
            }
        }

        return $cache;
    }

    protected function userById(?int $id): ?array
    {
        return $id ? ($this->users()[$id] ?? null) : null;
    }

    /* ---------- ticket domain helpers ---------- */

    protected function ticketByCode(string $code): ?array
    {
        return $this->db->table('tickets')->where('code', $code)->get()->getRowArray();
    }

    protected function nextTicketCode(string $type): string
    {
        $prefix = $type === 'Incident' ? 'INC' : 'SR';
        $row = $this->db->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(code,'-',-1) AS UNSIGNED)) AS n FROM tickets WHERE code LIKE ?",
            [$prefix . '-%']
        )->getRowArray();
        $n = (int) ($row['n'] ?? 0);
        if ($n === 0) {
            $n = $prefix === 'INC' ? 2100 : 4400;
        }

        return $prefix . '-' . ($n + 1);
    }

    /** Fire a notification template for a ticket; never throws. */
    protected function notify(string $trigger, array $ticket, array $extra = []): void
    {
        try {
            \App\Libraries\Mailer::sendTemplate(
                $trigger,
                $ticket,
                $this->userById((int) $ticket['requester_id']) ?? [],
                $ticket['agent_id'] ? $this->userById((int) $ticket['agent_id']) : null,
                $extra
            );
        } catch (\Throwable $e) {
            log_message('error', 'notify({trigger}) failed: {msg}', ['trigger' => $trigger, 'msg' => $e->getMessage()]);
        }
    }

    protected function addSystemNote(int $ticketId, string $body): void
    {
        $this->db->table('ticket_messages')->insert([
            'ticket_id' => $ticketId, 'kind' => 'system', 'user_id' => $this->me['id'] ?? null,
            'body' => $body, 'attachments' => '[]', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->table('tickets')->where('id', $ticketId)->update(['updated_at' => date('Y-m-d H:i:s')]);
    }

    /** Store posted custom-field values (inputs named cf_{id}) for a ticket. */
    protected function saveCustomFields(int $ticketId, string $audience): void
    {
        $fields = $this->db->table('ticket_fields')->where($audience, 1)->get()->getResultArray();
        foreach ($fields as $f) {
            $value = $this->request->getPost('cf_' . $f['id']);
            if ($f['type'] === 'Checkbox') {
                $value = $value ? 'Yes' : 'No';
            }
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            $this->db->table('ticket_field_values')->insert([
                'ticket_id' => $ticketId, 'field_id' => $f['id'], 'value' => mb_substr(trim((string) $value), 0, 2000),
            ]);
        }
    }

    /** Render the KB deflection panel for a query, or nothing when no match. */
    protected function kbSuggestions(string $q, string $base)
    {
        $results = th_kb_suggest(
            $this->db->table('articles')->where('status', 'Published')->get()->getResultArray(),
            $q
        );

        return $results
            ? view('agent/_kb_suggestions', ['results' => $results, 'base' => $base])
            : $this->response->setBody('');
    }

    /**
     * Fetch by code, refusing anything outside the caller's group scope.
     * Shared rather than owned by the ticket controller: the JSON API has to
     * apply exactly the same visibility rule or the two drift apart.
     */
    protected function ticketScoped(string $code): ?array
    {
        $t = $this->ticketByCode($code);
        if ($t && ! $this->canSeeTicket($t)) {
            $this->toast('That ticket belongs to another team', 'warn');

            return null;
        }

        return $t;
    }

    protected function createTicket(array $o): array
    {
        return (new \App\Libraries\TicketIntake((int) ($this->me['id'] ?? 0)))->create($o);
    }

    /**
     * Record one vote per person per article (changeable, never stackable)
     * and recount the totals. Returns a message for the toast.
     */
    protected function recordVote(int $articleId, string $vote): string
    {
        $vote = $vote === 'up' ? 'up' : 'down';
        $article = $this->db->table('articles')->where('id', $articleId)->get()->getRowArray();
        if (! $article) {
            return 'That article no longer exists';
        }

        $existing = $this->db->table('article_votes')
            ->where('article_id', $articleId)->where('user_id', $this->me['id'])->get()->getRowArray();

        if ($existing && $existing['vote'] === $vote) {
            $msg = 'You already voted on this one';
        } elseif ($existing) {
            $this->db->table('article_votes')->where('id', $existing['id'])->update(['vote' => $vote, 'created_at' => date('Y-m-d H:i:s')]);
            $msg = 'Vote changed';
        } else {
            $this->db->table('article_votes')->insert([
                'article_id' => $articleId, 'user_id' => $this->me['id'],
                'vote' => $vote, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $msg = $vote === 'up' ? 'Thanks — noted' : 'Thanks — we will rework this one';
        }

        // Totals are always derived from the votes table, never incremented blindly.
        $up   = $this->db->table('article_votes')->where('article_id', $articleId)->where('vote', 'up')->countAllResults();
        $down = $this->db->table('article_votes')->where('article_id', $articleId)->where('vote', 'down')->countAllResults();
        $this->db->table('articles')->where('id', $articleId)->update(['up_votes' => $up, 'down_votes' => $down]);

        return $msg;
    }

    /** Fire "Ticket is updated" automations for one or more tickets; never fatal. */
    protected function fireUpdated(int|array $ticketIds): void
    {
        try {
            $engine = new \App\Libraries\AutomationEngine();
            foreach ((array) $ticketIds as $id) {
                $engine->event('Ticket is updated', (int) $id);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Automation event failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }
}
