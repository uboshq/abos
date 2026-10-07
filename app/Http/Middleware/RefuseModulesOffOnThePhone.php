<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Services\PhoneModules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⛔ ফোনে বন্ধ মডিউলের দরজা — মেনু লুকানো দেয়াল নয় (১ অক্টোবর ২০২৬)।
 *
 * ⓘ মডিউলটা দুইভাবে আসে: রুটে লেখা (`phone.module:approval`), নয়তো রুটের
 * `{module}` থেকে (সিঙ্কের দরজা — সিঙ্কের মডিউলের নাম মডিউলের কোডই)।
 * নিয়ম আর উত্তর এক জায়গায় — [[PhoneModules::refuseUnlessReachable()]]।
 *
 * ⚠️ চলে [[ResolveCompanyContext]]-এর **পরে** — সুইচ কোম্পানির, আর কোম্পানি
 * ব্যবহারকারীর সারি থেকে আসে, ফোনের পাঠানো কিছু থেকে নয়।
 */
final class RefuseModulesOffOnThePhone
{
    public function __construct(private readonly PhoneModules $phone) {}

    public function handle(Request $request, Closure $next, ?string $module = null): Response
    {
        $module ??= $request->route('module');

        $this->phone->refuseUnlessReachable(is_string($module) ? $module : null);

        return $next($request);
    }
}
