<?php

namespace Survos\SurvosLinguaBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\SurvosLinguaBundle\SurvosLinguaBundle;

class SurvosLinguaBundleTest extends \TestCase
{
	public function testBundleExists(): void
	{
		$bundle = new SurvosLinguaBundle();
		$this->assertInstanceOf(SurvosLinguaBundle::class, $bundle);
	}
}
