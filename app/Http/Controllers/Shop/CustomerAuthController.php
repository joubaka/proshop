<?php

namespace App\Http\Controllers\Shop;

use App\Contact;
use App\Http\Controllers\Controller;
use App\Shop\CatalogService;
use App\Shop\Customer;
use App\Shop\CustomerContactLink;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class CustomerAuthController extends Controller
{
    public function loginForm() { return view('shop.account.login'); }
    public function registerForm() { return view('shop.account.register'); }

    public function register(Request $request, CatalogService $catalog)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:shop_customers,email'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
            'terms' => ['accepted'],
        ]);
        $customer = Customer::create([
            'uuid' => (string) Str::uuid(), 'name' => trim($data['name']),
            'email' => Str::lower(trim($data['email'])), 'mobile' => $data['mobile'] ?? null,
            'password' => Hash::make($data['password']),
        ]);
        $channel = $catalog->channel();
        $matches = Contact::query()->where('business_id', $channel->business_id)
            ->whereIn('type', ['customer', 'both'])->where('contact_status', 'active')
            ->whereRaw('LOWER(email) = ?', [$customer->email])->get();
        foreach ($matches as $contact) {
            CustomerContactLink::firstOrCreate([
                'shop_customer_id' => $customer->id, 'business_id' => $channel->business_id,
                'contact_id' => $contact->id,
            ], ['status' => 'pending', 'verification_method' => 'staff_review']);
        }
        Auth::guard('shop_customer')->login($customer);
        $request->session()->regenerate();
        $customer->sendEmailVerificationNotification();

        return redirect()->route('shop.account.verification.notice');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $credentials['email'] = Str::lower(trim($credentials['email']));
        $credentials['active'] = true;
        if (!Auth::guard('shop_customer')->attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The supplied customer credentials are not valid.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        Auth::guard('shop_customer')->user()->forceFill(['last_login_at' => now()])->save();
        return redirect()->intended(route('shop.account.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::guard('shop_customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('shop.home');
    }

    public function verificationNotice() { return view('shop.account.verify-email'); }

    public function verify(Request $request, string $id, string $hash)
    {
        $customer = Auth::guard('shop_customer')->user();
        abort_unless((string) $customer->getKey() === $id
            && hash_equals($hash, sha1($customer->getEmailForVerification())), 403);
        if (!$customer->hasVerifiedEmail()) { $customer->markEmailAsVerified(); }
        return redirect()->route('shop.account.dashboard')->with('status', 'Email address verified.');
    }

    public function resend(Request $request)
    {
        $customer = Auth::guard('shop_customer')->user();
        if (!$customer->hasVerifiedEmail()) { $customer->sendEmailVerificationNotification(); }
        return back()->with('status', 'Verification email sent.');
    }

    public function forgotForm() { return view('shop.account.forgot-password'); }
    public function forgot(Request $request)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'email']]);
        Password::broker('shop_customers')->sendResetLink(['email' => Str::lower(trim($request->email))]);
        return back()->with('status', 'If that customer account exists, a reset link has been sent.');
    }
    public function resetForm(Request $request, string $token) { return view('shop.account.reset-password', ['token' => $token, 'email' => $request->email]); }
    public function reset(Request $request)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'token' => ['required'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);
        $status = Password::broker('shop_customers')->reset($data, function (Customer $customer, string $password) {
            $customer->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($customer));
        });
        return $status === Password::PASSWORD_RESET
            ? redirect()->route('shop.account.login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }
}
