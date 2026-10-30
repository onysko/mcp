<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class BinaryReportResource extends Resource
{
    protected string $uri = 'file://reports/monthly.pdf';

    protected string $mimeType = 'application/pdf';

    public function handle(): Response
    {
        return Response::blob('%PDF-1.4 binary');
    }
}
