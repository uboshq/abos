<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherOverview;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ ভাউচারের "পোস্ট করুন"-এর আগের সারাংশ — পপ-আপের ভিতরে যা আঁকা হয় (মালিক, ৪ অক্টোবর ২০২৬;
 * [[confirm-overview.js]], [[VoucherOverview]])। কিছুই লেখে না।
 *
 * ⓘ চাবি পোস্টের দরজারই — `can:update,voucher` ([[VoucherController::middleware()]], নীতি [[VoucherPolicy]]):
 * যে পোস্ট করতে পারে না, সে সারাংশও খুলতে পারে না।
 */
class VoucherOverviewController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:update,voucher')];
    }

    public function __invoke(Voucher $voucher, VoucherOverview $overview): View
    {
        return view('ui.confirm-overview-body', ['overview' => $overview->build($voucher)->toArray()]);
    }
}
