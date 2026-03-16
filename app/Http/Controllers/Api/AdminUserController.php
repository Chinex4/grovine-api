<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Models\UserDailyActivity;
use App\Services\NotificationService;
use App\Services\ReferralService;
use App\Services\UsernameService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ReferralService $referralService,
        private readonly UsernameService $usernameService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in([User::ROLE_USER, User::ROLE_CHEF, User::ROLE_ADMIN])],
            'verification_status' => ['nullable', Rule::in(['verified', 'unverified'])],
            'account_status' => ['nullable', Rule::in([
                User::ACCOUNT_STATUS_ACTIVE,
                User::ACCOUNT_STATUS_SUSPENDED,
                User::ACCOUNT_STATUS_BANNED,
            ])],
            'sort_by' => ['nullable', Rule::in(['name', 'email', 'role', 'created_at', 'last_seen_at'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->with(['chefNiche:id,name,slug,description', 'chefNiches:id,name,slug,description'])
            ->withCount(['recipes', 'notifications', 'bookmarkedRecipes']);

        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);

            $query->where(function ($inner) use ($term): void {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('chef_name', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        if (! empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        if (! empty($validated['verification_status'])) {
            if ($validated['verification_status'] === 'verified') {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }

        if (! empty($validated['account_status'])) {
            $this->applyAccountStatusFilter($query, $validated['account_status']);
        }

        $sortColumn = $validated['sort_by'] ?? 'created_at';
        $sortDirection = $validated['sort_direction'] ?? 'desc';

        $query->orderBy($sortColumn, $sortDirection)->orderByDesc('created_at');

        $users = $query->paginate($validated['per_page'] ?? 15)->withQueryString();
        $users->getCollection()->each->normalizeAccountStatus();

        return response()->json([
            'message' => 'Users fetched successfully.',
            'data' => [
                'items' => AdminUserResource::collection($users->getCollection())->resolve(),
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'from' => $users->firstItem(),
                    'to' => $users->lastItem(),
                ],
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->normalizeAccountStatus();
        $user->loadMissing(['chefNiche:id,name,slug,description', 'chefNiches:id,name,slug,description'])
            ->loadCount(['recipes', 'notifications', 'bookmarkedRecipes']);

        return response()->json([
            'message' => 'User fetched successfully.',
            'data' => (new AdminUserResource($user))->resolve(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateUserPayload($request);
        $profilePicturePath = $request->hasFile('profile_picture')
            ? $request->file('profile_picture')->store('profiles', 'public')
            : null;

        $role = $validated['role'] ?? User::ROLE_USER;
        $chefNicheIds = $this->extractChefNicheIds($validated);

        $user = User::query()->create([
            'name' => $validated['name'],
            'chef_name' => $role === User::ROLE_CHEF ? ($validated['chef_name'] ?? null) : null,
            'username' => isset($validated['username']) && $validated['username'] !== ''
                ? strtolower((string) $validated['username'])
                : $this->usernameService->generate($validated['name']),
            'email' => strtolower((string) $validated['email']),
            'phone' => $validated['phone'] ?? null,
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'address' => $validated['address'] ?? null,
            'role' => $role,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
            'chef_niche_id' => $role === User::ROLE_CHEF ? $chefNicheIds->first() : null,
            'profile_picture' => $profilePicturePath,
            'onboarding_completed' => (bool) ($validated['onboarding_completed'] ?? false),
            'email_verified_at' => ($validated['is_verified'] ?? true) ? now() : null,
            'password' => null,
        ]);

        $this->referralService->ensureUserReferralCode($user);
        $this->syncChefNiches($user, $role, $chefNicheIds);

        $freshUser = $this->loadUserForAdmin($user->fresh() ?? $user);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => (new AdminUserResource($freshUser))->resolve(),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $this->validateUserPayload($request, $user, true);
        $role = $validated['role'] ?? $user->role;
        $chefNicheIds = $this->extractChefNicheIds($validated);
        $payload = [];

        foreach (['name', 'phone', 'date_of_birth', 'address', 'chef_name', 'onboarding_completed'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('username', $validated)) {
            $payload['username'] = strtolower((string) $validated['username']);
        }

        if (array_key_exists('email', $validated)) {
            $payload['email'] = strtolower((string) $validated['email']);
        }

        if (array_key_exists('role', $validated)) {
            $payload['role'] = $role;
        }

        if (array_key_exists('is_verified', $validated)) {
            $payload['email_verified_at'] = $validated['is_verified'] ? ($user->email_verified_at ?? now()) : null;
        }

        if ($request->hasFile('profile_picture')) {
            $payload['profile_picture'] = $request->file('profile_picture')->store('profiles', 'public');
        }

        if ($role !== User::ROLE_CHEF) {
            $payload['chef_name'] = null;
            $payload['chef_niche_id'] = null;
        } elseif ($chefNicheIds->isNotEmpty()) {
            $payload['chef_niche_id'] = $chefNicheIds->first();
        }

        $oldProfilePicture = $user->getRawOriginal('profile_picture');
        $user->update($payload);

        if ($request->hasFile('profile_picture')) {
            $this->deleteStoredFileIfLocal($oldProfilePicture);
        }

        $this->syncChefNiches($user, $role, $chefNicheIds, array_key_exists('chef_niche_id', $validated) || array_key_exists('chef_niche_ids', $validated));
        $freshUser = $this->loadUserForAdmin($user->fresh() ?? $user);

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => (new AdminUserResource($freshUser))->resolve(),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->guardAgainstSelfAction($request, $user);

        $profilePicture = $user->getRawOriginal('profile_picture');
        $this->deleteUserWithRetry($user);
        $this->deleteStoredFileIfLocal($profilePicture);

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    public function warn(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:4000'],
            'channels' => ['nullable', 'array', 'min:1'],
            'channels.*' => ['in:in_app,email,push,all'],
        ]);

        $user->increment('warning_count');
        $user->forceFill([
            'last_warned_at' => now(),
        ])->save();

        $summary = $this->notificationService->sendToUser(
            user: $user->fresh() ?? $user,
            title: $validated['title'] ?? 'Account warning',
            message: $validated['message'],
            category: NotificationService::CATEGORY_ADMIN,
            channels: $validated['channels'] ?? [NotificationService::CHANNEL_IN_APP],
            actionUrl: null,
            imageUrl: null,
            data: [
                'type' => 'warning',
                'warning_count' => (int) (($user->fresh()?->warning_count) ?? $user->warning_count),
            ],
            actor: $request->user(),
        );

        $freshUser = $this->loadUserForAdmin($user->fresh() ?? $user);

        return response()->json([
            'message' => 'Warning sent successfully.',
            'data' => [
                'user' => (new AdminUserResource($freshUser))->resolve(),
                'summary' => $summary,
            ],
        ]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->guardAgainstSelfAction($request, $user);

        $validated = $request->validate([
            'duration' => ['required', Rule::in(['24_hours', '7_days', '30_days', 'indefinite'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $suspendedUntil = match ($validated['duration']) {
            '24_hours' => now()->addDay(),
            '7_days' => now()->addDays(7),
            '30_days' => now()->addDays(30),
            default => null,
        };

        $user->update([
            'account_status' => User::ACCOUNT_STATUS_SUSPENDED,
            'suspended_until' => $suspendedUntil,
            'suspension_reason' => $validated['reason'] ?? null,
            'banned_at' => null,
            'banned_reason' => null,
        ]);

        $freshUser = $this->loadUserForAdmin($user->fresh() ?? $user);

        $this->notificationService->sendToUser(
            user: $freshUser,
            title: 'Account suspended',
            message: $suspendedUntil
                ? 'Your account has been suspended until '.$suspendedUntil->toDayDateTimeString().'.'
                : 'Your account has been suspended indefinitely.',
            category: NotificationService::CATEGORY_ADMIN,
            channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_EMAIL],
            actionUrl: null,
            imageUrl: null,
            data: [
                'type' => 'suspension',
                'reason' => $validated['reason'] ?? null,
                'suspended_until' => $suspendedUntil?->toIso8601String(),
            ],
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'User suspended successfully.',
            'data' => (new AdminUserResource($freshUser))->resolve(),
        ]);
    }

    public function ban(Request $request, User $user): JsonResponse
    {
        $this->guardAgainstSelfAction($request, $user);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $user->update([
            'account_status' => User::ACCOUNT_STATUS_BANNED,
            'banned_at' => now(),
            'banned_reason' => $validated['reason'] ?? null,
            'suspended_until' => null,
            'suspension_reason' => null,
        ]);

        $freshUser = $this->loadUserForAdmin($user->fresh() ?? $user);

        $this->notificationService->sendToUser(
            user: $freshUser,
            title: 'Account banned',
            message: 'Your account has been banned by an administrator.',
            category: NotificationService::CATEGORY_ADMIN,
            channels: [NotificationService::CHANNEL_IN_APP, NotificationService::CHANNEL_EMAIL],
            actionUrl: null,
            imageUrl: null,
            data: [
                'type' => 'ban',
                'reason' => $validated['reason'] ?? null,
                'banned_at' => $freshUser->banned_at?->toIso8601String(),
            ],
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'User banned successfully.',
            'data' => (new AdminUserResource($freshUser))->resolve(),
        ]);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->guardAgainstSelfAction($request, $user);

        $user->update([
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
            'suspended_until' => null,
            'suspension_reason' => null,
            'banned_at' => null,
            'banned_reason' => null,
        ]);

        $freshUser = $this->loadUserForAdmin($user->fresh() ?? $user);

        $this->notificationService->sendToUser(
            user: $freshUser,
            title: 'Account reactivated',
            message: 'Your account has been restored by an administrator.',
            category: NotificationService::CATEGORY_ADMIN,
            channels: [NotificationService::CHANNEL_IN_APP],
            actionUrl: null,
            imageUrl: null,
            data: [
                'type' => 'activation',
            ],
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'User activated successfully.',
            'data' => (new AdminUserResource($freshUser))->resolve(),
        ]);
    }

    public function growthChart(Request $request): JsonResponse
    {
        $period = $this->resolvePeriod($request);
        $users = User::query()
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->get(['id', 'created_at']);

        $series = $this->buildDateSeries(
            start: $period['start']->copy()->startOfDay(),
            end: $period['end']->copy()->startOfDay(),
            seed: 0,
            callback: function (Carbon $day) use ($users): int {
                return $users
                    ->filter(fn (User $user): bool => $user->created_at?->isSameDay($day) === true)
                    ->count();
            },
        );

        $cumulative = User::query()->where('created_at', '<', $period['start'])->count();

        $series = array_map(function (array $point) use (&$cumulative): array {
            $cumulative += $point['value'];

            return [
                'date' => $point['date'],
                'label' => $point['label'],
                'new_users' => $point['value'],
                'cumulative_users' => $cumulative,
            ];
        }, $series);

        return response()->json([
            'message' => 'User growth chart fetched successfully.',
            'data' => [
                'period' => $period['label'],
                'start_date' => $period['start']->toDateString(),
                'end_date' => $period['end']->toDateString(),
                'summary' => [
                    'total_users' => User::query()->count(),
                    'new_users_in_range' => array_sum(array_column($series, 'new_users')),
                ],
                'series' => $series,
            ],
        ]);
    }

    public function activityChart(Request $request): JsonResponse
    {
        $period = $this->resolvePeriod($request);
        $activities = UserDailyActivity::query()
            ->whereBetween('activity_date', [$period['start']->toDateString(), $period['end']->toDateString()])
            ->get(['user_id', 'activity_date', 'hits']);

        $series = $this->buildDateSeries(
            start: $period['start']->copy()->startOfDay(),
            end: $period['end']->copy()->startOfDay(),
            seed: ['active_users' => 0, 'hits' => 0],
            callback: function (Carbon $day) use ($activities): array {
                $items = $activities->filter(
                    fn (UserDailyActivity $activity): bool => $activity->activity_date?->isSameDay($day) === true
                );

                return [
                    'active_users' => $items->pluck('user_id')->unique()->count(),
                    'hits' => (int) $items->sum('hits'),
                ];
            },
        );

        $activeUsersToday = UserDailyActivity::query()
            ->where('activity_date', now()->toDateString())
            ->distinct('user_id')
            ->count('user_id');

        return response()->json([
            'message' => 'User activity chart fetched successfully.',
            'data' => [
                'period' => $period['label'],
                'start_date' => $period['start']->toDateString(),
                'end_date' => $period['end']->toDateString(),
                'summary' => [
                    'active_users_today' => $activeUsersToday,
                    'active_users_in_range' => $activities->pluck('user_id')->unique()->count(),
                    'total_hits_in_range' => (int) $activities->sum('hits'),
                ],
                'series' => array_map(static fn (array $point): array => [
                    'date' => $point['date'],
                    'label' => $point['label'],
                    'active_users' => $point['value']['active_users'],
                    'hits' => $point['value']['hits'],
                ], $series),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateUserPayload(Request $request, ?User $user = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'username' => [$partial ? 'sometimes' : 'nullable', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => [$required, 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:30'],
            'date_of_birth' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'date', 'before:today'],
            'address' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:500'],
            'role' => [$partial ? 'sometimes' : 'nullable', Rule::in([User::ROLE_USER, User::ROLE_CHEF, User::ROLE_ADMIN])],
            'chef_name' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:120'],
            'chef_niche_id' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'uuid', Rule::exists('chef_niches', 'id')->where('is_active', true)],
            'chef_niche_ids' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'array', 'min:1'],
            'chef_niche_ids.*' => ['uuid', 'distinct', Rule::exists('chef_niches', 'id')->where('is_active', true)],
            'onboarding_completed' => [$partial ? 'sometimes' : 'nullable', 'boolean'],
            'is_verified' => [$partial ? 'sometimes' : 'nullable', 'boolean'],
            'profile_picture' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'image', 'max:5120'],
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function extractChefNicheIds(array $validated): Collection
    {
        return collect($validated['chef_niche_ids'] ?? [($validated['chef_niche_id'] ?? null)])
            ->filter()
            ->unique()
            ->values();
    }

    private function syncChefNiches(User $user, string $role, Collection $chefNicheIds, bool $shouldSync = true): void
    {
        if ($role !== User::ROLE_CHEF) {
            $user->chefNiches()->sync([]);

            return;
        }

        if ($shouldSync && $chefNicheIds->isNotEmpty()) {
            $user->chefNiches()->sync($chefNicheIds->all());
        }
    }

    private function loadUserForAdmin(User $user): User
    {
        $user->normalizeAccountStatus();
        $user->loadMissing(['chefNiche:id,name,slug,description', 'chefNiches:id,name,slug,description'])
            ->loadCount(['recipes', 'notifications', 'bookmarkedRecipes']);

        return $user;
    }

    /**
     * @return array{start:Carbon,end:Carbon,label:string}
     */
    private function resolvePeriod(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['7d', '30d', '90d'])],
        ]);

        $period = $validated['period'] ?? '30d';
        $end = now()->endOfDay();
        $days = match ($period) {
            '7d' => 6,
            '90d' => 89,
            default => 29,
        };

        return [
            'start' => now()->subDays($days)->startOfDay(),
            'end' => $end,
            'label' => $period,
        ];
    }

    /**
     * @param callable(Carbon): mixed $callback
     * @return array<int, array{date:string,label:string,value:mixed}>
     */
    private function buildDateSeries(Carbon $start, Carbon $end, mixed $seed, callable $callback): array
    {
        $cursor = $start->copy();
        $series = [];

        while ($cursor->lte($end)) {
            $value = $callback($cursor) ?? $seed;

            $series[] = [
                'date' => $cursor->toDateString(),
                'label' => $cursor->format('M d'),
                'value' => $value,
            ];

            $cursor->addDay();
        }

        return $series;
    }

    private function applyAccountStatusFilter($query, string $status): void
    {
        if ($status === User::ACCOUNT_STATUS_BANNED) {
            $query->where('account_status', User::ACCOUNT_STATUS_BANNED);

            return;
        }

        if ($status === User::ACCOUNT_STATUS_SUSPENDED) {
            $query->where('account_status', User::ACCOUNT_STATUS_SUSPENDED)
                ->where(function ($inner): void {
                    $inner->whereNull('suspended_until')
                        ->orWhere('suspended_until', '>', now());
                });

            return;
        }

        $query->where(function ($inner): void {
            $inner->where('account_status', User::ACCOUNT_STATUS_ACTIVE)
                ->orWhere(function ($expired): void {
                    $expired->where('account_status', User::ACCOUNT_STATUS_SUSPENDED)
                        ->whereNotNull('suspended_until')
                        ->where('suspended_until', '<=', now());
                });
        });
    }

    private function deleteStoredFileIfLocal(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function guardAgainstSelfAction(Request $request, User $target): void
    {
        if ($request->user()?->id !== $target->id) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'You cannot perform this action on your own account.',
        ], 422));
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
