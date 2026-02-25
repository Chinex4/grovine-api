<?php

namespace App\Services;

use App\Models\User;

class UsernameService
{
    public function generate(string $name): string
    {
        $base = str()->of($name)->lower()->slug('_')->value();
        $base = $base !== '' ? $base : 'grovine_user';

        do {
            $username = $base.'_'.random_int(1000, 9999);
        } while (User::query()->where('username', $username)->exists());

        return $username;
    }
}
