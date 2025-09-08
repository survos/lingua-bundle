<?php

namespace Survos\LinguaBundle\Command;

use Survos\LinguaBundle\Service\LinguaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('lingua:process', 'Process lingua operations')]
class LinguaCommand
{
	public function __construct(
		private readonly LinguaService $linguaService,
	) {
	}


	public function __invoke(
		SymfonyStyle $io,
		#[Option('Reset data before processing')]
		?bool $reset = null,
	): int
	{
		$io->success('Command executed successfully!');

		return Command::SUCCESS;
	}
}
