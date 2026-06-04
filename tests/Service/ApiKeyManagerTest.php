<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Tests\Service;

use Asbs\ShopwareStreamDeck\Service\ApiKeyManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ApiKeyManagerTest extends TestCase
{
    public function testInvalidWhenNoMatchingHash(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturn(false);

        $manager = new ApiKeyManager($connection);

        self::assertFalse($manager->isValid('whatever'));
    }

    public function testValidWhenHashMatches(): void
    {
        $connection = $this->createMock(Connection::class);
        $secret = 'super-secret-key';
        $expectedHash = hash('sha256', $secret);

        $connection
            ->method('fetchOne')
            ->with(
                self::stringContains('asbs_streamdeck_api_key'),
                self::callback(static fn (array $params): bool => $params['hash'] === $expectedHash),
            )
            ->willReturn('11111111111111111111111111111111');

        $connection
            ->expects(self::once())
            ->method('executeStatement');

        $manager = new ApiKeyManager($connection);

        self::assertTrue($manager->isValid($secret));
    }

    public function testCreateReturnsRandomSecretAndPersistsHash(): void
    {
        $connection = $this->createMock(Connection::class);
        $captured = null;
        $connection
            ->expects(self::once())
            ->method('insert')
            ->willReturnCallback(static function (string $table, array $row) use (&$captured): int {
                $captured = $row;

                return 1;
            });

        $manager = new ApiKeyManager($connection);

        $secret = $manager->create('test label');

        self::assertNotEmpty($secret);
        self::assertSame(hash('sha256', $secret), $captured['key_hash']);
        self::assertSame('test label', $captured['label']);
        self::assertArrayHasKey('id', $captured);
        self::assertArrayHasKey('created_at', $captured);
    }

    public function testListReturnsRowsWithoutExposingTheHash(): void
    {
        $connection = $this->createMock(Connection::class);
        $sql = null;
        $rows = [
            ['id' => 'aabb', 'label' => 'Deck 1', 'createdAt' => '2026-05-01 10:00:00', 'lastUsedAt' => null],
        ];
        $connection
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $query) use (&$sql, $rows): array {
                $sql = $query;

                return $rows;
            });

        $result = (new ApiKeyManager($connection))->list();

        self::assertSame($rows, $result);
        self::assertStringNotContainsString('key_hash', $sql);
        self::assertStringContainsString('HEX(id)', $sql);
    }

    public function testDeleteRemovesTheKeyByHexId(): void
    {
        $hexId = str_repeat('ab', 16); // 32 hex chars
        $connection = $this->createMock(Connection::class);
        $params = null;
        $connection
            ->expects(self::once())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $query, array $p) use (&$params): int {
                $params = $p;

                return 1;
            });

        (new ApiKeyManager($connection))->delete($hexId);

        self::assertSame(hex2bin($hexId), $params['id']);
    }

    public function testDeleteIgnoresMalformedIds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        (new ApiKeyManager($connection))->delete('not-a-valid-hex-id');
    }
}
