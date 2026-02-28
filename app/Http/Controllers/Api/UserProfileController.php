<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['favoriteFoods:id,name,slug', 'cuisineRegions:id,name,slug', 'chefNiche:id,name,slug,description', 'chefNiches:id,name,slug,description']);

        return response()->json([
            'message' => 'User profile fetched successfully.',
            'data' => new UserResource($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'username' => ['sometimes', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if (array_key_exists('email', $validated)) {
            $validated['email'] = strtolower((string) $validated['email']);
        }

        if (array_key_exists('username', $validated)) {
            $validated['username'] = strtolower((string) $validated['username']);
        }

        $user->update($validated);

        $updatedFields = array_keys($validated);
        /** @var User|null $freshUser */
        $freshUser = $user->fresh();

        if ($updatedFields !== [] && $freshUser instanceof User) {
            $this->notificationService->sendAccountActivity(
                user: $freshUser,
                title: 'Profile updated',
                message: 'Your profile details were updated successfully.',
                data: [
                    'updated_fields' => $updatedFields,
                ],
                channels: [NotificationService::CHANNEL_IN_APP],
            );
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => new UserResource(($freshUser?->loadMissing(['favoriteFoods:id,name,slug', 'cuisineRegions:id,name,slug', 'chefNiche:id,name,slug,description', 'chefNiches:id,name,slug,description']) ?? $user)),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $profilePicture = $user->getRawOriginal('profile_picture');

        $this->deleteUserWithRetry($user);

        if ($profilePicture && ! str_starts_with($profilePicture, 'http://') && ! str_starts_with($profilePicture, 'https://')) {
            Storage::disk('public')->delete($profilePicture);
        }

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
    }

    public function uploadProfilePicture(Request $request): JsonResponse
    {
        $request->validate([
            'profile_picture' => ['required', 'image', 'max:5120'],
        ]);

        $user = $request->user();
        $oldPath = $user->getRawOriginal('profile_picture');
        $newPath = $request->file('profile_picture')->store('profiles', 'public');

        $user->update([
            'profile_picture' => $newPath,
        ]);

        if ($oldPath && ! str_starts_with($oldPath, 'http://') && ! str_starts_with($oldPath, 'https://')) {
            Storage::disk('public')->delete($oldPath);
        }

        /** @var User|null $freshUser */
        $freshUser = $user->fresh();

        if ($freshUser instanceof User) {
            $this->notificationService->sendAccountActivity(
                user: $freshUser,
                title: 'Profile picture updated',
                message: 'Your profile picture was updated successfully.',
                data: [],
                channels: [NotificationService::CHANNEL_IN_APP],
            );
        }

        return response()->json([
            'message' => 'Profile picture uploaded successfully.',
            'data' => new UserResource(($freshUser?->loadMissing(['favoriteFoods:id,name,slug', 'cuisineRegions:id,name,slug', 'chefNiche:id,name,slug,description', 'chefNiches:id,name,slug,description']) ?? $user)),
        ]);
    }

    private function deleteUserWithRetry(User $user): void
    {
        $connection = $user->getConnectionName() ?: config('database.default');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $user->delete();

                return;
            } catch (QueryException $exception) {
                $isLastAttempt = $attempt === 1;

                if ($isLastAttempt || ! $this->isMysqlReprepareError($exception)) {
                    throw $exception;
                }

                DB::purge($connection);
                DB::reconnect($connection);

                $freshUser = User::query()->find($user->id);

                if (! $freshUser) {
                    return;
                }

                $user = $freshUser;
            }
        }
    }

    private function isMysqlReprepareError(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $driverCode === 1615 || str_contains($message, 'needs to be re-prepared');
    }
}
