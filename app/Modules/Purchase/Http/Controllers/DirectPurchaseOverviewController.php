<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Engines\Overview\ConfirmOverview;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Purchase\Http\Requests\DirectPurchaseRules;
use App\Modules\Purchase\Services\DirectPurchaseOverview;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ সরাসরি ক্রয়ের সারাংশ — "নিশ্চিত করুন" চাপলে পপ-আপের ভিতরে যা আঁকা হয় (মালিক, ৪ অক্টোবর ২০২৬;
 * [[confirm-overview.js]], [[ConfirmOverview]])।
 *
 * ⓘ ফর্মের একই ঘর, জমার একই যাচাই ([[DirectPurchaseRules::store()]] আর লটের দাবি) আর বিলের একই অঙ্ক
 * ([[DirectPurchaseOverview]])। কিছুই লেখে না। চাবি জমার দরজারই (`purchase.bill.create`)।
 * ⚠️ `validate()` নয় — পপ-আপের অনুরোধ JSON চায় না, তাই ছুঁড়লে উত্তর হত পেছনে ফেরা আর পপ-আপে ঢুকত পুরো পাতা।
 * ভুলগুলো তাই সারাংশেই — "থামবে" সুরে, পপ-আপের "নিশ্চিত" বন্ধ। অঙ্কের দেয়ালও (বিলের ছাড় মোটের বেশি, ভ্যাট বন্ধ
 * অথচ ভ্যাট লেখা) একই পথে আসে, কারণ সারাংশ বিলের অঙ্কের পদ্ধতিগুলোই ডাকে।
 * ⓘ টাকার খাত আর পদ্ধতির মিল ([[MoneyAccountRule]], [[MethodFitsAccount]]) জমার সময়ই দেখা হয় — পর্দা ভুলটা
 * সেই ঘরের নিচেই দেখায়।
 */
class DirectPurchaseOverviewController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:purchase.bill.create')];
    }

    public function __invoke(Request $request, DirectPurchaseOverview $overview): View
    {
        $input = $request->all();
        $check = Validator::make($input, DirectPurchaseRules::store(CompanyContext::id(), $input), DirectPurchaseRules::messages());

        if ($check->fails()) {
            return $this->notReady($check->errors()->all());
        }

        $data = $check->validated();

        try {
            $sheet = $overview->build($data);
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
