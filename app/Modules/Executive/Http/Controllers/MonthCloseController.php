<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Executive\Services\MonthClose\MonthClosePack;
use App\Modules\Executive\Services\MonthClose\MonthPeriods;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ মাস শেষের হিসাব — পর্দা আর A4 কাগজ, একই অঙ্ক থেকে (মালিক, ১৭ নভেম্বর ২০২৬: নমুনা "Ok", তারপর "Sob ok"; [[MonthClosePack]])।
 *
 * ⓘ মেনুতে হিসাব › বই-এ দিন-খাতার পাশে, চাবি `accounts.report.month_close`। ⓘ পাতাটা এই মডিউলে, কারণ এটা বিক্রয়, মজুদ, ক্রয়,
 * বেতন আর বাকির অঙ্ক একসাথে পড়ে — হিসাব মডিউল কারও উপর দাঁড়ায় না ([[BoundariesTest]])।
 * ⓘ মাস বন্ধ যেমন ছিল তেমনই — এখানে কেবল দেখা আর বন্ধের পাতায় যাওয়ার পথ।
 */
final class MonthCloseController extends Controller
{
    public function show(Request $request, MonthClosePack $pack, PrintEngine $print): Response
    {
        $periods = MonthPeriods::fromQuery(is_string($request->query('month')) ? $request->query('month') : null);
        $data = $pack->build($periods);

        if ($request->boolean('pdf')) {
            $result = $print->renderDocument('print.month-close', ['m' => $data], PaperSize::A4);

            return response($result['pdf'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="month-close-'.$periods->key().'.pdf"',
                'X-Month-Close-Pages' => (string) $result['pages'],
            ]);
        }

        return response()->view('executive::month-close', [
            'menu' => app(MenuBuilder::class)->forUser($request->user()),
            'm' => $data,
            'pdfUrl' => route('executive.month_close', ['month' => $periods->key(), 'pdf' => 1]),
        ]);
    }
}
