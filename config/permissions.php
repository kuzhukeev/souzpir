<?php

// Fixed v1 roles. A membership with role=member and sales_manager=true is a manager.
// Company deletion and custom/module roles are deliberately absent.
return [
    'labels' => [
        'owner' => 'Владелец', 'admin' => 'Администратор', 'editor' => 'Редактор',
        'manager' => 'Менеджер', 'viewer' => 'Наблюдатель',
    ],
    'abilities' => [
        'org.read' => ['owner', 'admin', 'editor', 'manager', 'viewer'],
        'org.settings.write' => ['owner', 'admin'],
        'org.owner.transfer' => ['owner'],
        'org.billing' => ['owner'],
        'billing.read' => ['owner', 'admin', 'viewer'],
        'team.read' => ['owner', 'admin', 'viewer'],
        'team.write' => ['owner', 'admin'],
        'brand.read' => ['owner', 'admin', 'editor', 'manager', 'viewer'],
        'brand.write' => ['owner', 'admin'],
        'integrations.read' => ['owner', 'admin', 'viewer'],
        'integrations.write' => ['owner', 'admin'],
        'seller.dialogs.read' => ['owner', 'admin', 'editor', 'manager', 'viewer'],
        'seller.dialogs.write' => ['owner', 'admin', 'editor', 'manager'],
        'seller.customers.read' => ['owner', 'admin', 'editor', 'manager', 'viewer'],
        'seller.customers.write' => ['owner', 'admin', 'editor', 'manager'],
        'seller.suggestions.read' => ['owner', 'admin', 'editor', 'manager', 'viewer'],
        'seller.suggestions.write' => ['owner', 'admin', 'editor', 'manager'],
        'seller.knowledge.read' => ['owner', 'admin', 'editor', 'viewer'],
        'seller.knowledge.write' => ['owner', 'admin', 'editor'],
        'seller.settings.read' => ['owner', 'admin', 'editor', 'viewer'],
        'seller.settings.write' => ['owner', 'admin', 'editor'],
        'vision.report.read' => ['owner', 'admin', 'editor', 'manager', 'viewer'],
        'vision.measure.read' => ['owner', 'admin', 'editor', 'viewer'],
        'vision.measure.run' => ['owner', 'admin', 'editor'],
        'vision.settings.read' => ['owner', 'admin', 'editor', 'viewer'],
        'vision.settings.write' => ['owner', 'admin', 'editor'],
        'data.export' => ['owner'],
        'profile.own' => ['owner', 'admin', 'editor', 'manager', 'viewer'],
    ],
];
