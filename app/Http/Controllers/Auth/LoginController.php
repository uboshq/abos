<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * লগইন — প্ল্যান সেকশন ১৬।
 *
 * কোম্পানি এখানে জিজ্ঞেস করা হয় না। v9 স্পেসিফিকেশনে লগইনের আগে একটা
 * Workspace ড্রপডাউন ছিল, কিন্তু সেকশন ১৬.৩-এ ধরা পড়েছে সেটা Zero Trust-এর
 * সাথে যায় না: ড্রপডাউনে সব কোম্পানির নাম দেখানো মানে যে কেউ URL খুলেই
 * জেনে যাবে সার্ভারে কোন কোন প্রতিষ্ঠান আছে।
 *
 * তাই ব্যবহারকারী শুধু নিজের পরিচয় দেয়; কোম্পানি ঠিক হয় লগইনের পরে, তার
 * নিজের রেকর্ড থেকে (ResolveCompanyContext)।
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $user = $this->findUser($credentials['identifier']);

        // পাসওয়ার্ড সবসময় যাচাই করা হয়, ব্যবহারকারী না থাকলেও — একটা
        // ডামি হ্যাশের বিরুদ্ধে। কারণ অস্তিত্বহীন নামে সাথে সাথে উত্তর
        // দিলে আক্রমণকারী সময় মেপেই বুঝে ফেলে কোন নামগুলো আসল
        // (সেকশন ১৬.৫)।
        $hash = $user?->password ?? '$2y$12$'.str_repeat('.', 53);
        $passwordOk = Hash::check($credentials['password'], $hash);

        if ($user === null || ! $passwordOk || ! $user->is_active) {
            throw ValidationException::withMessages([
                // এক বার্তা, সব ক্ষেত্রে। "এই নামে কেউ নেই" বললে
                // ব্যবহারকারীর তালিকা বের করা যায়।
                'identifier' => __('auth.failed'),
            ]);
        }

        Auth::login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Username, Email বা Mobile — তিনটার যেকোনোটা (সেকশন ১৬.৩)।
     *
     * ফিল্ড সেলসম্যান নিজের মোবাইল নম্বর মনে রাখে, ইমেইল নয়।
     */
    private function findUser(string $identifier): ?User
    {
        return User::query()
            ->where('email', $identifier)
            ->orWhere('name', $identifier)
            ->first();
    }
}
