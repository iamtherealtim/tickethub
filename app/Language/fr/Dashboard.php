<?php

/** Tableau de bord des agents. */
return [
    'title' => 'Tableau de bord',

    'greeting' => [
        'morning'   => 'Bonjour',
        'afternoon' => 'Bon après-midi',
        'evening'   => 'Bonsoir',
    ],

    'scope' => [
        'groups' => '{0, plural, one {# groupe} other {# groupes}}',
        'queue'  => 'la file {0}',
        'line'   => '{date} · {count, plural, one {# ticket ouvert dans {scope}} other {# tickets ouverts dans {scope}}}',
    ],

    'btn' => [
        'refresh'   => 'Actualiser',
        'newTicket' => 'Nouveau ticket',
        'post'      => 'Publier',
        'save'      => 'Enregistrer',
        'edit'      => 'Modifier',
        'remove'    => 'Supprimer',
    ],

    'kpi' => [
        'unassigned'    => 'Non assignés',
        'unassignedSub' => 'En attente d’un responsable',
        'open'          => 'Ouverts',
        'openSub'       => '{0} créés aujourd’hui',
        'due4h'         => 'Échéance sous 4 h',
        'due4hSub'      => 'SLA de résolution',
        'pastDue'       => 'En retard',
        'breached'      => 'SLA de résolution dépassé',
        'allWithin'     => 'Tous dans les délais',
        'atRisk'        => '{0} à risque',
        'resolved24h'   => 'Résolus (24 h)',
        'noResolved'    => 'Aucun ticket résolu pour le moment',
        'fcr'           => 'Taux de résolution au premier contact : {0} %',
    ],

    'horizon' => [
        'title'    => 'Horizon de dépassement',
        'summary'  => '{0} à échéance sous 12 h',
        'pastDue'  => '{0} en retard',
        'beyond'   => '{0} au-delà',
        'now'      => 'maintenant',
        'plusH'    => '+{0} h',
        'dueIn'    => 'échéance dans {0}',
        'nothing'  => 'Aucune échéance dans les 12 prochaines heures',
        'seeAll'   => 'Voir les {0} tickets en retard',
    ],

    'volume' => [
        'title'    => 'Volume de tickets',
        'created'  => 'Créés',
        'resolved' => 'Résolus',
        'createdN'  => '{0} créés',
        'resolvedN' => '{0} résolus',
    ],

    'workload' => [
        'title' => 'Charge des agents',
        'sub'   => 'Ouverts par responsable',
        'late'  => '{0} en retard',
    ],

    'queue' => [
        'title'       => 'Ma file',
        'allTickets'  => 'Tous les tickets',
        'emptyMine'   => 'Rien ne vous est assigné. Prenez un ticket dans la réserve ci-dessous.',
        'emptyPool'   => 'La réserve est vide.',
        'unassigned'  => 'Non assignés · {0}',
        'claim'       => 'Prendre un ticket',
    ],

    'csat' => [
        'title'     => 'Satisfaction',
        'responses' => '{0, plural, one {# réponse} other {# réponses}}',
        'outOf'     => '/ 5',
    ],

    'ann' => [
        'title'        => 'Annonces',
        'empty'        => 'Aucune annonce pour l’instant.',
        'expires'      => 'expire le {0}',
        'edit'         => 'Modifier',
        'remove'       => 'Supprimer',
        'confirmRemove' => 'Supprimer cette annonce pour tout le monde ?',
        'newTitle'     => 'Publier une annonce',
        'editTitle'    => 'Modifier l’annonce',
        'headline'     => 'Titre',
        'severity'     => 'Gravité',
        'expiresLabel' => 'Expire le',
        'expiresHint'  => 'Facultatif — masquée après cette date.',
        'expiresKeep'  => 'Laissez vide pour garder l’annonce jusqu’à sa suppression.',
        'message'      => 'Message',
        'messagePlaceholder' => 'Ce qui se passe, qui est concerné, et ce que les gens doivent faire.',
    ],
];
