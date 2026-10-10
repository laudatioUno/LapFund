<?php

declare(strict_types=1);

namespace LapFund\Controller;

/** Thrown by access checks to leave a controller with a redirect. */
final class RedirectException extends \RuntimeException
{
    public function __construct(public readonly string $location)
    {
        parent::__construct('redirect');
    }
}
