<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Support\UserLocale;
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
            app()->setLocale(UserLocale::fromHeader($request->header('Accept-Language')));
        }

        return $next($request);
    }
}
