<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

abstract class ApiController extends Controller
{
    protected function ok(array $payload, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $payload], $status);
    }

    protected function collection(array $items, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $items,
            'meta' => $meta,
        ], $status);
    }

    protected function noContent(): Response
    {
        return response()->noContent();
    }
}
