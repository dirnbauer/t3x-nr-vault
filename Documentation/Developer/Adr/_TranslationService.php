<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Acme\AcmeTranslate\Service;

use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Service\VaultServiceInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

final class TranslationService
{
    private string $apiKey;
    private string $apiEndpoint;

    public function __construct(
        private readonly VaultServiceInterface $vault,
        private readonly RequestFactory $requestFactory,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        $config = $extensionConfiguration->get('acme_translate');
        $this->apiKey = (string) ($config['apiKey'] ?? '');
        $this->apiEndpoint = (string) ($config['apiEndpoint'] ?? '');
    }

    public function translate(string $text, string $targetLang): string
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException(
                'Translation API key not configured in extension settings.',
                1735990000
            );
        }

        $request = $this->requestFactory
            ->createRequest('POST', $this->apiEndpoint . '/translate')
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\GuzzleHttp\Psr7\Utils::streamFor(json_encode([
                'text' => $text,
                'target' => $targetLang,
            ])));

        // $this->apiKey contains vault identifier, resolved at request time
        $response = $this->vault->http()
            ->withAuthentication($this->apiKey, SecretPlacement::Bearer)
            ->withReason('Translation request: ' . $targetLang)
            ->sendRequest($request);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['translation'] ?? '';
    }
}
