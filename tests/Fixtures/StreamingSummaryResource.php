<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Generator;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class StreamingSummaryResource extends Resource
{
    protected string $uri = 'file://reports/summary.txt';

    public function handle(): Generator
    {
        yield Response::notification('processing/progress', ['step' => 1]);
        yield Response::text('First page');
        yield Response::text('Second page');
    }
}
