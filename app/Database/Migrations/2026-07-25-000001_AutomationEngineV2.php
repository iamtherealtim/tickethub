<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AutomationEngineV2 extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE automations ADD COLUMN conditions TEXT NULL, ADD COLUMN actions TEXT NULL');

        // Time-based rules run at most once per (rule, ticket).
        $this->db->query("CREATE TABLE automation_runs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rule_id INT UNSIGNED NOT NULL,
            ticket_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_rule_ticket (rule_id, ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Convert the seeded free-text rules into executable definitions.
        $structured = [
            'Route password resets to Identity & Access' => [
                'when' => 'Ticket is created',
                'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'password']],
                'actions' => [['type' => 'move_group', 'value' => '4'], ['type' => 'set_priority', 'value' => 'Medium']],
            ],
            'Escalate urgent tickets at 50% SLA' => [
                'when' => 'Time is reached',
                'conditions' => [
                    ['field' => 'priority', 'op' => 'equals', 'value' => 'Urgent'],
                    ['field' => 'sla_pct', 'op' => 'gte', 'value' => '50'],
                    ['field' => 'status', 'op' => 'not_equals', 'value' => 'Resolved'],
                    ['field' => 'status', 'op' => 'not_equals', 'value' => 'Closed'],
                ],
                'actions' => [
                    ['type' => 'escalate', 'value' => ''],
                    ['type' => 'email_agent', 'value' => "{{ticket.id}} \"{{ticket.subject}}\" is Urgent and past half of its resolution SLA.\n\n{{ticket.agent_url}}"],
                ],
            ],
            'Auto-close resolved tickets after 5 days' => [
                'when' => 'Time is reached',
                'conditions' => [
                    ['field' => 'status', 'op' => 'equals', 'value' => 'Resolved'],
                    ['field' => 'hours_since_resolved', 'op' => 'gte', 'value' => '120'],
                ],
                'actions' => [['type' => 'set_status', 'value' => 'Closed']],
            ],
            'Flag VIP requesters' => [
                'when' => 'Ticket is created',
                'conditions' => [['field' => 'requester_email', 'op' => 'contains', 'value' => 'ceo@']],
                'actions' => [['type' => 'set_priority', 'value' => 'Urgent'], ['type' => 'escalate', 'value' => '']],
            ],
            'Nudge pending tickets' => [
                'when' => 'Time is reached',
                'conditions' => [
                    ['field' => 'status', 'op' => 'equals', 'value' => 'Pending'],
                    ['field' => 'hours_since_updated', 'op' => 'gte', 'value' => '72'],
                ],
                'actions' => [['type' => 'email_requester', 'value' => "Hi {{requester.first}},\n\nWe are waiting on a reply from you before {{ticket.id}} \"{{ticket.subject}}\" can move forward.\n\nReply here:\n{{ticket.url}}\n\n— TicketHub Service Desk"]],
            ],
        ];
        foreach ($structured as $name => $def) {
            $this->db->table('automations')->where('name', $name)->update([
                'when_event' => $def['when'],
                'conditions' => json_encode($def['conditions']),
                'actions'    => json_encode($def['actions']),
            ]);
        }

        // The formerly hard-coded SLA warning becomes a visible, editable rule.
        $this->db->table('automations')->insert([
            'name' => 'Warn assignee at 80% of resolution SLA',
            'when_event' => 'Time is reached',
            'cond' => '', 'action' => '',
            'conditions' => json_encode([
                ['field' => 'sla_pct', 'op' => 'gte', 'value' => '80'],
                ['field' => 'status', 'op' => 'not_equals', 'value' => 'Resolved'],
                ['field' => 'status', 'op' => 'not_equals', 'value' => 'Closed'],
            ]),
            'actions' => json_encode([['type' => 'email_agent', 'value' => "Heads up — {{ticket.id}} \"{{ticket.subject}}\" has used 80% of its resolution SLA.\n\nDue: {{ticket.due}}\n{{ticket.agent_url}}"]]),
            'runs' => 0, 'active' => 1,
        ]);

        // Any rule that already acted on a ticket under the old engine should not refire.
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            "INSERT IGNORE INTO automation_runs (rule_id, ticket_id, created_at)
             SELECT a.id, t.id, ? FROM automations a
             JOIN tickets t ON (
                (a.name LIKE 'Warn assignee%' AND t.sla_warned_at IS NOT NULL)
                OR (a.name LIKE 'Nudge%' AND t.nudged_at IS NOT NULL)
                OR (a.name LIKE 'Escalate%' AND t.escalated = 1)
             )",
            [$now]
        );

        $this->db->query('ALTER TABLE automations DROP COLUMN cond, DROP COLUMN action');
    }

    public function down()
    {
        $this->db->query("ALTER TABLE automations ADD COLUMN cond VARCHAR(255) NOT NULL DEFAULT '', ADD COLUMN action VARCHAR(255) NOT NULL DEFAULT ''");
        $this->db->query('ALTER TABLE automations DROP COLUMN conditions, DROP COLUMN actions');
        $this->db->query('DROP TABLE IF EXISTS automation_runs');
    }
}
