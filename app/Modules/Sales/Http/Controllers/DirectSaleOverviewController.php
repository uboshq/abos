<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Overview\ConfirmOverview;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\DirectSaleRules;
use App\Modules\Sales\Services\DirectSaleOverview;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
        $check = Validator::make($input, DirectSaleRules::store(CompanyContext::id(), $input));

        // ⚠️ `validate()` নয় — পপ-আপের অনুরোধ JSON চায় না, তাই ছুঁড়লে উত্তর হত পেছনে ফেরা, আর পপ-আপে ঢুকত পুরো
        // কাউন্টার-পাতা। ভুলগুলো তাই সারাংশেই — "থামবে" সুরে, পপ-আপের "নিশ্চিত" বন্ধ
        if ($check->fails()) {
            return $this->notReady($check->errors()->all());
        }

        $data = $check->validated();

        /*
         * ⛔ সেবার নিজের "না"-ও সারাংশে — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬; ক্রয়ের সারাংশের একই ধাঁচ
         * ([[DirectPurchaseOverviewController]])। ⓘ সারির হিসাব (`lineFigures()`-এর ছাড়/ভ্যাটের দেয়াল) ছুঁড়লে আগে উত্তর হত
         * পেছনে ফেরা (৩০২), আর পপ-আপে বসত পুরো কাউন্টার-পাতা — ঠিক যা উপরের কথাটা এড়াতে চায়।
         */
        try {
            $sheet = $overview->build($data, $data['lines']);
        } catch (ValidationException $e) {
            return $this->notReady($e->validator->errors()->all());
        }

        return view('ui.confirm-overview-body', ['overview' => $sheet->toArray()]);
    }

    /** @param  list<string>  $messages */
    private function notReady(array $messages): View
    {
        $sheet = ConfirmOverview::titled(__('overview.not_ready'));
        foreach (array_unique($messages) as $message) {
            $sheet->note($message, 'stop');
        }

        return view('ui.confirm-overview-body', ['overview' => $sheet->toArray()]);
    }
}
