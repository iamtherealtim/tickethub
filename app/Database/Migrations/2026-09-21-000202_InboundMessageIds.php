<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Email intake had no memory of what it had already ingested: a webhook retry
 * or an overlapping Graph poll filed the same mail twice, and a reply could
 * only be threaded by a ticket code in the subject. Storing the RFC Message-ID
 * fixes both. The two settings back the new intake policies.
 */
class InboundMessageIds extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('message_id', 'ticket_messages')) {
            $this->db->query('ALTER TABLE ticket_messages
                ADD COLUMN message_id VARCHAR(255) NULL,
                ADD INDEX idx_message_id (message_id)');
        }

        $defaults = [
            'inbound_reopen_days'    => '5',      // replies to tickets resolved longer ago than this open a new ticket
            'inbound_unknown_policy' => 'create', // create | drop
        ];
        foreach ($defaults as $k => $v) {
            if (! $this->db->table('settings')->where('skey', $k)->countAllResults()) {
                $this->db->table('settings')->insert(['skey' => $k, 'svalue' => $v]);
            }
        }
    }

    public function down()
    {
        $this->db->query('ALTER TABLE ticket_messages DROP INDEX idx_message_id, DROP COLUMN message_id');
        $this->db->table('settings')->whereIn('skey', ['inbound_reopen_days', 'inbound_unknown_policy'])->delete();
    }
}
