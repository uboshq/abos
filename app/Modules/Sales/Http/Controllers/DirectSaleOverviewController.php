<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\DirectSaleRules;
use App\Modules\Sales\Services\DirectSaleOverview;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * ⭐ ওয়েবের কাউন্টারের সারাংশ — "নিশ্চিত করুন" চাপলে পপ-আপের ভিতরে যা আঁকা হয় (মালিক, ৪ অক্টোবর ২০২৬;
 * [[confirm-overview.js]], [[ConfirmOverview]])।
 *
 * ⓘ ফর্মের একই ঘর, ওয়েবের কাউন্টারের একই যাচাই ([[DirectSaleRules::store()]]) আর একই সারাংশ ([[DirectSaleOverview]]) —
 * ফোনের দরজা ([[DirectSaleApiController::overview()]]) ঠিক এটাই আঁকে, কেবল JSON-এ। কিছুই লেখে না।
 * ⓘ চাবি কাউন্টারের (`sales.challan.create`)।
 */
class DirectSaleOverviewController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.create')];
    }

    public function __invoke(Request $request, DirectSaleOverview $overview): View
    {
        $input = $request->all();
        $data = Validator::make($input, DirectSaleRules::store(CompanyContext::id(), $input))->validate();

        return view('ui.confirm-overview-body', ['overview' => $overview->build($data, $data['lines'])->toArray()]);
    }
}
