<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PurchaseReturnOverview;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ ক্রয় ফেরতের "নিশ্চিত করুন"-এর আগের সারাংশ — পপ-আপের ভিতরে যা আঁকা হয় (মালিক, ৪ অক্টোবর ২০২৬;
 * [[confirm-overview.js]], [[PurchaseReturnOverview]])। কিছুই লেখে না।
 *
 * ⓘ চাবি নিশ্চিতের দরজারই — `purchase.return.create` ([[PurchaseReturnController]]-এর `confirm`); আর কাগজটা দেখার
 * অধিকার (`view` নীতি), যাতে অন্য শাখার ফেরতের সারাংশ ঠিকানা বদলে খোলা না যায়।
 */
class PurchaseReturnOverviewController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:purchase.return.create')];
    }

    public function __invoke(PurchaseReturn $return, PurchaseReturnOverview $overview): View
    {
        $this->authorize('view', $return);

        return view('ui.confirm-overview-body', ['overview' => $overview->build($return)->toArray()]);
    }
}
