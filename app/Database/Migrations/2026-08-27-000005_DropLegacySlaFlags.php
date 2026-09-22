<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * sla_warned_at and nudged_at were the original "has this rule fired?" flags.
 * AutomationEngineV2 replaced them with automation_runs rows and converted the
 * existing data, after which nothing read either column. Removing them so the
 * schema stops implying a mechanism that is not there.
 */
class DropLegacySlaFlags extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE tickets DROP COLUMN sla_warned_at, DROP COLUMN nudged_at');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE tickets
            ADD COLUMN sla_warned_at DATETIME NULL,
            ADD COLUMN nudged_at DATETIME NULL');
    }
}
