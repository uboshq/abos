<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Middleware;

use App\Core\Contracts\GuardsThePrint;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Services\TransportRule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * মাল বেরোনোর কাগজ ছাপার আগে "মাল কীভাবে যাবে" — মালিকের অনুমোদিত বদল, ১ অক্টোবর ২০২৬।
 *
 * ⓘ প্রশ্নটা নিশ্চিতের দরজা থেকে এখানে সরল: বিক্রি নিশ্চিত হয় গাড়ি ঠিক না করেও, কিন্তু চালান আর গেট পাস — যে কাগজ
 * নিয়ে মাল গেট পেরোয় — ছাপা হয় না যতক্ষণ না তিন পথের একটা বাছা ([[TransportRule::named()]])। না বাছা থাকলে চালানের
 * পাতায় ফেরে, বার্তা আর বোতামসহ।
 * ⓘ রুটের মিডলওয়্যার, ছাপার কন্ট্রোলার ছোঁয়া হয় না — চালান (`{challan}`) আর গেট পাস (`{gatePass}`) দুই দরজাই।
 * ⓘ কোম্পানি পরিবহনের ঘর বন্ধ রাখলে (`sales.field_transport` = না) প্রশ্নই নেই — [[TransportRule::applies()]]।
 */
final class RequireTransportBeforePrint implements GuardsThePrint
{
    public function __construct(private readonly TransportRule $rule) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->whyNotPrint($request) === null) {
            return $next($request);
        }

        return redirect()
            ->route('sales.challan.show', $this->challanOf($request))
            ->withErrors(['transport' => __('sales::transport.needed_before_print')])
            ->with('ask_transport', $this->challanOf($request)?->id);
    }

    /**
     * ⓘ কেবল পাকা চালানে — মাল বেরোয় ওটাতেই। বাতিল কাগজ "বাতিল" ছাপে ([[ACancelledPaperLooksValidTest]]),
     * আর খসড়ার ছাপা না-করা কন্ট্রোলারের নিজের উত্তর ([[TheDraftHeldTheLimitAndTouchedNoBookTest]]) — এখানে আগ বাড়িয়ে নয়।
     * ⭐ ফোনের দলিল-দরজাও এটাই জিজ্ঞেস করে ([[GuardsThePrint]]; অডিট ফোন ⚠️৮)।
     */
    public function whyNotPrint(Request $request): ?string
    {
        $challan = $this->challanOf($request);

        if ($challan === null || $challan->status !== DocumentStatus::CONFIRMED
            || ! $this->rule->applies() || TransportRule::named($challan)) {
            return null;
        }

        return (string) __('sales::transport.needed_before_print');
    }

    private function challanOf(Request $request): ?DeliveryChallan
    {
        $challan = $request->route('challan');

        if ($challan !== null) {
            return $challan instanceof DeliveryChallan ? $challan : DeliveryChallan::query()->find((int) $challan);
        }

        $pass = $request->route('gatePass');

        if ($pass !== null) {
            $pass = $pass instanceof GatePass ? $pass : GatePass::query()->find((int) $pass);

            return $pass?->challan;
        }

        return null;
    }
}
