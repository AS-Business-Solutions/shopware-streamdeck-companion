<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1715000000ApiKey extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1715000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `asbs_streamdeck_api_key` (
                `id` BINARY(16) NOT NULL,
                `key_hash` VARCHAR(64) NOT NULL,
                `label` VARCHAR(255) NOT NULL DEFAULT '',
                `created_at` DATETIME(3) NOT NULL,
                `last_used_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.asbs_streamdeck_api_key.key_hash` (`key_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive cleanup needed.
    }
}
