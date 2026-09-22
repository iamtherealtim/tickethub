<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddEmailAndSso extends Migration
{
    public function up()
    {
        $this->db->query(
            "CREATE TABLE settings (
                skey VARCHAR(64) NOT NULL PRIMARY KEY,
                svalue TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->query("ALTER TABLE email_templates ADD COLUMN body TEXT NULL, ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1");

        // Default bodies for the seeded templates (placeholders resolved by Mailer).
        $bodies = [
            'Ticket created' => "Hi {{requester.first}},\n\nWe have logged your request as {{ticket.id}} — \"{{ticket.subject}}\".\n\nThe service desk aims to resolve it by {{ticket.due}}. You can follow progress or add detail any time:\n{{ticket.url}}\n\n— TicketHub Service Desk",
            'Public reply sent' => "Hi {{requester.first}},\n\n{{agent.name}} replied to {{ticket.id}} — \"{{ticket.subject}}\":\n\n{{message}}\n\nReply or add detail here:\n{{ticket.url}}\n\n— TicketHub Service Desk",
            'Status → Resolved' => "Hi {{requester.first}},\n\n{{ticket.id}} — \"{{ticket.subject}}\" has been marked resolved.\n\nIf everything works, no action is needed. If not, replying on the ticket within five days reopens it:\n{{ticket.url}}\n\nHow did we do? Rate the fix on the ticket page.\n\n— TicketHub Service Desk",
            'Ticket assigned' => "Hi {{agent.first}},\n\n{{ticket.id}} — \"{{ticket.subject}}\" ({{ticket.priority}}) is now assigned to you.\n\nRequester: {{requester.name}}\nResolution due: {{ticket.due}}\n\nOpen it:\n{{ticket.agent_url}}\n\n— TicketHub",
            '80% of SLA' => "Heads up — {{ticket.id}} \"{{ticket.subject}}\" has used 80% of its resolution SLA.\n\nDue: {{ticket.due}}\n{{ticket.agent_url}}",
        ];
        foreach ($bodies as $trigger => $body) {
            $this->db->table('email_templates')->where('trigger_event', $trigger)->update(['body' => $body]);
        }

        $defaults = [
            'mail_enabled'       => '0',
            'mail_host'          => '',
            'mail_port'          => '587',
            'mail_username'      => '',
            'mail_password'      => '',
            'mail_encryption'    => 'tls',
            'mail_from_email'    => 'servicedesk@tickethub.co',
            'mail_from_name'     => 'TicketHub Service Desk',
            'azure_enabled'      => '0',
            'azure_tenant_id'    => '',
            'azure_client_id'    => '',
            'azure_client_secret' => '',
            'azure_autoprovision' => '1',
        ];
        foreach ($defaults as $k => $v) {
            $this->db->table('settings')->insert(['skey' => $k, 'svalue' => $v]);
        }
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS settings');
        $this->db->query('ALTER TABLE email_templates DROP COLUMN body, DROP COLUMN active');
    }
}
