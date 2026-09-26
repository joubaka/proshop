<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    private array $permissions = [
        'shop.orders.view',
        'shop.orders.fulfil',
        'shop.catalog.view',
        'shop.catalog.manage',
        'shop.payments.review',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ($this->permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::query()->where('name', 'like', 'Admin#%')->each(function (Role $role) {
            $role->givePermissionTo($this->permissions);
        });

        Role::query()->whereHas('permissions', fn ($query) => $query->whereIn('name', ['sell.view', 'sell.create']))
            ->each(fn (Role $role) => $role->givePermissionTo('shop.orders.view'));
        Role::query()->whereHas('permissions', fn ($query) => $query->whereIn('name', ['sell.update', 'sell.create']))
            ->each(fn (Role $role) => $role->givePermissionTo('shop.orders.fulfil'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->where('guard_name', 'web')->whereIn('name', $this->permissions)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
