<?php

declare(strict_types=1);

namespace MyVendor\MyDeeplExtension\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

final class DeepLService
{
    private const API_URL = 'https://api-free.deepl.com/v2';

    private string $apiKey;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        $config = $extensionConfiguration->get('my_deepl_extension');
        $envVar = (string) ($config['deeplApiKeyEnvVar'] ?? 'DEEPL_API_KEY');
        $this->apiKey = getenv($envVar) ?: '';
    }

    public function translate(string $text, string $targetLang): string
    {
        // Use TYPO3's RequestFactory directly with env-provided key
        $response = $this->requestFactory->request(
            self::API_URL . '/translate',
            'POST',
            [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'text' => [$text],
                    'target_lang' => $targetLang,
                ]),
            ]
        );

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['translations'][0]['text'] ?? '';
    }
}
