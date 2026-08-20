<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\FinancialYear;
use App\Models\User;
use App\Models\UserDataScope;
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

        /*
         * এই মিডলওয়্যারটা কর্মীর জন্য, ডিলারের জন্য নয়।
         *
         * ── কেন যাচাইটা দরকার হলো ───────────────────────────────────
         * গ্রাহক পোর্টাল আসার পর `$request->user()` একটা `Customer`-ও
         * হতে পারে। কিন্তু নিচের পুরো যুক্তিটা `User`-এর জিনিস ধরে
         * নিয়েছে: `companies()`, `canAccessCompany()`, `switchCompany()`
         * — ডিলারের কোনোটাই নেই, আর থাকার কথাও নয়।
         *
         * কর্মী একাধিক কোম্পানিতে থাকতে পারেন, তাই তাঁর একটা "চলতি
         * কোম্পানি" বাছাই লাগে। ডিলার একটাই কোম্পানির, আর প্রসঙ্গটা
         * তাঁর নিজের সারি থেকেই আসে (PortalController::dealer)।
         */
        if (! $user instanceof User) {
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

        /*
         * কে কোন সারি দেখবেন — এখানেই একবার, অনুরোধের শুরুতে।
         *
         * ── কেন আগেভাগে, অলসভাবে নয় ─────────────────────────────────
         * `ScopedToUserBranch` প্রথম যে কোয়েরিতে লাগে সেখানেই সীমাটা
         * খোঁজে। খরচ একই — অনুরোধপ্রতি একটা কোয়েরি, কারণ `DataScope`
         * scoped। কিন্তু **কখন** সেটা ঘটবে তা অনিশ্চিত: যে কোনো
         * কোয়েরির ঠিক আগে।
         *
         * ২০ আগস্ট সেটা কামড়েছে। `TheWholeCatalogueAsOneSheetTest`
         * মাপে "গোটা ক্যাটালগ একটাই কোয়েরিতে" — আর সীমার খোঁজটা ঠিক
         * ওই মাপা ব্লকের ভেতরে পড়ে দুইটা হয়ে গিয়েছিল। কোডটা ধীর
         * হয়নি; কেবল খরচটা অন্য কারো হিসাবে গিয়ে বসেছিল।
         *
         * এখানে বসালে খরচটা যেখানকার সেখানেই থাকে, আর পরের কেউ
         * কোয়েরি গুনতে গিয়ে একই ফাঁদে পড়ে না।
         */
        app(DataScope::class)->idsFor($user, UserDataScope::BRANCH);

        // অর্থবছর প্রসঙ্গে বসানো হয় স্কোপ বসার পরে — নাহলে FinancialYear-এর
        // নিজের কোম্পানি-স্কোপ কিছুই খুঁজে পেত না।
        $financialYearId = FinancialYear::query()->where('is_current', true)->value('id');

        CompanyContext::set($companyId, $user->current_branch_id, $financialYearId);

        return $next($request);
    }
}
