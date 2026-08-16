<?php

namespace Survos\LinguaBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\LinguaBundle\SurvosLinguaBundle;

// Extended \TestCase -- the global namespace, which does not exist -- while importing the
// real one just above. Every run fatalled on the missing class.
class SurvosLinguaBundleTest extends TestCase
{
	public function testBundleExists(): void
	{
		$bundle = new SurvosLinguaBundle();
		$this->assertInstanceOf(SurvosLinguaBundle::class, $bundle);
	}
}
