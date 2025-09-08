<?php

namespace Survos\SurvosLinguaBundle\Service;

class LinguaService
{
	private array $config;


	public function __construct(array $config = [])
	{
		$this->config = $config;
	}


	public function getApiKey(): ?string
	{
		return $this->config['api_key'] ?? null;
	}


	public function process(): string
	{
		// Implement your service logic here
		return 'Processing with lingua service';
	}
}
