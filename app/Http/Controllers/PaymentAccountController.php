<?php

namespace App\Http\Controllers;

/** Compatibility boundary: the supported financial ledger is AccountController. */
class PaymentAccountController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()->can('account.access'), 403);
            return $next($request);
        });
    }

    public function index()
    {
        return redirect()->action([AccountController::class, 'index']);
    }

    public function retired()
    {
        // Never map obsolete IDs to the separate accounts table or mutate legacy data.
        abort(410, 'This legacy endpoint has been retired. Use the accounts workspace.');
    }
}
