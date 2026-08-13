<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Sales\Services\BatchTrace;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * রিকল — এই লটটা কাদের কাছে গেছে।
 *
 * ── কেন অনুমতিটা চালান দেখার ─────────────────────────────────────────
 * পর্দাটা গ্রাহকের নাম ও ফোন নম্বর দেখায়, অর্থাৎ বিক্রয়ের তথ্য। যিনি
 * চালান দেখতে পারেন না, তাঁর এই তালিকাও দেখার কথা নয় — নাহলে মজুদের
 * অনুমতি দিয়ে গোটা গ্রাহক-তালিকা বেরিয়ে যেত।
 */
class LotTraceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.view')];
    }

    public function show(Request $request, BatchTrace $trace): View
    {
        $batch = Batch::query()->with('product')->find($request->integer('batch'));

        return view('sales::lot.trace', [
            'menu' => app(MenuBuilder::class)->forUser($request->user()),

            /*
             * বাছার তালিকায় কেবল সেই লটগুলো যাদের কোনো নড়াচড়া আছে।
             *
             * খোলা হয়েছে অথচ কখনো মাল ঢোকেনি — এমন লট রিকলের তালিকায়
             * থাকলে কেবল ভিড় বাড়াত।
             */
            'batches' => Batch::query()
                ->with('product')
                ->whereHas('movements')
                ->get()
                ->sortBy(fn (Batch $b) => ($b->product?->name() ?? '').$b->batch_no)
                ->values(),

            'batch' => $batch,
            'onHand' => $batch === null ? null : $trace->onHand($batch),
            'recipients' => $batch === null ? collect() : $trace->recipients($batch),
        ]);
    }
}
