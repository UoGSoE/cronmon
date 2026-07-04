<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TokenResource;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokensController extends Controller
{
    public function index(): JsonResponse
    {
        $tokens = PersonalAccessToken::query()
            ->with('tokenable')
            ->latest()
            ->get();

        return response()->json(['tokens' => TokenResource::collection($tokens)]);
    }
}
