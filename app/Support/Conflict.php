<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class Conflict extends HttpException
{
    public function __construct(string $message = 'The record changed. Reload and try again.')
    {
        parent::__construct(409, $message);
    }
}
