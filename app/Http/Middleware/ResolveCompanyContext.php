<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Support\CompanyContext;
use App\Models\FinancialYear;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * প্রতিটা রিকোয়েস্টের শুরুতে ঠিক করা হয় এটা কোন কোম্পানির।
 *
 * উৎস একটাই: ব্যবহারকারীর রেকর্ডে সেভ করা পছন্দ। URL বা সেশন থেকে নেওয়া হয়
 * না — URL থেকে নিলে যে কেউ প্যারামিটার বদলে অন্য কোম্পানিতে ঢুকে পড়ত,
 * আর সেশন থেকে নিলে রিলোডে হারিয়ে যেত (DMS-এ ঠিক সেটাই হত)।
 *
 * ভাষাও এখানেই বসে, কারণ সেটাও ব্যবহারকারীর রেকর্ডে থাকে (নিয়ম ৯)।
 */
class ResolveCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            CompanyContext::clear();

            return $next($request);
        }

        app()->setLocale($user->locale ?? config('app.locale'));

        $companyId = $user->current_company_id;

        // প্রথমবার লগইন, বা যে কোম্পানিতে ছিল সেটা থেকে সরিয়ে দেওয়া হয়েছে।
        if ($companyId === null || ! $user->canAccessCompany($companyId)) {
            $companyId = $user->companies()->orderBy('companies.id')->value('companies.id');

            if ($companyId === null) {
                CompanyContext::clear();

                return $next($request);
            }

            $user->switchCompany($companyId);
            $user->refresh();
        }

        CompanyContext::set($companyId, $user->current_branch_id);

        // অর্থবছর প্রসঙ্গে বসানো হয় স্কোপ বসার পরে — নাহলে FinancialYear-এর
        // নিজের কোম্পানি-স্কোপ কিছুই খুঁজে পেত না।
        $financialYearId = FinancialYear::query()->where('is_current', true)->value('id');

        CompanyContext::set($companyId, $user->current_branch_id, $financialYearId);

        return $next($request);
    }
}
