<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class PdqIntegration extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE assets
            ADD COLUMN pdq_device_id VARCHAR(64) NULL,
            ADD COLUMN pdq_synced_at DATETIME NULL,
            ADD UNIQUE INDEX uq_pdq_device (pdq_device_id)');

        foreach ([
            'pdq_enabled'   => '0',
            'pdq_api_key'   => '',
            'pdq_base_url'  => 'https://app.pdq.com/v1/api',
            'pdq_auto_sync' => '0',
            'pdq_last_sync' => '',
        ] as $k => $v) {
            $this->db->table('settings')->insert(['skey' => $k, 'svalue' => $v]);
        }
    }

    public function down()
    {
        $this->db->query('ALTER TABLE assets DROP INDEX uq_pdq_device, DROP COLUMN pdq_device_id, DROP COLUMN pdq_synced_at');
        $this->db->table('settings')->whereIn('skey', ['pdq_enabled', 'pdq_api_key', 'pdq_base_url', 'pdq_auto_sync', 'pdq_last_sync'])->delete();
    }
}
