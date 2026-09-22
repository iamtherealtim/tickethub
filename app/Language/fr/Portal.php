<?php

/** Portail libre-service des employés. */
return [
    'home' => [
        'title'        => 'Aide',
        'kicker'       => 'Centre de services TicketHub',
        'headline'     => 'De quoi avez-vous besoin, {name} ?',
        'searchPlaceholder' => 'Cherchez une réponse ou un service — essayez « vpn » ou « portable »',
        'searchHint'   => 'La plupart des questions sur les mots de passe et le VPN trouvent réponse en moins d’une minute, sans ouvrir de ticket.',
        'brokenTitle'  => 'Quelque chose ne fonctionne pas',
        'brokenBody'   => 'Signalez un problème et nous le prenons en charge sans attendre.',
        'requestTitle' => 'Faire une demande',
        'requestBody'  => 'Matériel, logiciels, accès et intégration.',
        'trackTitle'   => 'Suivre une demande',
        'trackBody'    => '{0, plural, =0 {Aucune demande ouverte en ce moment.} one {# demande ouverte en ce moment.} other {# demandes ouvertes en ce moment.}}',
        'notices'      => 'Avis de service',
        'yourOpen'     => 'Vos demandes ouvertes',
        'popular'      => 'Les réponses les plus consultées',
        'helpfulCount' => '{0, plural, one {# personne a trouvé cela utile} other {# personnes ont trouvé cela utile}}',
    ],

    'kb' => [
        'title'      => 'Base de connaissances',
        'intro'      => 'Des réponses pas à pas, rédigées par les personnes qui règlent ces problèmes.',
        'all'        => 'Tout',
        'stats'      => '{views} vues · {helpful} utiles',
        'emptyCatTitle'  => 'Rien dans cette catégorie',
        'emptyCatBody'   => 'Choisissez une autre catégorie ci-dessus.',
        'emptyTitle'     => 'Aucun article pour le moment',
        'emptyBody'      => 'Le centre de services n’a encore rien publié — ouvrez un ticket et nous vous aiderons.',
    ],

    'article' => [
        'back'       => 'Base de connaissances',
        'updatedBy'  => 'Mis à jour {when} par {who}',
        'solved'     => 'Cela a-t-il réglé votre problème ?',
        'yes'        => 'Oui',
        'notQuite'   => 'Pas tout à fait',
        'copyLink'   => 'Copier le lien de cet article',
        'stillStuck' => 'Toujours bloqué ? Ouvrez un ticket',
    ],

    'catalog' => [
        'title' => 'Catalogue de services',
        'intro' => 'Demandez du matériel, des logiciels ou des accès. Chaque élément indique le délai et qui doit l’approuver.',
    ],

    'mytickets' => [
        'title'     => 'Mes tickets',
        'intro'     => 'Tout ce que vous avez soumis, et où ça en est.',
        'open'      => 'Ouverts ({0})',
        'resolved'  => 'Résolus ({0})',
        'emptyTitle' => 'Rien pour le moment',
        'emptyBody'  => 'Vos demandes apparaîtront ici avec chacune de leurs mises à jour.',
    ],

    'search' => [
        'answers'   => 'Réponses',
        'services'  => 'Services',
        'noAnswer'  => 'Aucune réponse pour « {q} ».',
        'raiseInstead' => 'Ouvrez plutôt un ticket',
    ],

    'row' => [
        'opened'     => 'ouvert {0}',
        'with'       => 'avec {0}',
        'needsReply' => 'Votre réponse est attendue',
    ],
];
