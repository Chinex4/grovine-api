<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'chef_name' => $this->chef_name,
            'username' => $this->username,
            'referral_code' => $this->referral_code,
            'referred_by_user_id' => $this->referred_by_user_id,
            'email' => $this->email,
            'phone' => $this->phone,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'address' => $this->address,
            'role' => $this->role,
            'chef_niche_id' => $this->chef_niche_id,
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
            'chef_profile_share_url' => $this->role === \App\Models\User::ROLE_CHEF
                ? url('/api/chefs/'.$this->username)
                : null,
            'profile_picture' => $this->profile_picture,
            'wallet_balance' => (string) $this->wallet_balance,
            'onboarding_completed' => $this->onboarding_completed,
            'favorite_foods' => $this->whenLoaded('favoriteFoods'),
            'cuisine_regions' => $this->whenLoaded('cuisineRegions'),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

