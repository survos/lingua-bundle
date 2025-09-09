<?php

namespace Survos\LinguaBundle\Controller;

use Survos\LinguaBundle\Service\LinguaClient;
use Survos\LinguaBundle\Service\LinguaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/lingua', name: 'lingua_')]
class LinguaController extends AbstractController
{
	public function __construct(
		private readonly LinguaClient $linguaClient,
	) {
	}


	#[Route('/', name: 'index')]
	public function index(): Response
	{
		return $this->render('@SurvosLinguaBundle/lingua/index.html.twig', [
		    'bundle_name' => 'SurvosLinguaBundle',
		]);
	}
}
