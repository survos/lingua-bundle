<?php

declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('lingua:harvest:messages', 'Harvest |trans keys used in templates into babel Str storage, one context per domain.')]
final class LinguaHarvestMessagesCommand
{
    public function __construct(private readonly LinguaHarvestCommand $harvester)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Comma-separated domains to harvest (allowlist -- default: messages,system). Never point this at a vendor bundle\'s own domain.')]
        ?string $domains = null,
    ): int {
        return $this->harvester->harvestMessages($io, $domains);
    }
}
