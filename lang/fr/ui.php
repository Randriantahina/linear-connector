<?php

declare(strict_types=1);

return [
    'auth' => [
        'api_key_label' => 'Clé API personnelle Linear',
        'api_key_field' => 'Clé API personnelle',
        'oauth_connect' => 'Se connecter avec Linear',
    ],
    'objects' => [
        'issue' => 'Tickets Linear',
        'project' => 'Projets Linear',
    ],
    'fields' => [
        'issue' => [
            'identifier' => 'Identifiant',
            'title' => 'Titre',
            'description' => 'Description',
            'state' => 'Statut',
            'state_type' => 'Type de statut',
            'priority' => 'Priorité',
            'priority_label' => 'Libellé de priorité',
            'assignee' => 'Assigné à',
            'assignee_email' => 'Courriel de l’assigné',
            'creator' => 'Créateur',
            'team' => 'Clé d’équipe',
            'team_name' => 'Équipe',
            'project' => 'Projet',
            'labels' => 'Étiquettes',
            'due_date' => 'Échéance',
            'created_at' => 'Créé le',
            'updated_at' => 'Modifié le',
            'completed_at' => 'Terminé le',
            'url' => 'Lien',
        ],
        'project' => [
            'name' => 'Nom',
            'description' => 'Description',
            'state' => 'Statut',
            'progress' => 'Avancement',
            'lead' => 'Responsable',
            'start_date' => 'Date de début',
            'target_date' => 'Date cible',
            'created_at' => 'Créé le',
            'updated_at' => 'Modifié le',
            'url' => 'Lien',
        ],
    ],
];
