<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * users.sso_subject — the identity provider's own stable id for the person
 * ("oidc|<issuer>|<sub>" or "saml|<idp entity>|<NameID>"), bound on first
 * sign-in. Once set, a later sign-in that asserts the same email but a
 * different subject is refused, so an account cannot be taken over by an
 * IdP account that merely claims the same address.
 */
class SsoSubject extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('sso_subject', 'users')) {
            $this->db->query('ALTER TABLE users ADD COLUMN sso_subject VARCHAR(255) NULL, ADD INDEX idx_sso_subject (sso_subject)');
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('sso_subject', 'users')) {
            $this->db->query('ALTER TABLE users DROP INDEX idx_sso_subject, DROP COLUMN sso_subject');
        }
    }
}
