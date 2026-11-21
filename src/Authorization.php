<?php

namespace DriveSnap;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

class Authorization
{
    public static function allows(?Authenticatable $user, string $action): bool
    {
        if (! $user || (method_exists($user, 'getAttribute') && $user->getAttribute('is_active') !== null && ! $user->getAttribute('is_active'))) {
            return false;
        }

        return Gate::forUser($user)->allows(config('drivesnap.abilities.view'))
            && ($action === 'view' || Gate::forUser($user)->allows(config('drivesnap.abilities.'.$action)));
    }
}
