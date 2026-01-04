<?php

use MyVendor\MyExtension\Domain\Dto\ApiEndpoint;
use MyVendor\MyExtension\Service\ApiClientService;

// Load endpoint from database
$row = $connection->select(['*'], 'tx_myext_apiendpoint', ['uid' => 1])
    ->fetchAssociative();
$endpoint = ApiEndpoint::fromDatabaseRow($row);

// Make authenticated API call
$customers = $this->apiClientService->get($endpoint, '/customers');
