<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class EmbeddedResourceTool extends Tool
{
    protected string $description = 'Returns the monthly report embedded in the result.';

    public function handle(Request $request): Response
    {
        return Response::embeddedResource(
            uri: 'file:///reports/monthly.json',
            text: '{"total":42}',
            mimeType: 'application/json',
            annotations: [
                'audience' => ['user'],
                'priority' => 0.9,
            ],
        );
    }
}
