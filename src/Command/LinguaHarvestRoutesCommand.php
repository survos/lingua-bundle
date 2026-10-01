<?php

declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('lingua:harvest:routes', 'Harvest route names into babel Str storage (context "routing"), humanized for translation.')]
final class LinguaHarvestRoutesCommand
{
    public function __construct(private readonly LinguaHarvestCommand $harvester) {}

    public function __invoke(SymfonyStyle $io): int
    {
        return $this->harvester->harvestRoutes($io);
    }
}
