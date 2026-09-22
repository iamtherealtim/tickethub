<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AuthHardening extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE users
            ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN remember_expires DATETIME NULL,
            ADD COLUMN azure_oid VARCHAR(64) NULL,
            ADD UNIQUE INDEX uq_azure_oid (azure_oid)');

        // SSO must not accept arbitrary Microsoft tenants: turn it off unless a
        // concrete tenant is configured, and stop auto-creating accounts by default.
        $tenant = $this->db->table('settings')->where('skey', 'azure_tenant_id')->get()->getRowArray();
        if (! $tenant || trim((string) $tenant['svalue']) === '') {
            $this->db->table('settings')->where('skey', 'azure_enabled')->update(['svalue' => '0']);
        }
        $this->db->table('settings')->where('skey', 'azure_autoprovision')->update(['svalue' => '0']);

        /*
         * Real deployments should force a change on accounts still holding the
         * shared demo password. The seeded demo accounts are deliberately left
         * alone so the documented demo logins keep working on a dev instance —
         * flip this to `1` (or run the UPDATE by hand) before going live.
         */
        // $this->db->query('UPDATE users SET must_change_password = 1');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE users DROP INDEX uq_azure_oid, DROP COLUMN must_change_password, DROP COLUMN remember_expires, DROP COLUMN azure_oid');
    }
}
