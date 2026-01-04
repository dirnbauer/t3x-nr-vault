<?php

// Pattern B: Prefixed reference usage
// Extension setting value: vault:my_translation_api_key

$ref = VaultReference::tryParse($config['apiKey']);
if ($ref !== null) {
    $vault->http()
        ->withAuthentication($ref->identifier, SecretPlacement::Bearer)
        ->sendRequest($request);
}
