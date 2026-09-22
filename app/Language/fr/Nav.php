<?php

/** Habillage des gabarits : barre latérale, en-têtes, pieds de page, menu utilisateur, palette. */
return [
    'agent' => [
        'dashboard' => 'Tableau de bord',
        'tickets'   => 'Tickets',
        'problems'  => 'Problèmes',
        'changes'   => 'Changements',
        'assets'    => 'Actifs',
        'catalog'   => 'Catalogue de services',
        'kb'        => 'Base de connaissances',
        'reports'   => 'Rapports',
        'admin'     => 'Administration',
    ],
    'portal' => [
        'home'      => 'Accueil',
        'catalog'   => 'Catalogue de services',
        'kb'        => 'Base de connaissances',
        'mytickets' => 'Mes tickets',
        'help'      => 'Aide',
        'titleSuffix' => 'Aide TicketHub',
    ],

    'titleSuffix'  => 'TicketHub',
    'closeMenu'    => 'Fermer le menu',
    'openMenu'     => 'Ouvrir le menu',
    'searchPlaceholder' => 'Rechercher des tickets, personnes, actifs…',
    'pastDue'      => '{0, plural, one {# en retard} other {# en retard}}',
    'notifications' => 'Notifications',
    'notificationsUnread' => 'Notifications ({0} non lues)',
    'themeToggle'  => 'Thème',

    'menu' => [
        'security' => 'Sécurité et 2FA',
        'notificationPrefs' => 'Préférences de notification',
        'workspace'        => 'Espace de travail',
        'account'          => 'Compte',
        'signedInAs'       => 'Connecté en tant que {name} · {role}',
        'signedInAsName'   => 'Connecté en tant que {name}',
        'agentWorkspace'   => 'Espace agent',
        'agentWorkspaceSub' => 'Files, SLA, administration',
        'employeePortal'   => 'Portail employé',
        'employeePortalSub' => 'Vue libre-service',
        'backToWorkspace'  => 'Retour à l’espace agent',
        'appearance'       => 'Apparence',
        'language'         => 'Langue',
    ],

    'password' => [
        'change'    => 'Changer le mot de passe',
        'changeSub' => 'Prend effet immédiatement dans l’espace agent et le portail',
        'current'   => 'Mot de passe actuel',
        'new'       => 'Nouveau mot de passe',
        'repeat'    => 'Répétez le nouveau',
    ],

    'palette' => [
        'title'       => 'Aller à',
        'sub'         => 'Tickets, personnes, actifs, articles et pages',
        'placeholder' => 'Tapez pour rechercher…',
    ],

    'footer' => [
        'ext'    => 'Centre de services · poste 4400',
        'hours'  => 'Ouvert de 8 h à 18 h (HE), du lundi au vendredi',
        'urgent' => 'Panne urgente hors des heures d’ouverture ? Appelez la ligne de garde.',
    ],
];
