<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Supplier\Reports\PrincipalCommission;
use App\Modules\Supplier\Services\PrincipalCommissionFigures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐ ফোনের প্রিন্সিপালের কমিশনের পর্দা — `GET /api/v1/supplier/principal-commission?month=YYYY-MM` (মালিক, fe-র মারফত,
 * ২০ অক্টোবর ২০২৬: *"app e dekhate hobe"*; চুক্তি `pc/CONTRACT.md` §১)।
 *
 * ⓘ সারি আর মোট ওয়েবের রিপোর্ট আর সরবরাহকারী-ড্যাশবোর্ডের বাক্সের হুবহু ([[PrincipalCommissionFigures]]); `month` খালি হলে
 * প্রতিটা প্রিন্সিপালের চলতি চক্র, দিলে সেই মাসে শেষ হওয়া চক্র — ওয়েবের মাস বাছার একই নিয়ম ([[PrincipalCommission::month()]];
 * ভুল আকারে ৪২২)।
 *
 * ⛔ পাহারা: টোকেন, `abilities:app`, কোম্পানির প্রসঙ্গ, ফোনে ক্রয় বন্ধ হলে ৪০৩ `module_off` (রুট ফাইলে); চাবি
 * `supplier.report` (রুটে `can:`, ওয়েবের রিপোর্টের একই); ওয়েবে পর্দাটা বন্ধ হলে ৪০৪ — ওয়েবের একই উত্তর।
 * ⛔ উত্তরে টাকা — কোনো ক্যাশে নয় (`no-store, private`; উইজেটের নিরাপত্তা-পর্যালোচনা ৮-এর একই নিয়ম)।
 * ⓘ কেবল পড়া; খাতায় কিছু বসে না।
 */
class PrincipalCommissionApiController extends Controller
{
    /** ⓘ উইজেটের দরজার একই মান ([[WidgetSummaryController::NO_STORE]]) */
    public const NO_STORE = 'no-store, private';

    public function __invoke(Request $request, PrincipalCommissionFigures $figures): JsonResponse
    {
        abort_if(PrincipalCommissionFigures::screenOff(), 404, __('core.message.screen_switched_off'));

        // ⓘ যাচাই ওয়েবের একই জায়গায় — ভুল আকারে ৪২২, নীরবে চলতি চক্র নয়
        $month = PrincipalCommission::month($request->query('month'))?->format('Y-m');

        ['rows' => $rows, 'total' => $total] = $figures->figures($month);

        return response()->json([
            'month' => $month,
            'rows' => $rows,
            'total' => $total,
            // ⭐ ওয়েবের একই পাতা, একই মাসে — "ওয়েবে খুলুন"
            'web_url' => route('supplier.report.show', array_filter(['slug' => 'principal-commission', 'month' => $month]), false),
        ])->header('Cache-Control', self::NO_STORE);
    }
}
