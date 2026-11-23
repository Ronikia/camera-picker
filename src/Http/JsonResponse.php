<?php

declare(strict_types=1);

namespace App\Http;

final class JsonResponse
{
    /**
     * @param array<mixed> $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly int $status = 200,
    ) {
    }

    public static function error(int $status, string $message): self
    {
        return new self(['error' => ['status' => $status, 'message' => $message]], $status);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }
}
