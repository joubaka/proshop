<?php

namespace App\Http\Controllers;

use App\PaymentAccount;
use Illuminate\Http\Request;

class PaymentAccountController extends Controller
{
    public function index()
    {
        $payment_accounts = PaymentAccount::all();
        return view('payment_account.index', compact('payment_accounts'));
    }

    public function create()
    {
        $account_types = PaymentAccount::account_types();
        return view('payment_account.create', compact('account_types'));
    }

    public function store(Request $request)
    {
        $data = $request->only(['name', 'account_type', 'note']);
        PaymentAccount::create($data);
        $output = ['success' => true, 'msg' => __('lang_v1.success')];
        return redirect()->route('payment-account.index')->with('status', $output);
    }

    public function show($id)
    {
        $payment_account = PaymentAccount::findOrFail($id);
        return view('payment_account.show', compact('payment_account'));
    }

    public function edit($id)
    {
        $payment_account = PaymentAccount::findOrFail($id);
        $account_types = PaymentAccount::account_types();
        return view('payment_account.edit', compact('payment_account', 'account_types'));
    }

    public function update(Request $request, $id)
    {
        $payment_account = PaymentAccount::findOrFail($id);
        $data = $request->only(['name', 'account_type', 'note']);
        $payment_account->update($data);
        $output = ['success' => true, 'msg' => __('lang_v1.updated_successfully')];
        return redirect()->route('payment-account.index')->with('status', $output);
    }

    public function destroy($id)
    {
        PaymentAccount::findOrFail($id)->delete();
        $output = ['success' => true, 'msg' => __('lang_v1.deleted_successfully')];
        return redirect()->route('payment-account.index')->with('status', $output);
    }
}
