<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegressionTestCase;

class StaffPermissionRegressionTest extends RegressionTestCase
{
    public static function restrictedActions(): array
    {
        return [
            'products' => ['GET', '/products'],
            'purchases' => ['POST', '/purchases'],
            'stock transfer' => ['POST', '/stock-transfers'],
            'stock adjustment' => ['POST', '/stock-adjustments'],
            'users' => ['GET', '/users'],
            'roles' => ['GET', '/roles'],
            'tax rates' => ['POST', '/tax-rates'],
            'units' => ['POST', '/units'],
            'settings' => ['POST', '/business/update'],
            'accounts' => ['GET', '/account/account'],
            'account transaction deletion' => ['DELETE', '/account/delete-account-transaction/1'],
            'profit report' => ['GET', '/reports/profit-loss'],
            'media deletion' => ['DELETE', '/delete-media/1'],
        ];
    }

    #[DataProvider('restrictedActions')]
    public function test_staff_without_permissions_are_denied(string $method, string $uri): void
    {
        $this->createIdentitySchema();
        $this->signInWithPermissions();
        $this->json($method, $uri)->assertForbidden();
    }
}
