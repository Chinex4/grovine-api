<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $accountStatus = $this->effectiveAccountStatus();
        $recipesCount = $this->resolveCount('recipes_count');
        $notificationsCount = $this->resolveCount('notifications_count');
        $bookmarksCount = $this->resolveCount('bookmarked_recipes_count');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'chef_name' => $this->chef_name,
            'display_name' => $this->chef_name ?: $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'profile_picture' => $this->profile_picture,
            'role' => $this->role,
            'is_verified' => (bool) $this->email_verified_at,
            'verification_status' => $this->email_verified_at ? 'verified' : 'unverified',
            'account_status' => $accountStatus,
            'status' => $this->email_verified_at ? 'verified' : 'unverified',
            'warning_count' => (int) ($this->warning_count ?? 0),
            'last_warned_at' => $this->last_warned_at?->toIso8601String(),
            'suspended_until' => $this->suspended_until?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,
            'banned_at' => $this->banned_at?->toIso8601String(),
            'banned_reason' => $this->banned_reason,
            'last_active' => $this->last_seen_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'joined_at' => $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'stats' => [
                'posts_created_count' => $recipesCount,
                'bookmarked_recipes_count' => $bookmarksCount,
                'notifications_received_count' => $notificationsCount,
                'warnings_count' => (int) ($this->warning_count ?? 0),
                'followers_count' => 0,
                'reports_received_count' => 0,
            ],
            'chef_niche' => $this->whenLoaded('chefNiche', function (): ?array {
                if (! $this->chefNiche) {
                    return null;
                }

                return [
                    'id' => $this->chefNiche->id,
                    'name' => $this->chefNiche->name,
                    'slug' => $this->chefNiche->slug,
                    'description' => $this->chefNiche->description,
                ];
            }),
            'chef_niches' => $this->whenLoaded('chefNiches', fn (): array => $this->chefNiches
                ->map(fn ($niche): array => [
                    'id' => $niche->id,
                    'name' => $niche->name,
                    'slug' => $niche->slug,
                    'description' => $niche->description,
                ])
                ->values()
                ->all()),
        ];
    }

    private function resolveCount(string $key): int
    {
        $value = $this->resource->{$key} ?? 0;

        return (int) $value;
    }
}
