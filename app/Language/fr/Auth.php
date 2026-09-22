<?php

/** Pages de connexion, de réinitialisation du mot de passe et de déconnexion. */
return [
    'login' => [
        'title'      => 'Connexion',
        'pitch'      => 'Chaque ticket, sous contrôle.',
        'pitchBody'  => 'Incidents, demandes, changements et problèmes dans une seule file — avec la consommation du SLA toujours visible, de la première réponse à la résolution.',
        'feature1'   => 'File unifiée avec vues enregistrées et triage en masse',
        'feature2'   => 'Horizon de dépassement SLA sur chaque ticket ouvert',
        'feature3'   => 'Base de connaissances qui évite les tickets répétitifs',
        'copyright'  => '© {year} TicketHub',
        'heading'    => 'Connexion',
        'intro'      => 'Utilisez votre courriel professionnel. Les agents arrivent dans l’espace de travail, tous les autres dans le portail d’aide.',
        'email'      => 'Courriel professionnel',
        'emailPlaceholder' => 'vous@tickethub.co',
        'password'   => 'Mot de passe',
        'forgot'     => 'Mot de passe oublié ?',
        'remember'   => 'Rester connecté pendant 30 jours',
        'submit'     => 'Se connecter',
        'or'         => 'ou',
        'microsoft'  => 'Se connecter avec Microsoft',
        'demoTitle'  => 'Comptes de démonstration · le mot de passe est « password »',
        'demoBody'   => 'Comptes de démonstration uniquement. Les comptes créés depuis l’administration reçoivent un mot de passe temporaire aléatoire et doivent en choisir un.',
        'demoAdmin'  => 'Agent administrateur',
        'demoAgent'  => 'Agent',
        'demoEmployee' => 'Employé',
    ],

    'forgot' => [
        'title'   => 'Réinitialiser le mot de passe',
        'heading' => 'Mot de passe oublié ?',
        'intro'   => 'Saisissez votre courriel professionnel et nous vous enverrons un lien valable une heure.',
        'email'   => 'Courriel professionnel',
        'submit'  => 'Envoyer le lien',
        'back'    => 'Retour à la connexion',
    ],

    'reset' => [
        'title'    => 'Choisir un nouveau mot de passe',
        'heading'  => 'Choisissez un nouveau mot de passe',
        'intro'    => 'Au moins 8 caractères. Tous les appareils mémorisés seront déconnectés.',
        'password' => 'Nouveau mot de passe',
        'repeat'   => 'Répétez-le',
        'submit'   => 'Définir le mot de passe',
    ],

    'newPassword' => [
        'title'    => 'Choisir votre mot de passe',
        'heading'  => 'Choisissez votre propre mot de passe',
        'intro'    => 'Votre compte utilise encore un mot de passe temporaire. Choisissez le vôtre pour continuer — au moins 12 caractères.',
        'current'  => 'Mot de passe actuel (temporaire)',
        'password' => 'Nouveau mot de passe',
        'repeat'   => 'Répétez-le',
        'submit'   => 'Définir mon mot de passe',
        'signOut'  => 'Me déconnecter plutôt',
    ],

    'twoFactor' => [
        'title'    => 'Vérification en deux étapes',
        'heading'  => 'Saisissez votre code',
        'intro'    => 'Ouvrez votre application d’authentification et saisissez le code à 6 chiffres pour TicketHub, ou utilisez l’un de vos codes de récupération.',
        'code'     => 'Code d’authentification ou de récupération',
        'submit'   => 'Vérifier et se connecter',
        'startOver' => 'Recommencer',
    ],

    'logout' => [
        'title'   => 'Déconnexion',
        'working' => 'Déconnexion en cours…',
        'submit'  => 'Se déconnecter',
        'hint'    => 'Si rien ne se passe, appuyez sur le bouton.',
    ],
];
