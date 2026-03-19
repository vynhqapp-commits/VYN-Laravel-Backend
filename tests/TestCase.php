<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /** Assign a role by name using the api guard. */
    protected function assignRole(User $user, string $role): void
    {
        $user->assignRole(Role::findByName($role, 'api'));
    }
}
