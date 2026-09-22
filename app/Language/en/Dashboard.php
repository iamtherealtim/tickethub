<?php

/** Agent dashboard. */
return [
    'title' => 'Dashboard',

    'greeting' => [
        'morning'   => 'Good morning',
        'afternoon' => 'Good afternoon',
        'evening'   => 'Good evening',
    ],

    'scope' => [
        'groups' => '{0, plural, one {# group} other {# groups}}',
        'queue'  => 'the {0} queue',
        'line'   => '{date} · {count, plural, one {# ticket open in {scope}} other {# tickets open in {scope}}}',
    ],

    'btn' => [
        'refresh'   => 'Refresh',
        'newTicket' => 'New ticket',
        'post'      => 'Post',
        'save'      => 'Save',
        'edit'      => 'Edit',
        'remove'    => 'Remove',
    ],

    'kpi' => [
        'unassigned'    => 'Unassigned',
        'unassignedSub' => 'Waiting for an owner',
        'open'          => 'Open',
        'openSub'       => '{0} raised today',
        'due4h'         => 'Due within 4h',
        'due4hSub'      => 'Resolution SLA',
        'pastDue'       => 'Past due',
        'breached'      => 'Breached resolution SLA',
        'allWithin'     => 'All within target',
        'atRisk'        => '{0} at risk',
        'resolved24h'   => 'Resolved 24h',
        'noResolved'    => 'No resolved tickets yet',
        'fcr'           => 'First-contact rate {0}%',
    ],

    'horizon' => [
        'title'    => 'Breach horizon',
        'summary'  => '{0} due in 12h',
        'pastDue'  => '{0} past due',
        'beyond'   => '{0} beyond',
        'now'      => 'now',
        'plusH'    => '+{0}h',
        'dueIn'    => 'due in {0}',
        'nothing'  => 'Nothing due in the next 12 hours',
        'seeAll'   => 'See all {0} past due',
    ],

    'volume' => [
        'title'    => 'Ticket volume',
        'created'  => 'Created',
        'resolved' => 'Resolved',
        'createdN'  => '{0} created',
        'resolvedN' => '{0} resolved',
    ],

    'workload' => [
        'title' => 'Agent workload',
        'sub'   => 'Open by assignee',
        'late'  => '{0} late',
    ],

    'queue' => [
        'title'       => 'My queue',
        'allTickets'  => 'All tickets',
        'emptyMine'   => 'Nothing assigned to you. Take one from the unassigned pool below.',
        'emptyPool'   => 'The pool is empty.',
        'unassigned'  => 'Unassigned · {0}',
        'claim'       => 'Claim work',
    ],

    'csat' => [
        'title'     => 'Satisfaction',
        'responses' => '{0, plural, one {# response} other {# responses}}',
        'outOf'     => '/ 5',
    ],

    'ann' => [
        'title'        => 'Announcements',
        'empty'        => 'Nothing posted right now.',
        'expires'      => 'expires {0}',
        'edit'         => 'Edit',
        'remove'       => 'Remove',
        'confirmRemove' => 'Remove this announcement for everyone?',
        'newTitle'     => 'Post an announcement',
        'editTitle'    => 'Edit announcement',
        'headline'     => 'Headline',
        'severity'     => 'Severity',
        'expiresLabel' => 'Expires',
        'expiresHint'  => 'Optional — hidden after this day.',
        'expiresKeep'  => 'Clear it to keep the notice until removed.',
        'message'      => 'Message',
        'messagePlaceholder' => 'What is happening, who it affects, and what people should do.',
    ],
];
