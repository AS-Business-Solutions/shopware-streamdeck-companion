<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Tests\Command;

use Asbs\ShopwareStreamDeck\Command\CreateApiKeyCommand;
use Asbs\ShopwareStreamDeck\Service\ApiKeyManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateApiKeyCommandTest extends TestCase
{
    public function testPrintsSecretThatMatchesStoredHashAndPassesLabel(): void
    {
        $captured = null;
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('insert')
            ->willReturnCallback(static function (string $table, array $row) use (&$captured): int {
                $captured = $row;

                return 1;
            });

        $tester = new CommandTester(new CreateApiKeyCommand(new ApiKeyManager($connection)));
        $exitCode = $tester->execute(['--label' => 'My Stream Deck']);

        self::assertSame(0, $exitCode);
        self::assertIsArray($captured);
        self::assertSame('My Stream Deck', $captured['label']);

        // The secret printed to the console must hash to the persisted key_hash.
        self::assertSame(1, preg_match('/[0-9a-f]{64}/', $tester->getDisplay(), $matches));
        self::assertSame($captured['key_hash'], hash('sha256', $matches[0]));
    }

    public function testPassesEmptyLabelByDefault(): void
    {
        $captured = null;
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('insert')
            ->willReturnCallback(static function (string $table, array $row) use (&$captured): int {
                $captured = $row;

                return 1;
            });

        $tester = new CommandTester(new CreateApiKeyCommand(new ApiKeyManager($connection)));

        self::assertSame(0, $tester->execute([]));
        self::assertIsArray($captured);
        self::assertSame('', $captured['label']);
    }
}
