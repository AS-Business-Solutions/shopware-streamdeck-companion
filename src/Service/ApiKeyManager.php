<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Service;

use Doctrine\DBAL\Connection;

final class ApiKeyManager
{
    private const TABLE = 'asbs_streamdeck_api_key';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(string $label = ''): string
    {
        $secret = bin2hex(random_bytes(32));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->insert(self::TABLE, [
            'id' => $this->uuidBytes(),
            'key_hash' => hash('sha256', $secret),
            'label' => $label,
            'created_at' => $now,
        ]);

        return $secret;
    }

    /**
     * Lists existing keys for the admin UI. The raw secret is never stored and
     * the hash is deliberately not selected — keys are display/metadata only.
     *
     * @return list<array{id:string,label:string,createdAt:string,lastUsedAt:string|null}>
     */
    public function list(): array
    {
        /** @var list<array{id:string,label:string,createdAt:string,lastUsedAt:string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, label, created_at AS createdAt, last_used_at AS lastUsedAt
             FROM '.self::TABLE.' ORDER BY created_at DESC',
        );

        return $rows;
    }

    public function count(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.self::TABLE);
    }

    /**
     * Read-only counterpart of isValid(): verifies a secret without touching
     * last_used_at, so a connection test from the admin does not fake
     * Stream-Deck activity on the key.
     */
    public function matches(string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        return $this->connection->fetchOne(
            'SELECT id FROM '.self::TABLE.' WHERE key_hash = :hash LIMIT 1',
            ['hash' => hash('sha256', $secret)],
        ) !== false;
    }

    public function delete(string $id): void
    {
        if (\strlen($id) !== 32 || !ctype_xdigit($id)) {
            return;
        }
        $binary = hex2bin($id);
        if ($binary === false) {
            return;
        }
        $this->connection->executeStatement(
            'DELETE FROM '.self::TABLE.' WHERE id = :id',
            ['id' => $binary],
        );
    }

    public function isValid(string $secret): bool
    {
        if ($secret === '') {
            return false;
        }
        $hash = hash('sha256', $secret);
        $id = $this->connection->fetchOne(
            'SELECT id FROM asbs_streamdeck_api_key WHERE key_hash = :hash LIMIT 1',
            ['hash' => $hash],
        );
        if ($id === false) {
            return false;
        }
        $this->connection->executeStatement(
            'UPDATE asbs_streamdeck_api_key SET last_used_at = :now WHERE id = :id',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );

        return true;
    }

    private function uuidBytes(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return $bytes;
    }
}
