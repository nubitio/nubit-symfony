<?php

declare(strict_types=1);

namespace Nubit\Platform\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ProblemResponse extends JsonResponse
{
    public function __construct(ProblemDetails $problem)
    {
        parent::__construct($problem->toArray(), $problem->status, [
            'Content-Type' => 'application/problem+json',
        ]);
    }
}
