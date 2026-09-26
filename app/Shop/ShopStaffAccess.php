<?php

namespace App\Shop;

use App\User;

class ShopStaffAccess
{
    public static function allows(?User $user, int $businessId, string $permission): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->can('superadmin')) {
            return true;
        }

        if ((int) $user->business_id !== $businessId) {
            return false;
        }

        return $user->hasRole('Admin#'.$businessId)
            || $user->can($permission);
    }
}
