<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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
