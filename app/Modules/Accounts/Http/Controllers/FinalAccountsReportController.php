<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * লাভ-ক্ষতি আর নগদ প্রবাহ — মেনু যে চাবি চায়, দরজাও ঠিক সেটাই চায় (১ অক্টোবর ২০২৬, নিরীক্ষা §৮)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * মেনুর সারি চাইত কেবল `accounts.report.final`। ⓘ কিন্তু পাতাটা সাধারণ রিপোর্টের দরজা দিয়ে খুলত,
 * আর সেই দরজা আগে `accounts.report` চাইত — তারপর ভেতরে আবার `final`। ⇒ যাঁর কেবল চূড়ান্ত হিসাব
 * দেখার চাবি (মালিকের পাঠক, নিরীক্ষক), তিনি মেনুতে সারিটা দেখতেন আর ক্লিক করলে ৪০৩।
 *
 * ⭐ স্থিতিপত্রের মতোই নিজের দরজা ([[BalanceSheetController]] — `can:accounts.report.final`)।
 * কাজটা একই রিপোর্ট-ইঞ্জিনের পাতা, তাই আঁকা [[ReportController::show()]]-এই — নকল নয়।
 */
class FinalAccountsReportController extends Controller implements HasMiddleware
{
    /** এই দরজার slug-গুলো — রুটের `whereIn` এখান থেকে পড়ে। */
    public const SLUGS = ['profit-loss', 'cash-flow'];

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.report.final')];
    }

    public function show(Request $request, string $slug): View
    {
        return app(ReportController::class)->show($request, $slug);
    }
}
