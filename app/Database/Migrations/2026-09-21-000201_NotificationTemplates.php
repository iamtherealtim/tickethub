<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Three triggers the code has fired for a while ("Request approved",
 * "Request rejected") or promised in its toast ("Escalated — supervisor
 * notified") without a template row behind them, so nothing ever left.
 */
class NotificationTemplates extends Migration
{
    private const ROWS = [
        [
            'name' => 'Request approved', 'trigger_event' => 'Request approved', 'recipient' => 'Requester',
            'subject' => '{{ticket.id}} approved — work starts now',
            'body' => "Hi {{requester.first}},\n\nYour request {{ticket.id}} — \"{{ticket.subject}}\" has been approved and is now with the service desk.\n\nResolution due: {{ticket.due}}\nFollow progress here:\n{{ticket.url}}\n\n— TicketHub Service Desk",
        ],
        [
            'name' => 'Request rejected', 'trigger_event' => 'Request rejected', 'recipient' => 'Requester',
            'subject' => '{{ticket.id}} was not approved',
            'body' => "Hi {{requester.first}},\n\nYour request {{ticket.id}} — \"{{ticket.subject}}\" was not approved and has been closed.\n\n{{message}}\n\nIf you think this is a mistake, reply on the ticket and the service desk will pick it up:\n{{ticket.url}}\n\n— TicketHub Service Desk",
        ],
        [
            'name' => 'Escalation notice', 'trigger_event' => 'Ticket escalated', 'recipient' => 'Group',
            'subject' => 'Escalated — {{ticket.id}} ({{ticket.priority}})',
            'body' => "{{ticket.id}} — \"{{ticket.subject}}\" has been escalated to {{ticket.priority}} priority.\n\nRequester: {{requester.name}}\nAssignee: {{agent.name}}\nResolution due: {{ticket.due}}\n\n{{message}}\n\nOpen it:\n{{ticket.agent_url}}\n\n— TicketHub",
        ],
    ];

    public function up()
    {
        foreach (self::ROWS as $row) {
            $exists = $this->db->table('email_templates')->where('trigger_event', $row['trigger_event'])->countAllResults();
            if (! $exists) {
                $this->db->table('email_templates')->insert($row + ['active' => 1]);
            }
        }
    }

    public function down()
    {
        $this->db->table('email_templates')->whereIn('trigger_event', array_column(self::ROWS, 'trigger_event'))->delete();
    }
}
