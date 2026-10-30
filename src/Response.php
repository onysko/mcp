<?php

declare(strict_types=1);

namespace Laravel\Mcp;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use JsonException;
use Laravel\Mcp\Enums\Role;
use Laravel\Mcp\Schema\Icon;
use Laravel\Mcp\Server\Content\Audio;
use Laravel\Mcp\Server\Content\Blob;
use Laravel\Mcp\Server\Content\EmbeddedResource;
use Laravel\Mcp\Server\Content\Image;
use Laravel\Mcp\Server\Content\Notification;
use Laravel\Mcp\Server\Content\ResourceLink;
use Laravel\Mcp\Server\Content\StructuredContentText;
use Laravel\Mcp\Server\Content\Text;
use Laravel\Mcp\Server\Contracts\Content;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use League\Flysystem\UnableToReadFile;

class Response
{
    use Conditionable;
    use Macroable;

    protected function __construct(
        protected Content $content,
        protected Role $role = Role::User,
        protected bool $isError = false,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function notification(string $method, array $params = []): static
    {
        return new static(new Notification($method, $params));
    }

    public static function text(string $text): static
    {
        return new static(new Text($text));
    }

    public static function html(string $path): static
    {
        $path = str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) ? $path : resource_path($path);

        if (! file_exists($path)) {
            throw new InvalidArgumentException("File not found at path [{$path}].");
        }

        return static::text((string) file_get_contents($path));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $mergeData
     */
    public static function view(string $view, array $data = [], array $mergeData = []): static
    {
        return static::text(view($view, $data, $mergeData)->render());
    }

    /**
     * @internal
     *
     * @throws JsonException
     */
    public static function json(mixed $content): static
    {
        return static::text(json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function blob(string $content): static
    {
        return new static(new Blob($content));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public static function structured(array $response): ResponseFactory
    {
        if ($response === []) {
            throw new InvalidArgumentException('Structured content cannot be empty.');
        }

        try {
            $json = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $jsonException) {
            throw new InvalidArgumentException("Invalid structured content: {$jsonException->getMessage()}", 0, $jsonException);
        }

        $content = new static(new StructuredContentText($json));

        return (new ResponseFactory($content))->withStructuredContent($response);
    }

    public static function error(string $text): static
    {
        return new static(new Text($text), isError: true);
    }

    public function content(): Content
    {
        return $this->content;
    }

    /**
     * @param  Response|array<int, Response>  $responses
     */
    public static function make(Response|array $responses): ResponseFactory
    {
        return new ResponseFactory($responses);
    }

    /**
     * @param  array<string, mixed>|string  $meta
     */
    public function withMeta(array|string $meta, mixed $value = null): static
    {
        $this->content->setMeta($meta, $value);

        return $this;
    }

    public static function audio(string $data, string $mimeType = 'audio/wav'): static
    {
        return new static(new Audio($data, $mimeType));
    }

    public static function image(string $data, string $mimeType = 'image/png'): static
    {
        return new static(new Image($data, $mimeType));
    }

    /**
     * @param  string|class-string<Resource>|Resource|ResourceLink  $uri
     * @param  array<string, mixed>  $annotations
     * @param  list<Icon>  $icons
     */
    public static function resourceLink(
        string|Resource|ResourceLink $uri,
        ?string $name = null,
        ?string $mimeType = null,
        ?string $title = null,
        ?string $description = null,
        ?int $size = null,
        array $annotations = [],
        array $icons = [],
    ): static {
        if (is_string($uri) && is_subclass_of($uri, Resource::class)) {
            $uri = Container::getInstance()->make($uri);
        }

        $link = match (true) {
            $uri instanceof ResourceLink => $uri,
            $uri instanceof Resource => (new ResourceLink(
                uri: $uri->uri(),
                name: $name ?? $uri->name(),
                mimeType: $mimeType ?? $uri->mimeType(),
                title: $title ?? $uri->title(),
                description: $description ?? $uri->description(),
                size: $size,
                annotations: array_merge($uri->annotations(), $annotations),
                icons: $icons === [] ? $uri->resolvedIcons() : $icons,
            )),
            default => new ResourceLink(
                uri: $uri,
                name: $name ?? throw new InvalidArgumentException('Resource link name is required when using a URI string.'),
                mimeType: $mimeType,
                title: $title,
                description: $description,
                size: $size,
                annotations: $annotations,
                icons: $icons,
            ),
        };

        return new static($link);
    }

    /**
     * Embed a resource's contents in a tool or prompt response.
     *
     * Accepts a URI with explicit text or blob contents, or a registered resource
     * (class-string or instance) whose handler is run to produce them, with the
     * given arguments filling its URI template.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $annotations
     */
    public static function embeddedResource(
        string|Resource|EmbeddedResource $uri,
        array $arguments = [],
        ?string $text = null,
        ?string $blob = null,
        ?string $mimeType = null,
        array $annotations = [],
    ): static {
        if (is_string($uri) && is_subclass_of($uri, Resource::class)) {
            $uri = Container::getInstance()->make($uri);
        }

        $embedded = match (true) {
            $uri instanceof EmbeddedResource => $uri,
            $uri instanceof Resource => static::embedResource($uri, $arguments, $annotations),
            default => new EmbeddedResource(
                uri: $uri,
                text: $text,
                blob: $blob,
                mimeType: $mimeType,
                annotations: $annotations,
            ),
        };

        return new static($embedded);
    }

    /**
     * Run the resource's handler and wrap its first contents as an embedded resource.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $annotations
     */
    protected static function embedResource(Resource $resource, array $arguments, array $annotations): EmbeddedResource
    {
        $uri = static::expandResourceUri($resource, $arguments);

        $container = Container::getInstance();

        // The container seeds a resolved Request with the calling tool's arguments: replace them.
        $request = $container->make(Request::class);
        $request->setArguments($arguments);
        $request->setUri($uri);

        $container->instance(Request::class, $request);

        try {
            // @phpstan-ignore-next-line
            $result = $container->call([$resource, 'handle']);
        } finally {
            $container->forgetInstance(Request::class);
        }

        $response = static::firstResponse($result);

        if ($response->isError()) {
            throw new InvalidArgumentException(sprintf('Resource [%s] returned an error and may not be embedded.', $resource::class));
        }

        $contents = $response->content()->toResource($resource);

        return new EmbeddedResource(
            uri: $uri,
            text: $contents['text'] ?? null,
            blob: isset($contents['blob']) ? base64_decode((string) $contents['blob'], true) ?: null : null,
            mimeType: $contents['mimeType'] ?? $resource->mimeType(),
            annotations: array_merge($resource->annotations(), $annotations),
        );
    }

    /**
     * Fill the resource's URI template with the given arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected static function expandResourceUri(Resource $resource, array $arguments): string
    {
        if (! $resource instanceof HasUriTemplate) {
            return $resource->uri();
        }

        $template = $resource->uriTemplate();

        $missing = array_diff($template->variableNames(), array_keys($arguments));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Missing URI template variable%s [%s] for resource [%s].',
                count($missing) === 1 ? '' : 's',
                implode(', ', $missing),
                $resource::class,
            ));
        }

        $replacements = [];

        foreach ($template->variableNames() as $name) {
            $replacements['{'.$name.'}'] = (string) $arguments[$name];
        }

        $uri = strtr((string) $template, $replacements);

        // Keep the embedded URI readable back through resources/read, which matches a variable within one segment only.
        if ($template->match($uri) === null) {
            throw new InvalidArgumentException(sprintf('The arguments do not form a valid URI for the template of resource [%s].', $resource::class));
        }

        return $uri;
    }

    /**
     * Normalize a handler result to its first response.
     */
    protected static function firstResponse(mixed $result): Response
    {
        if ($result instanceof Generator) {
            $result = iterator_to_array($result, false);
        }

        if (is_string($result)) {
            return static::text($result);
        }

        if ($result instanceof ResponseFactory) {
            $result = $result->responses()->all();
        }

        if (is_array($result)) {
            $result = array_values(array_filter(
                $result,
                fn (mixed $item): bool => ! $item instanceof Response || ! $item->isNotification(),
            ))[0] ?? null;
        }

        if ($result instanceof Response) {
            return $result;
        }

        throw new InvalidArgumentException('A resource handler must return a response, a string, or a list of responses to be embedded.');
    }

    public static function fromStorage(string $path, ?string $disk = null, ?string $mimeType = null): static
    {
        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        try {
            $data = $storage->get($path);
        } catch (UnableToReadFile $unableToReadFile) {
            throw new InvalidArgumentException("File not found at path [{$path}].", 0, $unableToReadFile);
        }

        if ($data === null) {
            throw new InvalidArgumentException("File not found at path [{$path}].");
        }

        $mimeType ??= $storage->mimeType($path) ?: throw new InvalidArgumentException(
            "Unable to determine MIME type for [{$path}].",
        );

        return match (true) {
            str_starts_with($mimeType, 'image/') => static::image($data, $mimeType),
            str_starts_with($mimeType, 'audio/') => static::audio($data, $mimeType),
            default => throw new InvalidArgumentException("Unsupported MIME type [{$mimeType}] for [{$path}]."),
        };
    }

    public function asAssistant(): static
    {
        return new static($this->content, Role::Assistant, $this->isError);
    }

    public function isNotification(): bool
    {
        return $this->content instanceof Notification;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function role(): Role
    {
        return $this->role;
    }
}
