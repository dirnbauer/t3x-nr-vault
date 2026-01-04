<?php

return [
    'ctrl' => [
        'title' => 'API Endpoints',
        'label' => 'name',
    ],
    'columns' => [
        'name' => [
            'label' => 'Name',
            'config' => ['type' => 'input', 'required' => true],
        ],
        'url' => [
            'label' => 'API Base URL',
            'config' => ['type' => 'input', 'required' => true],
        ],
        'token' => [
            'label' => 'API Token',
            'config' => [
                'type' => 'input',
                'renderType' => 'vaultSecret',
            ],
        ],
    ],
];
