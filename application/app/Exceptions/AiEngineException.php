<?php

namespace App\Exceptions;

use RuntimeException;

class AiEngineException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $upstreamStatus = 502,
        protected string $operation = 'request',
        protected mixed $payload = null,
    ) {
        parent::__construct($message);
    }

    public function upstreamStatus(): int
    {
        return $this->upstreamStatus;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }

    public function statusForClient(): int
    {
        return match (true) {
            $this->upstreamStatus === 404 => 404,
            $this->upstreamStatus === 401, $this->upstreamStatus === 403 => 502,
            $this->upstreamStatus === 503 => 503,
            $this->upstreamStatus >= 500 => 502,
            $this->upstreamStatus >= 400 => 422,
            default => 502,
        };
    }
}
