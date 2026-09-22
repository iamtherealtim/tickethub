<?php

/** Sign-in, password reset and sign-out pages. */
return [
    'login' => [
        'title'      => 'Sign in',
        'pitch'      => 'Every ticket, on the clock.',
        'pitchBody'  => 'Incidents, requests, changes, and problems in one queue — with the SLA burn always in sight, from first response to resolution.',
        'feature1'   => 'Unified queue with saved views and bulk triage',
        'feature2'   => 'SLA breach horizon across every open ticket',
        'feature3'   => 'Knowledge base that deflects repeat tickets',
        'copyright'  => '© {year} TicketHub',
        'heading'    => 'Sign in',
        'intro'      => 'Use your work email. Agents land in the workspace, everyone else in the help portal.',
        'email'      => 'Work email',
        'emailPlaceholder' => 'you@tickethub.co',
        'password'   => 'Password',
        'forgot'     => 'Forgot password?',
        'remember'   => 'Keep me signed in for 30 days',
        'submit'     => 'Sign in',
        'or'         => 'or',
        'microsoft'  => 'Sign in with Microsoft',
        'demoTitle'  => 'Demo accounts · password is “password”',
        'demoBody'   => 'Seeded demo logins only. Accounts created from Admin get a random one-time password and must set their own.',
        'demoAdmin'  => 'Agent admin',
        'demoAgent'  => 'Agent',
        'demoEmployee' => 'Employee',
    ],

    'forgot' => [
        'title'   => 'Reset password',
        'heading' => 'Forgot your password?',
        'intro'   => 'Enter your work email and we will send a one-hour reset link.',
        'email'   => 'Work email',
        'submit'  => 'Send reset link',
        'back'    => 'Back to sign in',
    ],

    'reset' => [
        'title'    => 'Choose a new password',
        'heading'  => 'Choose a new password',
        'intro'    => 'At least 8 characters. This signs out any remembered devices.',
        'password' => 'New password',
        'repeat'   => 'Repeat it',
        'submit'   => 'Set password',
    ],

    'newPassword' => [
        'title'    => 'Choose your password',
        'heading'  => 'Choose your own password',
        'intro'    => 'Your account still uses a one-time password. Pick your own to continue — at least 12 characters.',
        'current'  => 'Current (one-time) password',
        'password' => 'New password',
        'repeat'   => 'Repeat it',
        'submit'   => 'Set my password',
        'signOut'  => 'Sign out instead',
    ],

    'twoFactor' => [
        'title'    => 'Two-factor check',
        'heading'  => 'Enter your code',
        'intro'    => 'Open your authenticator app and type the 6-digit code for TicketHub, or use one of your recovery codes.',
        'code'     => 'Authenticator or recovery code',
        'submit'   => 'Verify and sign in',
        'startOver' => 'Start over',
    ],

    'logout' => [
        'title'   => 'Signing out',
        'working' => 'Signing you out…',
        'submit'  => 'Sign out',
        'hint'    => 'If nothing happens, press the button.',
    ],
];
