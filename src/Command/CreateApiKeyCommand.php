<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck\Command;

use Asbs\ShopwareStreamDeck\Service\ApiKeyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asbs:streamdeck:key:create',
    description: 'Generate a new API key for the Stream Deck plugin and print it once.',
)]
final class CreateApiKeyCommand extends Command
{
    public function __construct(private readonly ApiKeyManager $apiKeyManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'label',
            'l',
            InputOption::VALUE_REQUIRED,
            'Optional label to identify the key (e.g. the Stream Deck device).',
            '',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $label = (string) $input->getOption('label');

        $secret = $this->apiKeyManager->create($label);

        $io->success('API key created. Copy it now — it is shown only this once.');
        $io->writeln($secret);
        $io->newline();
        $io->writeln('Paste this value into the Stream Deck plugin field "Companion API key".');

        return Command::SUCCESS;
    }
}
