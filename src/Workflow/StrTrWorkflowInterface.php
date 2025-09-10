<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Workflow;

interface StrTrWorkflowInterface
{
    public const PLACE_NEW        = 'new';
    public const PLACE_QUEUED     = 'queued';
    public const PLACE_TRANSLATED = 'translated';
    public const PLACE_REVIEW     = 'review';
}
