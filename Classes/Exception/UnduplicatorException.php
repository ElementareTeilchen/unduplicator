<?php

declare(strict_types=1);

namespace Elementareteilchen\Unduplicator\Exception;

class UnduplicatorException extends \Exception
{
    public function __construct(string $message)
    {
        parent::__construct($message, 1);
    }

}
