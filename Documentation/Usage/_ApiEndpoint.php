<?php

declare(strict_types=1);

namespace MyVendor\MyExtension\Domain\Dto;

final readonly class ApiEndpoint
{
    public function __construct(
        public int $uid,
        public string $name,
        public string $url,
        public string $token,  // Contains vault UUID, not the secret
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            uid: (int) $row['uid'],
            name: (string) $row['name'],
            url: (string) $row['url'],
            token: (string) $row['token'],
        );
    }
}
