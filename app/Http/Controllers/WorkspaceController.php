<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * শেলের নিজের কাজ — ড্যাশবোর্ড, কোম্পানি সুইচ, ভাষা সুইচ।
 *
 * মডিউলের কোনো কাজ এখানে নেই এবং থাকবে না; মডিউল নিজের কন্ট্রোলার নিজের
 * ফোল্ডারে রাখে (সেকশন ১৯.১)।
 */
class WorkspaceController extends Controller
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public function dashboard(Request $request): View
    {
        return view('workspace.dashboard', [
            'menu' => $this->menu->forUser($request->user()),
        ]);
    }

    /**
     * কোম্পানি বদলানো।
     *
     * switchCompany() নিজেই যাচাই করে ব্যবহারকারীর ওই কোম্পানিতে ঢোকার
     * অধিকার আছে কি না — এখানে আবার করা হয় না, কারণ দুই জায়গায় একই
     * যাচাই মানে একদিন একটা বদলাবে আর অন্যটা থেকে যাবে।
     */
    public function switchCompany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $request->user()->switchCompany(
            (int) $validated['company_id'],
            isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
        );

        // রিডাইরেক্ট, রেন্ডার নয় — কোম্পানি বদলের পর আগের পাতার ডাটা
        // অন্য কোম্পানির, আর সেটা দেখানো মানে দুই কোম্পানি মিশে যাওয়া।
        return redirect()->route('dashboard');
    }

    public function switchLocale(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:bn,en'],
        ]);

        // ব্যবহারকারীর রেকর্ডে, সেশনে নয় — অন্য ডিভাইসেও একই ভাষা (নিয়ম ৯)।
        $request->user()->forceFill(['locale' => $validated['locale']])->save();

        return back();
    }
}
