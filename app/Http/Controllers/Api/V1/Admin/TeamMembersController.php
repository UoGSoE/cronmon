<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamMembersController extends Controller
{
    public function store(Request $request, Team $team): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $team->users()->syncWithoutDetaching([$data['user_id']]);

        return response()->json([
            'members' => UserResource::collection(
                $team->users()->orderBy('surname')->orderBy('forenames')->get()
            ),
        ], 201);
    }

    public function destroy(Team $team, User $user): JsonResponse
    {
        $team->users()->detach($user->id);

        return response()->json(null, 204);
    }
}
