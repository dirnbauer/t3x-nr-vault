<?php

// Pattern A: Direct identifier usage
// Extension setting value: my_translation_api_key

$vault->http()
    ->withAuthentication($config['apiKey'], SecretPlacement::Bearer)
    ->sendRequest($request);
