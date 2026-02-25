<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Pattern A: Direct identifier usage
// Extension setting value: my_translation_api_key

$vault->http()
    ->withAuthentication($config['apiKey'], SecretPlacement::Bearer)
    ->sendRequest($request);
