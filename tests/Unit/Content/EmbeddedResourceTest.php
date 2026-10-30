<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Content\EmbeddedResource;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;

it('may be used in tools and prompts', function (): void {
    $embedded = new EmbeddedResource(
        uri: 'file:///data/report.json',
        text: '{"key":"value"}',
        mimeType: 'application/json',
    );

    $expected = [
        'type' => 'resource',
        'resource' => [
            'uri' => 'file:///data/report.json',
            'mimeType' => 'application/json',
            'text' => '{"key":"value"}',
        ],
    ];

    expect($embedded->toTool(new class extends Tool {}))->toEqual($expected)
        ->and($embedded->toPrompt(new class extends Prompt {}))->toEqual($expected);
});

it('may not be used in resources', function (): void {
    $embedded = new EmbeddedResource('file:///data/report.json', text: 'content');

    $resource = new class extends Resource
    {
        protected string $uri = 'file://store/items.json';
    };

    expect(fn (): array => $embedded->toResource($resource))
        ->toThrow(InvalidArgumentException::class, 'EmbeddedResource content may not be used in resources.');
});

it('encodes blob contents as base64', function (): void {
    $embedded = new EmbeddedResource('file:///data/report.pdf', blob: '%PDF-1.4', mimeType: 'application/pdf');

    expect($embedded->toArray()['resource'])->toEqual([
        'uri' => 'file:///data/report.pdf',
        'mimeType' => 'application/pdf',
        'blob' => base64_encode('%PDF-1.4'),
    ]);
});

it('requires either text or blob contents', function (): void {
    expect(fn (): EmbeddedResource => new EmbeddedResource('file:///data/report.json'))
        ->toThrow(InvalidArgumentException::class, 'An embedded resource requires either text or blob content.')
        ->and(fn (): EmbeddedResource => new EmbeddedResource('file:///data/report.json', text: 'a', blob: 'b'))
        ->toThrow(InvalidArgumentException::class, 'An embedded resource requires either text or blob content.');
});

it('casts to string as the text, falling back to the uri', function (): void {
    expect((string) new EmbeddedResource('file:///data/a.txt', text: 'hello'))->toBe('hello')
        ->and((string) new EmbeddedResource('file:///data/a.bin', blob: 'raw'))->toBe('file:///data/a.bin');
});

it('includes annotations and meta when set', function (): void {
    $embedded = new EmbeddedResource(
        uri: 'file:///data/report.json',
        text: 'content',
        annotations: ['audience' => ['user'], 'priority' => 0.5],
    );

    $embedded->setMeta('source', 'nightly');

    expect($embedded->toArray())->toEqual([
        'type' => 'resource',
        'resource' => ['uri' => 'file:///data/report.json', 'text' => 'content'],
        'annotations' => ['audience' => ['user'], 'priority' => 0.5],
        '_meta' => ['source' => 'nightly'],
    ]);
});
