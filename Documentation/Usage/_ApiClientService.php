<?php

declare(strict_types=1);

namespace MyVendor\MyExtension\Service;

use MyVendor\MyExtension\Domain\Dto\ApiEndpoint;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class ApiClientService
{
    public function __construct(
        private readonly VaultServiceInterface $vault,
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * Call an API endpoint with vault-managed authentication.
     *
     * The token is retrieved from vault and injected at request time.
     * It never appears in this code and is wiped from memory immediately.
     */
    public function call(
        ApiEndpoint $endpoint,
        string $method,
        string $path,
        array $data = [],
    ): ResponseInterface {
        // Create PSR-7 request using TYPO3's RequestFactory
        $request = $this->requestFactory->createRequest(
            $method,
            rtrim($endpoint->url, '/') . '/' . ltrim($path, '/'),
        );

        if ($data !== [] && \in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody(
                    \GuzzleHttp\Psr7\Utils::streamFor(json_encode($data))
                );
        }

        // Send via VaultHttpClient - token never exposed to application
        return $this->vault->http()
            ->withAuthentication($endpoint->token, SecretPlacement::Bearer)
            ->withReason('API call to ' . $endpoint->name . ': ' . $path)
            ->sendRequest($request);
    }

    /**
     * Convenience method for GET requests.
     */
    public function get(ApiEndpoint $endpoint, string $path): array
    {
        $response = $this->call($endpoint, 'GET', $path);

        return json_decode(
            $response->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
