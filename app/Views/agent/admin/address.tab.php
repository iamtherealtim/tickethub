<?php

use App\Libraries\SiteAddress;

/** Admin → Address & HTTPS tab. */
return [
    'label' => 'Address & HTTPS',
    'icon'  => 'lock',
    'order' => 105,
    'data'  => static function ($request, $me): array {
        $state = SiteAddress::gather($request);
        $cert  = SiteAddress::certificate($request->getGet('recheck') === null);

        return [
            'addrState'   => $state,
            'addrCert'    => $cert,
            'addrIssues'  => SiteAddress::check($state, $cert),
            'addrLinks'   => SiteAddress::callbacks(),
            'addrRootCa'  => is_file(SiteAddress::ROOT_CA_PATH),
        ];
    },
];
