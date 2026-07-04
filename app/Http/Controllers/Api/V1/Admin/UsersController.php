<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'users' => UserResource::collection(User::orderBy('surname')->orderBy('forenames')->get()),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create([
            ...$request->validated(),
            'is_staff' => true,
            'password' => bcrypt(Str::random(64)),
        ]);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->validated());

        return new UserResource($user->fresh());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete yourself.'], 422);
        }

        $transferTo = $request->input('transfer_jobs_to');

        if ($user->jobs()->exists() && ! $transferTo && ! $request->boolean('delete_personal_jobs')) {
            return response()->json([
                'message' => 'User owns personal jobs. Specify either transfer_jobs_to (a user id) or delete_personal_jobs: true.',
            ], 422);
        }

        if ($transferTo) {
            $request->validate([
                'transfer_jobs_to' => ['integer', 'exists:users,id', Rule::notIn([$user->id])],
            ]);

            $recipient = User::findOrFail($transferTo);
            $user->transferPersonalJobsTo($recipient);
            $user->reassignAuthoredJobsTo($recipient);
            $user->delete();

            return response()->json(null, 204);
        }

        $user->reassignAuthoredJobsTo($request->user());
        $user->delete();

        return response()->json(null, 204);
    }
}
