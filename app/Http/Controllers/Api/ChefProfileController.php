<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChefProfileController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function become(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chef_name' => ['required', 'string', 'max:120'],
            'chef_niche_id' => ['required_without:chef_niche_ids', 'uuid', Rule::exists('chef_niches', 'id')->where('is_active', true)],
            'chef_niche_ids' => ['required_without:chef_niche_id', 'array', 'min:1'],
            'chef_niche_ids.*' => ['uuid', 'distinct', Rule::exists('chef_niches', 'id')->where('is_active', true)],
        ]);

        $user = $request->user();
        $chefNicheIds = collect($validated['chef_niche_ids'] ?? [($validated['chef_niche_id'] ?? null)])
            ->filter()
            ->unique()
            ->values();

        $user->update([
            'role' => User::ROLE_CHEF,
            'chef_name' => $validated['chef_name'],
            'chef_niche_id' => $chefNicheIds->first(),
        ]);
        $user->chefNiches()->sync($chefNicheIds->all());

        $fresh = $user->fresh();

        if ($fresh instanceof User) {
            $fresh->loadMissing(['chefNiche:id,name,slug,description', 'chefNiches:id,name,slug,description']);

            $this->notificationService->sendAccountActivity(
                user: $fresh,
                title: 'Chef profile created',
                message: 'Your account has been upgraded to chef profile successfully.',
                data: [
                    'role' => $fresh->role,
                    'chef_name' => $fresh->chef_name,
                    'chef_niche_id' => $fresh->chef_niche_id,
                    'chef_niche_ids' => $fresh->chefNiches->pluck('id')->all(),
                ],
                channels: [NotificationService::CHANNEL_IN_APP],
            );
        }

        return response()->json([
            'message' => 'Chef profile updated successfully.',
            'data' => new UserResource(($fresh ?? $user)->loadMissing(['chefNiche:id,name,slug,description', 'chefNiches:id,name,slug,description'])),
        ]);
    }

    public function show(string $username): JsonResponse
    {
        $chef = User::query()
            ->where('username', strtolower($username))
            ->where('role', User::ROLE_CHEF)
            ->with(['chefNiche:id,name,slug,description', 'chefNiches:id,name,slug,description'])
            ->first();

        if (! $chef) {
            return response()->json([
                'message' => 'Chef profile not found.',
            ], 404);
        }

        $chefNiches = $chef->chefNiches;

        if ($chefNiches->isEmpty() && $chef->chefNiche) {
            $chefNiches = collect([$chef->chefNiche]);
        }

        return response()->json([
            'message' => 'Chef profile fetched successfully.',
            'data' => [
                'id' => $chef->id,
                'chef_name' => $chef->chef_name,
                'name' => $chef->name,
                'username' => $chef->username,
                'profile_picture' => $chef->profile_picture,
                'chef_niche' => $chef->chefNiche
                    ? [
                        'id' => $chef->chefNiche->id,
                        'name' => $chef->chefNiche->name,
                        'slug' => $chef->chefNiche->slug,
                        'description' => $chef->chefNiche->description,
                    ]
                    : null,
                'chef_niches' => $chefNiches
                    ->map(fn ($niche): array => [
                        'id' => $niche->id,
                        'name' => $niche->name,
                        'slug' => $niche->slug,
                        'description' => $niche->description,
                    ])
                    ->values()
                    ->all(),
                'share_url' => url('/api/chefs/'.$chef->username),
            ],
        ]);
    }
}
