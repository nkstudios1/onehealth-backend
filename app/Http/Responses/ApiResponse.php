<?php

namespace App\Http\Responses;

use App\Enums\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The response helpers for controllers. Use this trait (or extend ApiController,
 * which already does) and return one of these. Never call response()->json()
 * directly: it would skip the standard envelope.
 *
 *   return $this->ok(new UserResource($user), 'Welcome back.');
 *   return $this->created($result);
 *   return $this->paginated($paginator, RecordResource::class);
 *   return $this->fail(ErrorCode::Forbidden);   // or just: throw ApiException::of(...)
 */
trait ApiResponse
{
    /** 200 with a payload. */
    protected function ok(mixed $data = null, string $message = 'OK', array $meta = []): JsonResponse
    {
        return ApiResponder::success($data, $message, 200, $meta);
    }

    /** 201 after creating something. */
    protected function created(mixed $data = null, string $message = 'Created', array $meta = []): JsonResponse
    {
        return ApiResponder::success($data, $message, 201, $meta);
    }

    /**
     * A page of results. Pass a Resource class to shape each item.
     *
     * @param  class-string<JsonResource>|null  $resource
     */
    protected function paginated(
        LengthAwarePaginator $paginator,
        ?string $resource = null,
        string $message = 'OK',
    ): JsonResponse {
        $items = $resource === null
            ? $paginator->items()
            : collect($paginator->items())
                ->map(fn ($item) => (new $resource($item))->resolve(request()))
                ->all();

        return ApiResponder::success($items, $message, 200, [
            'pagination' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * An error response without throwing. Prefer `throw ApiException::of(...)`
     * inside services; use this when a controller wants to return directly.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function fail(ErrorCode $code, ?string $message = null, array $errors = [], array $meta = []): JsonResponse
    {
        return ApiResponder::error($code, $message, $errors, $meta);
    }
}
