<?php

/** Employee self-service portal. */
return [
    'home' => [
        'title'        => 'Help',
        'kicker'       => 'TicketHub service desk',
        'headline'     => 'What do you need help with, {name}?',
        'searchPlaceholder' => 'Search answers and services — try “vpn” or “laptop”',
        'searchHint'   => 'Most password and VPN questions are answered in under a minute without raising a ticket.',
        'brokenTitle'  => 'Something is broken',
        'brokenBody'   => 'Report a problem and we will pick it up straight away.',
        'requestTitle' => 'Request something',
        'requestBody'  => 'Hardware, software, access, and onboarding.',
        'trackTitle'   => 'Track a request',
        'trackBody'    => '{0, plural, =0 {Nothing open right now.} one {# open right now.} other {# open right now.}}',
        'notices'      => 'Service notices',
        'yourOpen'     => 'Your open requests',
        'popular'      => 'Answers people use most',
        'helpfulCount' => '{0, plural, one {# person found this helpful} other {# people found this helpful}}',
    ],

    'kb' => [
        'title'      => 'Knowledge base',
        'intro'      => 'Step-by-step answers, written by the people who fix these things.',
        'all'        => 'All',
        'stats'      => '{views} views · {helpful} helpful',
        'emptyCatTitle'  => 'Nothing in this category',
        'emptyCatBody'   => 'Pick another category above.',
        'emptyTitle'     => 'No articles yet',
        'emptyBody'      => 'The service desk has not published any answers yet — raise a ticket and we will help.',
    ],

    'article' => [
        'back'       => 'Knowledge base',
        'updatedBy'  => 'Updated {when} by {who}',
        'solved'     => 'Did this solve it?',
        'yes'        => 'Yes',
        'notQuite'   => 'Not quite',
        'copyLink'   => 'Copy a link to this article',
        'stillStuck' => 'Still stuck? Raise a ticket',
    ],

    'catalog' => [
        'title' => 'Service catalog',
        'intro' => 'Request equipment, software, and access. Each item shows how long it takes and who needs to approve it.',
    ],

    'mytickets' => [
        'title'     => 'My tickets',
        'intro'     => 'Everything you have raised, and where it stands.',
        'open'      => 'Open ({0})',
        'resolved'  => 'Resolved ({0})',
        'emptyTitle' => 'Nothing here yet',
        'emptyBody'  => 'When you raise a request it will show up here with every update.',
    ],

    'search' => [
        'answers'   => 'Answers',
        'services'  => 'Services',
        'noAnswer'  => 'No answer for “{q}”.',
        'raiseInstead' => 'Raise a ticket instead',
    ],

    'row' => [
        'opened'     => 'opened {0}',
        'with'       => 'with {0}',
        'needsReply' => 'Needs your reply',
    ],
];
