<?php

declare(strict_types=1);

return [
    'auth' => [
        'api_key_label' => 'Linear personal API key',
        'api_key_field' => 'Personal API key',
        'oauth_connect' => 'Connect with Linear',
    ],
    'objects' => [
        'issue' => 'Linear issues',
        'project' => 'Linear projects',
    ],
    'fields' => [
        'issue' => [
            'identifier' => 'Identifier',
            'title' => 'Title',
            'description' => 'Description',
            'state' => 'Status',
            'state_type' => 'Status type',
            'priority' => 'Priority',
            'priority_label' => 'Priority label',
            'assignee' => 'Assignee',
            'assignee_email' => 'Assignee email',
            'creator' => 'Creator',
            'team' => 'Team key',
            'team_name' => 'Team',
            'project' => 'Project',
            'labels' => 'Labels',
            'due_date' => 'Due date',
            'created_at' => 'Created at',
            'updated_at' => 'Updated at',
            'completed_at' => 'Completed at',
            'url' => 'Link',
        ],
        'project' => [
            'name' => 'Name',
            'description' => 'Description',
            'state' => 'Status',
            'progress' => 'Progress',
            'lead' => 'Lead',
            'start_date' => 'Start date',
            'target_date' => 'Target date',
            'created_at' => 'Created at',
            'updated_at' => 'Updated at',
            'url' => 'Link',
        ],
    ],
];
