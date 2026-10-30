<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;

class EmbeddedResourcePrompt extends Prompt
{
    protected string $description = 'Reviews the daily plan.';

    /**
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        return [
            Response::embeddedResource(DailyPlanResource::class),
            Response::text('Review the plan above.'),
        ];
    }
}
