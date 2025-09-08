<?php

namespace Survos\LinguaBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\LinguaBundle\SurvosLinguaBundle;

class SurvosLinguaBundleTest extends \TestCase
{
	public function testBundleExists(): void
	{
		$bundle = new SurvosLinguaBundle();
		$this->assertInstanceOf(SurvosLinguaBundle::class, $bundle);
	}
}
