<?php

namespace Survos\SurvosLinguaBundle\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class LinguaExtension extends AbstractExtension
{
	public function getFilters(): array
	{
		return [
		    new TwigFilter('lingua_filter', [$this, 'filterMethod']),
		];
	}


	public function getFunctions(): array
	{
		return [
		    new TwigFunction('lingua_function', [$this, 'functionMethod']),
		];
	}


	public function filterMethod($value): string
	{
		// Implement your filter logic here
		return $value;
	}


	public function functionMethod(): string
	{
		// Implement your function logic here
		return 'Hello from lingua!';
	}
}
