<?php

/**
 * Strings shared across the whole app: buttons, labels, and the display names of
 * the fixed vocabularies (status, priority, type, ...). Views translate a stored
 * value with th_t('Common.status.' . $status, $status) so an unknown value still
 * renders as-is instead of leaking a key.
 */
return [
    'app'     => 'TicketHub',
    'tagline' => 'Service desk',
    'version' => 'TicketHub v{0}',

    'status' => [
        'New'      => 'New',
        'Open'     => 'Open',
        'Pending'  => 'Pending',
        'Resolved' => 'Resolved',
        'Closed'   => 'Closed',
    ],
    'priority' => [
        'Urgent' => 'Urgent',
        'High'   => 'High',
        'Medium' => 'Medium',
        'Low'    => 'Low',
    ],
    'type' => [
        'Incident'        => 'Incident',
        'Service request' => 'Service request',
        'Problem'         => 'Problem',
        'Change'          => 'Change',
    ],
    'changeState' => [
        'Awaiting approval' => 'Awaiting approval',
        'Scheduled'         => 'Scheduled',
        'In progress'       => 'In progress',
        'Completed'         => 'Completed',
        'Rejected'          => 'Rejected',
        'Cancelled'         => 'Cancelled',
    ],
    'problemStatus' => [
        'Root cause identified' => 'Root cause identified',
        'Under investigation'   => 'Under investigation',
        'Resolved'              => 'Resolved',
        'Known error'           => 'Known error',
    ],
    'assetStatus' => [
        'In use'       => 'In use',
        'In stock'     => 'In stock',
        'Needs repair' => 'Needs repair',
        'Retired'      => 'Retired',
    ],
    'role' => [
        'Administrator' => 'Administrator',
        'Supervisor'    => 'Supervisor',
        'Agent'         => 'Agent',
        'Requester'     => 'Requester',
    ],
    'level' => [
        'info'  => 'Information',
        'warn'  => 'Warning',
        'alert' => 'Urgent',
    ],

    'btn' => [
        'save'     => 'Save',
        'cancel'   => 'Cancel',
        'post'     => 'Post',
        'edit'     => 'Edit',
        'remove'   => 'Remove',
        'refresh'  => 'Refresh',
        'yes'      => 'Yes',
        'no'       => 'No',
        'share'    => 'Share',
        'seeAll'   => 'See all',
        'close'    => 'Close',
        'signOut'  => 'Sign out',
        'signIn'   => 'Sign in',
        'back'     => 'Back',
        'submit'   => 'Submit',
        'newTicket'  => 'New ticket',
        'newRequest' => 'New request',
        'raiseTicket' => 'Raise a ticket',
    ],

    'label' => [
        'required' => 'required',
        'optional' => 'optional',
        'none'     => '—',
        'by'       => 'by',
        'with'     => 'with',
        'search'   => 'Search',
        'email'    => 'Email',
        'password' => 'Password',
        'name'     => 'Name',
        'language' => 'Language',
        'theme'    => 'Theme',
    ],

    'theme' => [
        'system' => 'System',
        'light'  => 'Light',
        'dark'   => 'Dark',
        'toggle' => 'Switch theme (current: {0})',
    ],

    'lang' => [
        'en' => 'English',
        'fr' => 'Français',
    ],

    'empty' => [
        'nothingHere'   => 'Nothing here yet',
        'nothingPosted' => 'Nothing posted right now.',
        'noResults'     => 'No results',
    ],

    'time' => [
        'opened'  => 'opened {0}',
        'updated' => 'Updated {0}',
        'expires' => 'expires {0}',
    ],
];
