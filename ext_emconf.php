<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Vault - Secure Secrets Management',
    'description' => 'Centralized, secure storage for API keys, credentials, and other secrets with envelope encryption, access control, audit logging, and a secure HTTP client.',
    'category' => 'be',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'info@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'beta',
    'version' => '0.3.1',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'php' => '8.5.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
