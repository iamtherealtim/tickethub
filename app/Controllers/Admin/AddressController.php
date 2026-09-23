<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\SiteAddress;

/** Admin → Address & HTTPS actions (the tab itself is agent/admin/address.tab.php). */
class AddressController extends BaseController
{
    /**
     * The internal CA's root certificate (TICKETHUB_TLS=internal), so an admin
     * can push it to client PCs. Public by nature — it is a CA certificate, the
     * private key never leaves Caddy's volume.
     */
    public function rootCa()
    {
        $pem = is_file(SiteAddress::ROOT_CA_PATH) ? (string) file_get_contents(SiteAddress::ROOT_CA_PATH) : '';
        if (! str_contains($pem, 'BEGIN CERTIFICATE')) {
            $this->toast('The internal root certificate is not available — is TICKETHUB_TLS=internal and has Caddy started?', 'warn');

            return redirect()->to('/app/admin/address');
        }

        return $this->response
            ->setHeader('Content-Type', 'application/x-x509-ca-cert')
            ->setHeader('Content-Disposition', 'attachment; filename="tickethub-root-ca.crt"')
            ->setBody($pem);
    }
}
