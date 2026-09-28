<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Standard success envelope. `docs/api.md` documents list endpoints as
     * `{data: [...], meta: {total, page, per_page}}`; single resources use the
     * same `data` key so clients can parse one shape.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function data(mixed $data, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(array_merge(['data' => $data], $extra), $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function message(string $message, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(array_merge(['message' => $message], $meta), $status);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(string $message, int $status = 400, string $code = 'error', array $details = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => (object) $details,
        ], $status);
    }

    /**
     * Pagination envelope with the documented caps (per_page <= 100).
     *
     * @param  array<string, string>  $query
     */
    public static function paginate(LengthAwarePaginator $paginator, array $query = []): JsonResponse
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
            'query' => array_filter($query, static fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    /**
     * Resolve and clamp `per_page` from the request (max 100 per docs/api.md).
     */
    public static function perPage(int $default = 20, int $max = 100): int
    {
        $request = request();
        $perPage = (int) $request->query('per_page', $default);

        if ($perPage < 1) {
            $perPage = $default;
        }

        return min($perPage, $max);
    }
}
