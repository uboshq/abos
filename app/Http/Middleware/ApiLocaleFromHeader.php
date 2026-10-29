<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Support\UserLocale;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ ফোনের দরজায় ভাষা — `api/*`-এ কেবল (ওয়েবের সেশনের নিয়ম ছোঁয় না)। ক্রম (fe, ২১ অক্টোবর ২০২৬):
 *   ১. ঢোকা মানুষের `users.locale` — সেটা পরে [[ResolveCompanyContext]] বসায়, এটার উপরে;
 *   ২. ঢোকা না থাকলে (লগইন, সংস্করণ, ক্র্যাশের দরজা) ফোনের `Accept-Language` — bn বা en, বাকি সব bn।
 * তাই ইংরেজি বেছে নেওয়া মানুষ লগইনের ভুল-বার্তাও ইংরেজিতে পান।
 */
final class ApiLocaleFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            // ⓘ ক্রম এখানেই ঠিক হয়, অন্য মিডলওয়্যারের সারির উপর ভরসা নয়: টোকেনে ঢোকা মানুষ থাকলে তাঁর ভাষা, নইলে হেডার।
            // `user('sanctum')` টোকেনটা এখনই চেনে, আর একই guard পরে `auth:sanctum`-কে সেই মানুষটাই দেয় (দ্বিতীয় খোঁজ নয়)।
            $user = $request->user('sanctum');
            app()->setLocale($user instanceof User && in_array($user->locale, UserLocale::SUPPORTED, true)
                ? $user->locale
                : UserLocale::fromHeader($request->header('Accept-Language')));
        }

        return $next($request);
    }
}
