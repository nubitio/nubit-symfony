<?php

declare(strict_types=1);

namespace Nubit\Platform\Http;

final readonly class ProblemDetails
{
    /**
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
        public ?string $code = null,
        public ?string $action = null,
        public array $extensions = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'code' => $this->code,
            'action' => $this->action,
            'extensions' => $this->extensions === [] ? null : $this->extensions,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
