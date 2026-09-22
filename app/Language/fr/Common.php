<?php

/**
 * Chaînes partagées dans toute l’application : boutons, libellés et noms affichés
 * des vocabulaires fixes (statut, priorité, type…). Les vues traduisent une valeur
 * stockée avec th_t('Common.status.' . $status, $status) : une valeur inconnue
 * s’affiche telle quelle au lieu de laisser fuir une clé.
 */
return [
    'app'     => 'TicketHub',
    'tagline' => 'Centre de services',
    'version' => 'TicketHub v{0}',

    'status' => [
        'New'      => 'Nouveau',
        'Open'     => 'Ouvert',
        'Pending'  => 'En attente',
        'Resolved' => 'Résolu',
        'Closed'   => 'Fermé',
    ],
    'priority' => [
        'Urgent' => 'Urgente',
        'High'   => 'Haute',
        'Medium' => 'Moyenne',
        'Low'    => 'Basse',
    ],
    'type' => [
        'Incident'        => 'Incident',
        'Service request' => 'Demande de service',
        'Problem'         => 'Problème',
        'Change'          => 'Changement',
    ],
    'changeState' => [
        'Awaiting approval' => 'En attente d’approbation',
        'Scheduled'         => 'Planifié',
        'In progress'       => 'En cours',
        'Completed'         => 'Terminé',
        'Rejected'          => 'Refusé',
        'Cancelled'         => 'Annulé',
    ],
    'problemStatus' => [
        'Root cause identified' => 'Cause racine identifiée',
        'Under investigation'   => 'En cours d’analyse',
        'Resolved'              => 'Résolu',
        'Known error'           => 'Erreur connue',
    ],
    'assetStatus' => [
        'In use'       => 'En service',
        'In stock'     => 'En stock',
        'Needs repair' => 'À réparer',
        'Retired'      => 'Retiré',
    ],
    'role' => [
        'Administrator' => 'Administrateur',
        'Supervisor'    => 'Superviseur',
        'Agent'         => 'Agent',
        'Requester'     => 'Demandeur',
    ],
    'level' => [
        'info'  => 'Information',
        'warn'  => 'Avertissement',
        'alert' => 'Urgent',
    ],

    'btn' => [
        'save'     => 'Enregistrer',
        'cancel'   => 'Annuler',
        'post'     => 'Publier',
        'edit'     => 'Modifier',
        'remove'   => 'Supprimer',
        'refresh'  => 'Actualiser',
        'yes'      => 'Oui',
        'no'       => 'Non',
        'share'    => 'Partager',
        'seeAll'   => 'Tout voir',
        'close'    => 'Fermer',
        'signOut'  => 'Se déconnecter',
        'signIn'   => 'Se connecter',
        'back'     => 'Retour',
        'submit'   => 'Envoyer',
        'newTicket'  => 'Nouveau ticket',
        'newRequest' => 'Nouvelle demande',
        'raiseTicket' => 'Ouvrir un ticket',
    ],

    'label' => [
        'required' => 'obligatoire',
        'optional' => 'facultatif',
        'none'     => '—',
        'by'       => 'par',
        'with'     => 'avec',
        'search'   => 'Rechercher',
        'email'    => 'Courriel',
        'password' => 'Mot de passe',
        'name'     => 'Nom',
        'language' => 'Langue',
        'theme'    => 'Thème',
    ],

    'theme' => [
        'system' => 'Système',
        'light'  => 'Clair',
        'dark'   => 'Sombre',
        'toggle' => 'Changer de thème (actuel : {0})',
    ],

    'lang' => [
        'en' => 'English',
        'fr' => 'Français',
    ],

    'empty' => [
        'nothingHere'   => 'Rien pour le moment',
        'nothingPosted' => 'Aucune publication pour l’instant.',
        'noResults'     => 'Aucun résultat',
    ],

    'time' => [
        'opened'  => 'ouvert {0}',
        'updated' => 'Mis à jour {0}',
        'expires' => 'expire le {0}',
    ],
];
