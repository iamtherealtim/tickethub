<?php

namespace App\Controllers;

use App\Libraries\Audit;
use App\Libraries\AutomationEngine;
use App\Libraries\Mailer;
use App\Libraries\PdqConnect;
use App\Libraries\Settings;

class AdminController extends BaseController
{
    private const TABS = ['agents', 'people', 'groups', 'routing', 'sla', 'hours', 'rules', 'fields', 'email', 'mail', 'sso', 'integrations', 'audit'];

    public function index(string $tab = 'agents')
    {
        // Plug-in tabs live in app/Views/agent/admin/<tab>.php with a sibling
        // <tab>.tab.php describing label/icon/order and a data provider.
        $plugin = preg_match('/^[a-z][a-z0-9_-]*$/', $tab) && is_file(APPPATH . 'Views/agent/admin/' . $tab . '.tab.php')
            ? $tab : null;
        if ($plugin === null && ! in_array($tab, self::TABS, true)) {
            $tab = 'agents';
        }
        $pluginVars = [];
        if ($plugin !== null) {
            $meta = include APPPATH . 'Views/agent/admin/' . $plugin . '.tab.php';
            if (isset($meta['data']) && is_callable($meta['data'])) {
                $pluginVars = (array) ($meta['data'])($this->request, $this->me);
            }
        }

        $openTickets = $this->db->table('tickets')->whereIn('status', TH_OPEN_STATES)->get()->getResultArray();
        $openByAgent = [];
        $openByGroup = [];
        foreach ($openTickets as $t) {
            if ($t['agent_id']) {
                $openByAgent[(int) $t['agent_id']] = ($openByAgent[(int) $t['agent_id']] ?? 0) + 1;
            }
            $openByGroup[(int) $t['group_id']] = ($openByGroup[(int) $t['group_id']] ?? 0) + 1;
        }

        return view('agent/admin', $pluginVars + $this->agentShared() + [
            'title' => 'Admin', 'nav' => 'admin', 'tab' => $tab, 'pluginTab' => $plugin,
            'users' => $this->users(),
            'openByAgent' => $openByAgent, 'openByGroup' => $openByGroup,
            'hours' => $this->db->table('business_hours')->get()->getResultArray(),
            'apiTokens' => $this->db->query(
                'SELECT t.*, u.name AS who FROM api_tokens t JOIN users u ON u.id = t.user_id ORDER BY t.id DESC'
            )->getResultArray(),
            'slas' => $this->db->table('slas')->get()->getResultArray(),
            'rules' => $this->db->table('automations')->orderBy('id')->get()->getResultArray(),
            'fields' => $this->db->table('ticket_fields')->get()->getResultArray(),
            'routes' => $this->db->table('routing_rules')->orderBy('position')->orderBy('id')->get()->getResultArray(),
        ] + $this->autoLogData($tab) + [
            'templates' => $this->db->table('email_templates')->get()->getResultArray(),
            'groupMembers' => $this->groupMembers(),
            'settings' => Settings::all(),
            'mailConfigured' => Mailer::configured(),
            'auditRows' => $tab === 'audit'
                ? $this->db->table('audit_log')->orderBy('id', 'DESC')->limit(100)->get()->getResultArray()
                : [],
        ]);
    }

    /** Filtered, sorted, paginated automation log for the rules tab. */
    private function autoLogData(string $tab): array
    {
        if ($tab !== 'rules') {
            return ['autoLog' => [], 'logTotal' => 0, 'logPage' => 1, 'logPerPage' => 20, 'logFilters' => []];
        }
        $r = $this->request;
        $filters = [
            'log_kind' => in_array($r->getGet('log_kind'), ['routing', 'automation'], true) ? $r->getGet('log_kind') : '',
            'log_q'    => trim((string) $r->getGet('log_q')),
            'log_sort' => $r->getGet('log_sort') === 'asc' ? 'asc' : 'desc',
        ];

        $b = $this->db->table('automation_log');
        if ($filters['log_kind'] !== '') {
            $b->where('kind', $filters['log_kind']);
        }
        if ($filters['log_q'] !== '') {
            $b->groupStart()
                ->like('ticket_code', $filters['log_q'])
                ->orLike('rule_name', $filters['log_q'])
                ->orLike('summary', $filters['log_q'])
                ->groupEnd();
        }
        $total = $b->countAllResults(false);

        $perPage = 20;
        $page = max(1, min((int) ($r->getGet('page') ?: 1), max(1, (int) ceil($total / $perPage))));
        $rows = $b->orderBy('id', $filters['log_sort'] === 'asc' ? 'ASC' : 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['autoLog' => $rows, 'logTotal' => $total, 'logPage' => $page, 'logPerPage' => $perPage, 'logFilters' => $filters];
    }

    /** A one-off password nobody has to remember: they must change it at first sign-in. */
    private function randomPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Aa'), '=');
    }

    /**
     * Email the temporary password when mail works; otherwise return it once so the
     * admin can pass it on out of band. Never a shared, guessable default.
     */
    private function inviteMessage(string $name, string $email, string $temp): string
    {
        $sent = Mailer::send(
            $email,
            'Your TicketHub account',
            'Hi ' . explode(' ', $name)[0] . ",\n\nAn account has been created for you.\n\n"
            . "Sign in at: " . site_url('login') . "\nEmail: " . $email . "\nTemporary password: " . $temp
            . "\n\nYou will be asked to choose your own password straight away.\n\n— TicketHub"
        );

        return $sent
            ? 'Invite emailed to ' . $email . ' with a one-time password'
            : 'Account created. Email is not configured, so give them this one-time password now: ' . $temp;
    }

    /**
     * Who can actually take work in each group. Deactivated agents are excluded
     * on purpose: they cannot be assigned, so counting them makes an unstaffed
     * queue look covered while its tickets and SLA warnings go nowhere.
     */
    private function groupMembers(): array
    {
        $members = [];
        foreach ($this->users() as $u) {
            if ($u['group_id'] && (int) $u['active'] === 1 && in_array($u['role'], ['Administrator', 'Supervisor', 'Agent'], true)) {
                $members[(int) $u['group_id']][] = $u;
            }
        }

        return $members;
    }

    public function addAgent()
    {
        $p = $this->request->getPost();
        if (empty($p['name']) || empty($p['email'])) {
            $this->toast('Name and email are needed', 'warn');

            return redirect()->back();
        }
        if (! filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
            $this->toast('That is not a valid email address', 'warn');

            return redirect()->back();
        }
        if ($this->db->table('users')->where('email', $p['email'])->countAllResults()) {
            $this->toast('That email is already registered', 'warn');

            return redirect()->back();
        }
        $now  = date('Y-m-d H:i:s');
        $temp = $this->randomPassword();
        $this->db->table('users')->insert([
            'name' => $p['name'], 'email' => strtolower(trim($p['email'])),
            'password_hash' => password_hash($temp, PASSWORD_DEFAULT),
            'role' => in_array($p['role'] ?? '', ['Agent', 'Supervisor', 'Administrator'], true) ? $p['role'] : 'Agent',
            'title' => ($p['title'] ?? '') ?: 'Support Analyst', 'group_id' => (int) ($p['group_id'] ?? 0),
            'color' => 'violet', 'active' => 1, 'must_change_password' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Audit::log('admin.agent_invited', $p['email']);
        $this->toast($this->inviteMessage($p['name'], $p['email'], $temp));

        return redirect()->to('/app/admin/agents');
    }

    public function updateAgent(int $id)
    {
        $u = $this->db->table('users')->where('id', $id)->whereIn('role', ['Administrator', 'Supervisor', 'Agent'])->get()->getRowArray();
        $p = $this->request->getPost();
        if (! $u || empty($p['name'])) {
            $this->toast('Agent not found or name missing', 'warn');

            return redirect()->to('/app/admin/agents');
        }
        if ((int) $u['id'] === (int) $this->me['id'] && ($p['role'] ?? '') !== 'Administrator') {
            $this->toast('You cannot demote your own account', 'warn');

            return redirect()->to('/app/admin/agents');
        }
        $this->db->table('users')->where('id', $id)->update([
            'name' => $p['name'], 'title' => ($p['title'] ?? '') ?: $u['title'],
            'role' => in_array($p['role'] ?? '', ['Agent', 'Supervisor', 'Administrator'], true) ? $p['role'] : $u['role'],
            'group_id' => (int) ($p['group_id'] ?? 0) ?: $u['group_id'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Audit::log('admin.agent_updated', $u['email'] . ' → role ' . ($p['role'] ?? $u['role']));
        $this->toast($p['name'] . ' updated');

        return redirect()->to('/app/admin/agents');
    }

    public function addPerson()
    {
        $p = $this->request->getPost();
        if (empty($p['name']) || empty($p['email'])) {
            $this->toast('Name and email are needed', 'warn');

            return redirect()->back();
        }
        if (! filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
            $this->toast('That is not a valid email address', 'warn');

            return redirect()->back();
        }
        if ($this->db->table('users')->where('email', $p['email'])->countAllResults()) {
            $this->toast('That email is already registered', 'warn');

            return redirect()->back();
        }
        $now  = date('Y-m-d H:i:s');
        $temp = $this->randomPassword();
        $this->db->table('users')->insert([
            'name' => $p['name'], 'email' => strtolower(trim($p['email'])),
            'password_hash' => password_hash($temp, PASSWORD_DEFAULT),
            'role' => 'Requester', 'title' => ($p['title'] ?? '') ?: 'Employee',
            'dept' => ($p['dept'] ?? '') ?: null, 'site' => ($p['site'] ?? '') ?: null, 'phone' => ($p['phone'] ?? '') ?: null,
            'color' => ['brand', 'ink', 'violet', 'signal'][random_int(0, 3)],
            'active' => 1, 'must_change_password' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Audit::log('admin.person_added', $p['email']);
        $this->toast($this->inviteMessage($p['name'], $p['email'], $temp));

        return redirect()->to('/app/admin/people');
    }

    public function updatePerson(int $id)
    {
        $u = $this->db->table('users')->where('id', $id)->where('role', 'Requester')->get()->getRowArray();
        $p = $this->request->getPost();
        if (! $u || empty($p['name'])) {
            $this->toast('Person not found or name missing', 'warn');

            return redirect()->to('/app/admin/people');
        }
        $this->db->table('users')->where('id', $id)->update([
            'name' => $p['name'], 'title' => ($p['title'] ?? '') ?: null,
            'dept' => ($p['dept'] ?? '') ?: null, 'site' => ($p['site'] ?? '') ?: null, 'phone' => ($p['phone'] ?? '') ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Audit::log('admin.person_updated', $u['email']);
        $this->toast($p['name'] . ' updated');

        return redirect()->to('/app/admin/people');
    }

    public function addGroup()
    {
        $p = $this->request->getPost();
        if (empty($p['name'])) {
            $this->toast('Name the group', 'warn');

            return redirect()->back();
        }
        $this->db->table('groups')->insert([
            'name' => $p['name'], 'description' => ($p['description'] ?? '') ?: '—',
            'hours_id' => (int) ($p['hours_id'] ?? 1),
        ]);
        $this->toast('Group created');

        return redirect()->to('/app/admin/groups');
    }

    public function updateGroup(int $id)
    {
        $p = $this->request->getPost();
        if (empty($p['name'])) {
            $this->toast('Name the group', 'warn');
        } else {
            $this->db->table('groups')->where('id', $id)->update([
                'name' => $p['name'], 'description' => ($p['description'] ?? '') ?: '—', 'hours_id' => (int) ($p['hours_id'] ?? 1),
            ]);
            Audit::log('admin.group_updated', $p['name']);
            $this->toast('Group updated');
        }

        return redirect()->to('/app/admin/groups');
    }

    public function deleteGroup(int $id)
    {
        $inUseTickets = $this->db->table('tickets')->where('group_id', $id)->countAllResults();
        $inUseAgents  = $this->db->table('users')->where('group_id', $id)->countAllResults();
        if ($inUseTickets || $inUseAgents) {
            $this->toast('Cannot delete — ' . $inUseTickets . ' ticket(s) and ' . $inUseAgents . ' agent(s) still reference this group', 'warn');
        } else {
            $g = $this->db->table('groups')->where('id', $id)->get()->getRowArray();
            $this->db->table('groups')->where('id', $id)->delete();
            Audit::log('admin.group_deleted', $g['name'] ?? (string) $id);
            $this->toast('Group deleted', 'bad');
        }

        return redirect()->to('/app/admin/groups');
    }

    public function addSla()
    {
        $p = $this->request->getPost();
        if (empty($p['name'])) {
            $this->toast('Name the policy', 'warn');

            return redirect()->back();
        }
        $this->db->table('slas')->insert([
            'name' => $p['name'], 'first_response' => ($p['first_response'] ?? '') ?: '1 hour',
            'resolution' => ($p['resolution'] ?? '') ?: '8 hours',
            'hours' => $p['hours'] ?? 'Business hours', 'escalation' => ($p['escalation'] ?? '') ?: 'None', 'active' => 1,
        ]);
        $this->toast('Policy created');

        return redirect()->to('/app/admin/sla');
    }

    public function updateSla(int $id)
    {
        $p = $this->request->getPost();
        if (empty($p['name'])) {
            $this->toast('Name the policy', 'warn');
        } else {
            $this->db->table('slas')->where('id', $id)->update([
                'name' => $p['name'], 'first_response' => ($p['first_response'] ?? '') ?: '1 hour',
                'resolution' => ($p['resolution'] ?? '') ?: '8 hours', 'hours' => $p['hours'] ?? 'Business hours',
                'escalation' => ($p['escalation'] ?? '') ?: 'None',
            ]);
            Audit::log('admin.sla_updated', $p['name']);
            $this->toast('Policy updated');
        }

        return redirect()->to('/app/admin/sla');
    }

    public function deleteSla(int $id)
    {
        $s = $this->db->table('slas')->where('id', $id)->get()->getRowArray();
        $this->db->table('slas')->where('id', $id)->delete();
        Audit::log('admin.sla_deleted', $s['name'] ?? (string) $id);
        $this->toast('Policy deleted', 'bad');

        return redirect()->to('/app/admin/sla');
    }

    /** Parse the builder's parallel arrays into validated conditions/actions JSON. */
    private function rulePayload(): ?array
    {
        $p = $this->request->getPost();
        if (empty($p['name'])) {
            $this->toast('Name the rule', 'warn');

            return null;
        }

        $conditions = [];
        foreach ((array) ($p['cond_field'] ?? []) as $i => $field) {
            $op    = (string) ($p['cond_op'][$i] ?? '');
            $value = trim((string) ($p['cond_value'][$i] ?? ''));
            if (! in_array($field, AutomationEngine::CONDITION_FIELDS, true) || ! in_array($op, AutomationEngine::OPERATORS, true)) {
                continue;
            }
            if ($value === '' && ! in_array($op, ['not_contains', 'not_equals'], true)) {
                continue;
            }
            $conditions[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }

        $actions = [];
        foreach ((array) ($p['act_type'] ?? []) as $i => $type) {
            $value = trim((string) ($p['act_value'][$i] ?? ''));
            if (! in_array($type, AutomationEngine::ACTION_TYPES, true)) {
                continue;
            }
            if ($value === '' && $type !== 'escalate') {
                continue;
            }
            $actions[] = ['type' => $type, 'value' => $value];
        }
        if (! $actions) {
            $this->toast('Add at least one action — a rule that does nothing is a note, not a rule', 'warn');

            return null;
        }

        return [
            'name'       => $p['name'],
            'when_event' => in_array($p['when_event'] ?? '', ['Ticket is created', 'Ticket is updated', 'Time is reached'], true) ? $p['when_event'] : 'Ticket is created',
            'conditions' => json_encode($conditions),
            'actions'    => json_encode($actions),
        ];
    }

    public function addRule()
    {
        if ($data = $this->rulePayload()) {
            $data += ['runs' => 0, 'active' => 1];
            $this->db->table('automations')->insert($data);
            Audit::log('admin.rule_added', $data['name']);
            $this->toast('Automation created');
        }

        return redirect()->to('/app/admin/rules');
    }

    public function updateRule(int $id)
    {
        if ($data = $this->rulePayload()) {
            $this->db->table('automations')->where('id', $id)->update($data);
            // Edited rules may fire again on tickets they already touched.
            $this->db->table('automation_runs')->where('rule_id', $id)->delete();
            Audit::log('admin.rule_updated', $data['name']);
            $this->toast('Automation updated');
        }

        return redirect()->to('/app/admin/rules');
    }

    public function deleteRule(int $id)
    {
        $r = $this->db->table('automations')->where('id', $id)->get()->getRowArray();
        $this->db->table('automations')->where('id', $id)->delete();
        Audit::log('admin.rule_deleted', $r['name'] ?? (string) $id);
        $this->toast('Automation deleted', 'bad');

        return redirect()->to('/app/admin/rules');
    }

    public function runRules()
    {
        $actions = (new AutomationEngine())->run();
        Audit::log('admin.rules_run', count($actions) . ' action(s)');
        $this->toast($actions ? count($actions) . ' action(s): ' . implode(' · ', array_slice($actions, 0, 3)) . (count($actions) > 3 ? ' …' : '') : 'Nothing to do — all rules are satisfied');

        return redirect()->to('/app/admin/rules');
    }

    private function fieldOptions(): string
    {
        $raw = (string) $this->request->getPost('options');
        $opts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($o) => $o !== ''));

        return json_encode($opts);
    }

    public function addField()
    {
        $p = $this->request->getPost();
        if (empty($p['label'])) {
            $this->toast('Label the field', 'warn');

            return redirect()->back();
        }
        $this->db->table('ticket_fields')->insert([
            'label' => $p['label'], 'type' => $p['type'] ?? 'Text',
            'required' => ($p['required'] ?? '') === 'yes' ? 1 : 0,
            'agents' => isset($p['agents']) ? 1 : 0, 'portal' => isset($p['portal']) ? 1 : 0,
            'options' => $this->fieldOptions(),
        ]);
        $this->toast('Field added');

        return redirect()->to('/app/admin/fields');
    }

    /* ---------- routing rules ---------- */

    private function routePayload(): ?array
    {
        $p = $this->request->getPost();
        if (trim((string) ($p['match_value'] ?? '')) === '' || empty($p['group_id'])) {
            $this->toast('A match value and a target team are needed', 'warn');

            return null;
        }

        return [
            'position'    => (int) ($p['position'] ?? 10),
            'match_type'  => in_array($p['match_type'] ?? '', ['Category', 'Subject contains', 'Source'], true) ? $p['match_type'] : 'Category',
            'match_value' => trim((string) $p['match_value']),
            'group_id'    => (int) $p['group_id'],
            'agent_id'    => ! empty($p['agent_id']) ? (int) $p['agent_id'] : null,
            'priority'    => in_array($p['priority'] ?? '', ['Urgent', 'High', 'Medium', 'Low'], true) ? $p['priority'] : null,
        ];
    }

    public function addRoute()
    {
        if ($data = $this->routePayload()) {
            $data['active'] = 1;
            $this->db->table('routing_rules')->insert($data);
            Audit::log('admin.route_added', $data['match_type'] . ' "' . $data['match_value'] . '"');
            $this->toast('Routing rule added');
        }

        return redirect()->to('/app/admin/routing');
    }

    public function updateRoute(int $id)
    {
        if ($data = $this->routePayload()) {
            $this->db->table('routing_rules')->where('id', $id)->update($data);
            Audit::log('admin.route_updated', $data['match_type'] . ' "' . $data['match_value'] . '"');
            $this->toast('Routing rule updated');
        }

        return redirect()->to('/app/admin/routing');
    }

    public function deleteRoute(int $id)
    {
        $this->db->table('routing_rules')->where('id', $id)->delete();
        Audit::log('admin.route_deleted', (string) $id);
        $this->toast('Routing rule deleted', 'bad');

        return redirect()->to('/app/admin/routing');
    }

    public function saveDefaultGroup()
    {
        $gid = (int) $this->request->getPost('default_group_id');
        if ($this->db->table('groups')->where('id', $gid)->countAllResults()) {
            Settings::set('default_group_id', (string) $gid);
            $this->toast('Routing defaults saved');
        }
        // Same form, so one save covers both fallback team and balancing.
        Settings::set('auto_assign', $this->request->getPost('auto_assign') ? '1' : '0');
        Audit::log('admin.routing_defaults', 'group ' . $gid . ', auto_assign=' . ($this->request->getPost('auto_assign') ? '1' : '0'));

        return redirect()->to('/app/admin/routing');
    }

    public function createToken()
    {
        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            $this->toast('Name the token so you know what to revoke later', 'warn');

            return redirect()->to('/app/admin/integrations');
        }

        // A token acts as one specific person: their role and group scope apply
        // to every call, and the audit trail names them.
        $userId = (int) $this->request->getPost('user_id');
        $actsAs = $this->db->table('users')->where('id', $userId)->where('active', 1)
            ->whereIn('role', ['Administrator', 'Supervisor', 'Agent'])->get()->getRowArray();
        if (! $actsAs) {
            $this->toast('Pick an active agent, supervisor or administrator for the token to act as', 'warn');

            return redirect()->to('/app/admin/integrations');
        }

        $expiresAt = null;
        $days      = trim((string) $this->request->getPost('expires_days'));
        if ($days !== '') {
            if (! ctype_digit($days) || (int) $days < 1 || (int) $days > 3650) {
                $this->toast('Expiry must be a whole number of days between 1 and 3650, or blank for never', 'warn');

                return redirect()->to('/app/admin/integrations');
            }
            $expiresAt = date('Y-m-d H:i:s', time() + (int) $days * 86400);
        }

        // Shown once. Only the hash is kept, so this is the single opportunity
        // to copy it — same contract as every other API token worth trusting.
        $token = bin2hex(random_bytes(24));
        $this->db->table('api_tokens')->insert([
            'user_id' => (int) $actsAs['id'],
            'name' => mb_substr($name, 0, 60),
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Audit::log('admin.api_token_created', $name . ' (acts as ' . $actsAs['email'] . ($expiresAt ? ', expires ' . $expiresAt : '') . ')');
        $this->session->setFlashdata('new_api_token', $token);
        $this->toast('Token created — copy it now, it will not be shown again');

        return redirect()->to('/app/admin/integrations');
    }

    public function revokeToken(int $id)
    {
        $row = $this->db->table('api_tokens')->where('id', $id)->get()->getRowArray();
        if ($row) {
            $this->db->table('api_tokens')->where('id', $id)->update(['revoked_at' => date('Y-m-d H:i:s')]);
            Audit::log('admin.api_token_revoked', $row['name']);
            $this->toast('Token revoked');
        }

        return redirect()->to('/app/admin/integrations');
    }

    public function updateField(int $id)
    {
        $p = $this->request->getPost();
        if (empty($p['label'])) {
            $this->toast('Label the field', 'warn');
        } else {
            $this->db->table('ticket_fields')->where('id', $id)->update([
                'label' => $p['label'], 'type' => $p['type'] ?? 'Text',
                'required' => ($p['required'] ?? '') === 'yes' ? 1 : 0,
                'agents' => isset($p['agents']) ? 1 : 0, 'portal' => isset($p['portal']) ? 1 : 0,
                'options' => $this->fieldOptions(),
            ]);
            $this->toast('Field updated');
        }

        return redirect()->to('/app/admin/fields');
    }

    public function deleteField(int $id)
    {
        // Remove stored answers too, rather than leaving them orphaned.
        $this->db->table('ticket_field_values')->where('field_id', $id)->delete();
        $this->db->table('ticket_fields')->where('id', $id)->delete();
        $this->toast('Field deleted', 'bad');

        return redirect()->to('/app/admin/fields');
    }

    /**
     * Holiday dates are what the SLA clock actually skips, so they are stored as
     * a JSON array of YYYY-MM-DD. Accepts a comma- or newline-separated list and
     * quietly drops anything that is not a real date.
     */
    private function holidayDates(?string $raw): string
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $bit) {
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', $bit);
            if ($d && $d->format('Y-m-d') === $bit) {
                $out[$bit] = true;
            }
        }
        $dates = array_keys($out);
        sort($dates);

        return json_encode($dates);
    }

    public function addHours()
    {
        $p = $this->request->getPost();
        if (empty($p['name'])) {
            $this->toast('Name the calendar', 'warn');
        } else {
            $this->db->table('business_hours')->insert([
                'name' => $p['name'], 'tz' => ($p['tz'] ?? '') ?: 'UTC', 'days' => ($p['days'] ?? '') ?: 'Mon-Fri',
                'time_range' => ($p['time_range'] ?? '') ?: '09:00 - 17:00', 'holidays' => ($p['holidays'] ?? '') ?: 'None',
                'holiday_dates' => $this->holidayDates($p['holiday_dates'] ?? ''),
            ]);
            Audit::log('admin.hours_added', $p['name']);
            $this->toast('Calendar added');
        }

        return redirect()->to('/app/admin/hours');
    }

    public function updateHours(int $id)
    {
        $p = $this->request->getPost();
        if (empty($p['name'])) {
            $this->toast('Name the calendar', 'warn');
        } else {
            $this->db->table('business_hours')->where('id', $id)->update([
                'name' => $p['name'], 'tz' => ($p['tz'] ?? '') ?: 'UTC', 'days' => ($p['days'] ?? '') ?: 'Mon-Fri',
                'time_range' => ($p['time_range'] ?? '') ?: '09:00 - 17:00', 'holidays' => ($p['holidays'] ?? '') ?: 'None',
                'holiday_dates' => $this->holidayDates($p['holiday_dates'] ?? ''),
            ]);
            Audit::log('admin.hours_updated', $p['name']);
            $this->toast('Calendar updated');
        }

        return redirect()->to('/app/admin/hours');
    }

    public function saveTimezone()
    {
        $tz = (string) $this->request->getPost('app_timezone');
        try {
            new \DateTimeZone($tz);
            Settings::set('app_timezone', $tz);
            Audit::log('admin.timezone_changed', $tz);
            $this->toast('Display timezone set to ' . $tz);
        } catch (\Throwable) {
            $this->toast('That is not a valid timezone', 'warn');
        }

        return redirect()->to('/app/admin/hours');
    }

    public function saveGraph()
    {
        $p = $this->request->getPost();
        Settings::saveMany([
            'graph_inbound_enabled' => isset($p['graph_inbound_enabled']) ? '1' : '0',
            'graph_mailbox'         => strtolower(trim((string) ($p['graph_mailbox'] ?? ''))),
        ]);
        Audit::log('admin.graph_saved', 'enabled=' . (isset($p['graph_inbound_enabled']) ? '1' : '0') . ' mailbox=' . ($p['graph_mailbox'] ?? ''));
        $this->toast('Microsoft 365 mailbox settings saved');

        return redirect()->to('/app/admin/mail');
    }

    public function fetchGraph()
    {
        if (! \App\Libraries\GraphMail::configured()) {
            $this->toast('Enable the mailbox and fill in the SSO credentials (tenant, client, secret) first', 'warn');

            return redirect()->to('/app/admin/mail');
        }
        $actions = \App\Libraries\GraphMail::poll();
        Audit::log('admin.graph_fetch', count($actions) . ' line(s)');
        $this->toast(implode(' · ', array_slice($actions, 0, 3)) . (count($actions) > 3 ? ' …' : ''), str_contains(strtolower($actions[0] ?? ''), 'error') || str_contains(strtolower($actions[0] ?? ''), 'could not') ? 'warn' : 'ok');

        return redirect()->to('/app/admin/mail');
    }

    /* ---------- PDQ Connect integration ---------- */

    public function savePdq()
    {
        $p = $this->request->getPost();
        if ($err = th_outbound_url_error((string) ($p['pdq_base_url'] ?? ''))) {
            $this->toast('API base URL rejected — ' . $err, 'warn');

            return redirect()->to('/app/admin/integrations');
        }
        Settings::saveMany([
            'pdq_enabled'   => isset($p['pdq_enabled']) ? '1' : '0',
            'pdq_api_key'   => $p['pdq_api_key'] ?? '',
            'pdq_base_url'  => rtrim(trim((string) ($p['pdq_base_url'] ?? '')), '/') ?: 'https://app.pdq.com/v1/api',
            'pdq_auto_sync' => isset($p['pdq_auto_sync']) ? '1' : '0',
        ], ['pdq_api_key']);
        Audit::log('admin.pdq_saved', 'enabled=' . (isset($p['pdq_enabled']) ? '1' : '0') . ' autosync=' . (isset($p['pdq_auto_sync']) ? '1' : '0'));
        $this->toast('PDQ Connect settings saved');

        return redirect()->to('/app/admin/integrations');
    }

    public function testPdq()
    {
        if (! \App\Libraries\PdqConnect::configured()) {
            $this->toast('Enable the integration and save an API key first', 'warn');

            return redirect()->to('/app/admin/integrations');
        }
        $res = \App\Libraries\PdqConnect::testConnection();
        Audit::log('admin.pdq_test', $res['message']);
        $this->toast($res['message'], $res['ok'] ? 'ok' : 'bad');

        return redirect()->to('/app/admin/integrations');
    }

    public function syncPdq()
    {
        if (! \App\Libraries\PdqConnect::configured()) {
            $this->toast('Enable the integration and save an API key first', 'warn');

            return redirect()->back();
        }
        $res = \App\Libraries\PdqConnect::sync();
        Audit::log('admin.pdq_sync', $res['message']);
        $this->toast($res['message'], $res['ok'] ? 'ok' : 'bad');

        return redirect()->back();
    }

    public function saveInbound()
    {
        $p = $this->request->getPost();
        Settings::set('inbound_email_enabled', isset($p['inbound_email_enabled']) ? '1' : '0');
        Settings::set('inbound_unknown_policy', ($p['inbound_unknown_policy'] ?? '') === 'drop' ? 'drop' : 'create');
        Settings::set('inbound_reopen_days', (string) max(0, min(365, (int) ($p['inbound_reopen_days'] ?? 5))));
        if (isset($p['regenerate'])) {
            Settings::set('inbound_email_secret', bin2hex(random_bytes(16)));
            Audit::log('admin.inbound_secret_rotated');
            $this->toast('Inbound settings saved — new secret generated');
        } else {
            $this->toast('Inbound settings saved');
        }
        Audit::log('admin.inbound_saved', 'enabled=' . (isset($p['inbound_email_enabled']) ? '1' : '0'));

        return redirect()->to('/app/admin/mail');
    }

    public function updateTemplate(int $id)
    {
        $tpl = $this->db->table('email_templates')->where('id', $id)->get()->getRowArray();
        $p = $this->request->getPost();
        if (! $tpl) {
            return redirect()->to('/app/admin/email');
        }
        if (empty($p['subject'])) {
            $this->toast('The template needs a subject', 'warn');

            return redirect()->to('/app/admin/email');
        }
        $this->db->table('email_templates')->where('id', $id)->update([
            'subject' => $p['subject'],
            'body'    => $p['body'] ?? '',
        ]);
        $this->toast('Template "' . $tpl['name'] . '" saved');

        return redirect()->to('/app/admin/email');
    }

    public function saveMail()
    {
        $p = $this->request->getPost();
        Settings::saveMany([
            'mail_enabled'    => isset($p['mail_enabled']) ? '1' : '0',
            'mail_host'       => $p['mail_host'] ?? '',
            'mail_port'       => ($p['mail_port'] ?? '') ?: '587',
            'mail_username'   => $p['mail_username'] ?? '',
            'mail_password'   => $p['mail_password'] ?? '',
            'mail_encryption' => in_array($p['mail_encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $p['mail_encryption'] : 'tls',
            'mail_from_email' => $p['mail_from_email'] ?? '',
            'mail_from_name'  => $p['mail_from_name'] ?? '',
        ], ['mail_password']);
        Audit::log('admin.mail_saved', 'enabled=' . (isset($p['mail_enabled']) ? '1' : '0') . ' host=' . ($p['mail_host'] ?? ''));
        $this->toast('Email settings saved');

        return redirect()->to('/app/admin/mail');
    }

    public function testMail()
    {
        if (! Mailer::configured()) {
            $this->toast('Enable email and set an SMTP host first', 'warn');

            return redirect()->to('/app/admin/mail');
        }
        $ok = Mailer::send(
            $this->me['email'],
            'TicketHub test email',
            "This is a test message from TicketHub.\n\nIf you are reading it, the SMTP settings work.\n\nSent " . date('j M Y, H:i') . ' to ' . $this->me['email'] . '.'
        );
        $this->toast(
            $ok ? 'Test email sent to ' . $this->me['email'] : 'Test email failed — check the SMTP settings and writable/logs for details',
            $ok ? 'ok' : 'bad'
        );

        return redirect()->to('/app/admin/mail');
    }

    public function saveSso()
    {
        $p = $this->request->getPost();
        $tenant = strtolower(trim((string) ($p['azure_tenant_id'] ?? '')));
        if ($tenant !== '' && ! in_array($tenant, ['common', 'organizations'], true)
            && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tenant)) {
            $this->toast('Tenant must be a directory GUID, "common" or "organizations"', 'warn');

            return redirect()->to('/app/admin/sso');
        }
        $groupIdRule = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
        $groups = [];
        foreach (['sso_agent_group', 'sso_admin_group'] as $k) {
            $v = strtolower(trim((string) ($p[$k] ?? '')));
            if ($v !== '' && ! preg_match($groupIdRule, $v)) {
                $this->toast('Group ids must be Entra group object ids (GUIDs) — leave blank to disable mapping', 'warn');

                return redirect()->to('/app/admin/sso');
            }
            $groups[$k] = $v;
        }
        Settings::saveMany([
            'azure_enabled'       => isset($p['azure_enabled']) ? '1' : '0',
            'azure_tenant_id'     => $tenant,
            'azure_client_id'     => trim((string) ($p['azure_client_id'] ?? '')),
            'azure_client_secret' => $p['azure_client_secret'] ?? '',
            'azure_autoprovision' => isset($p['azure_autoprovision']) ? '1' : '0',
        ] + $groups, ['azure_client_secret']);
        Audit::log('admin.sso_saved', 'enabled=' . (isset($p['azure_enabled']) ? '1' : '0'));
        $this->toast('Single sign-on settings saved');

        return redirect()->to('/app/admin/sso');
    }

    public function toggle(string $kind, int $id)
    {
        [$table, $label] = match ($kind) {
            'agent'    => ['users', 'name'],
            'sla'      => ['slas', 'name'],
            'rule'     => ['automations', 'name'],
            'template' => ['email_templates', 'name'],
            'route'    => ['routing_rules', 'match_value'],
            default    => [null, null],
        };
        if ($table) {
            $row = $this->db->table($table)->where('id', $id)->get()->getRowArray();
            if ($row) {
                if ($table === 'users' && (int) $row['id'] === (int) $this->me['id']) {
                    $this->toast('You cannot deactivate your own account', 'warn');

                    return redirect()->back();
                }
                $on = ! (int) $row['active'];
                $this->db->table($table)->where('id', $id)->update(['active' => (int) $on]);
                Audit::log('admin.toggle', $kind . ' "' . $row[$label] . '" → ' . ($on ? 'on' : 'off'));
                $this->toast($row[$label] . ($on ? ' enabled' : ' disabled'), $on ? 'ok' : 'warn');
            }
        }

        return redirect()->back();
    }
}
