<?php

/** Layout chrome: sidebar, headers, footers, user menu, command palette. */
return [
    'agent' => [
        'dashboard' => 'Dashboard',
        'tickets'   => 'Tickets',
        'problems'  => 'Problems',
        'changes'   => 'Changes',
        'assets'    => 'Assets',
        'catalog'   => 'Service catalog',
        'kb'        => 'Knowledge',
        'reports'   => 'Reports',
        'admin'     => 'Admin',
    ],
    'portal' => [
        'home'      => 'Home',
        'catalog'   => 'Service catalog',
        'kb'        => 'Knowledge',
        'mytickets' => 'My tickets',
        'help'      => 'Help',
        'titleSuffix' => 'TicketHub Help',
    ],

    'titleSuffix'  => 'TicketHub',
    'closeMenu'    => 'Close menu',
    'openMenu'     => 'Open menu',
    'searchPlaceholder' => 'Search tickets, people, assets…',
    'pastDue'      => '{0, plural, one {# past due} other {# past due}}',
    'notifications' => 'Notifications',
    'notificationsUnread' => 'Notifications ({0} unread)',
    'themeToggle'  => 'Theme',

    'menu' => [
        'security' => 'Security & 2FA',
        'notificationPrefs' => 'Notification preferences',
        'workspace'        => 'Workspace',
        'account'          => 'Account',
        'signedInAs'       => 'Signed in as {name} · {role}',
        'signedInAsName'   => 'Signed in as {name}',
        'agentWorkspace'   => 'Agent workspace',
        'agentWorkspaceSub' => 'Queues, SLAs, admin',
        'employeePortal'   => 'Employee portal',
        'employeePortalSub' => 'Self-service view',
        'backToWorkspace'  => 'Back to the agent workspace',
        'appearance'       => 'Appearance',
        'language'         => 'Language',
    ],

    'password' => [
        'change'    => 'Change password',
        'changeSub' => 'Applies immediately across the workspace and portal',
        'current'   => 'Current password',
        'new'       => 'New password',
        'repeat'    => 'Repeat the new one',
    ],

    'palette' => [
        'title'       => 'Jump to',
        'sub'         => 'Tickets, people, assets, articles and pages',
        'placeholder' => 'Type to search…',
    ],

    'footer' => [
        'ext'    => 'Service desk · ext. 4400',
        'hours'  => 'Open 08:00–18:00 ET, Mon–Fri',
        'urgent' => 'Urgent outage outside hours? Call the on-call line.',
    ],
];
