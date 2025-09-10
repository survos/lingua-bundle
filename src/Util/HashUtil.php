<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Util;

final class HashUtil
{
    public static function calcHash(string $text, string $sourceLocale): string
    {
        // Stable, short-ish, prefix with source locale for cross-locale disambiguation.
        return substr(base_convert(md5($sourceLocale.'|'.$text), 16, 36), 0, 18);
    }
}
