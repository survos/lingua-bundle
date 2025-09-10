<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Entity;

trait _StrTrKeyHelpers
{
    public static function calcKey(Str $str, string $targetLocale, string $engine): string
    {
        return $str->getHash().'|'.$targetLocale.'|'.$engine;
    }

    public static function create(Str $str, string $targetLocale, string $engine): self
    {
        $obj = new self($str, $targetLocale, $engine); // assumes a matching ctor
        // if entity computes $this->key internally, ensure it matches calcKey
        return $obj;
    }

    public function getKey(): string
    {
        return $this->key; // property defined in your entity
    }
}
