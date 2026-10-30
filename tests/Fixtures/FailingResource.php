<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class FailingResource extends Resource
{
    protected string $uri = 'file://reports/missing';

    public function handle(): Response
    {
        return Response::error('The report is not ready.');
    }
}
