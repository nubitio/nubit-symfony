<?php

declare(strict_types=1);

namespace Nubit\Platform\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

class ApiResponse extends JsonResponse
{
    public function __construct(
        public bool $success,
        public string $message,
        public mixed $data = null,
    ) {
        parent::__construct($this->toArray(), $success ? 200 : 400);
    }

    public static function success(string $message = 'Success', mixed $data = null): self
    {
        return new self(true, $message, $data);
    }

    public static function error(string $message, mixed $data = null): self
    {
        return new self(false, $message, $data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
