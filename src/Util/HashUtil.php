<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Util;

use App\Entity\Source;

final class HashUtil
{
//    public static function calcHash(string $text, string $sourceLocale): string
//    {
//        // Stable, short-ish, prefix with source locale for cross-locale disambiguation.
//        return substr(base_convert(md5($sourceLocale.'|'.$text), 16, 36), 0, 18);
//    }

    public static function calcTranslationKey(string $sourceHash, string $targetLocale, ?string $engine=null)
    {
//        assert(!$engine);
        $engine = null;
        return sprintf('%s-%s%s', $sourceHash, $targetLocale, $engine ? "-$engine" : '');
    }

    // embed the original source in the middle
    public static function calcSourceKey(string $original, string $locale): string
    {
        \assert(\strlen($locale) >= 2, "Invalid Locale: $locale");
        $h = \hash('xxh3', $original);
        // keep server-compatible splice of locale into hash early
        return \substr_replace($h, \strtoupper(substr($locale, 0, 2)), 3, 0);
    }


}
