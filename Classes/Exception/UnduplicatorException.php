<?php

declare(strict_types=1);

namespace ElementareTeilchen\Unduplicator\Exception;

class UnduplicatorException extends \Exception
{
    public function __construct(string $message, int $code = 1)
    {
        parent::__construct($message, $code);
    }

}
