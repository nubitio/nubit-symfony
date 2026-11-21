<?php

declare(strict_types=1);

namespace Nubit\Platform\Exception;

use Nubit\Platform\Http\ProblemDetails;
use Throwable;

final class DomainProblemException extends ServiceException
{
    public function __construct(
        public readonly DomainErrorCode $errorCode,
        string $detail,
        public readonly string $title,
        public readonly string $type,
        public readonly ?string $action = null,
        public readonly ?int $numericCode = null,
        int $statusCode = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail, $statusCode, $previous);
    }

    public function toProblemDetails(): ProblemDetails
    {
        return new ProblemDetails(
            type: $this->type,
            title: $this->title,
            status: $this->getCode(),
            detail: $this->getMessage(),
            code: $this->errorCode->value,
            action: $this->action,
            extensions: $this->numericCode === null ? [] : ['numericCode' => $this->numericCode],
        );
    }
}
