<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\SettingsService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Validation\ValidationException;

/**
 * মাল কীভাবে যাবে, না বলে DO বা সরাসরি বিক্রি নিশ্চিত হয় না — মালিকের পরিকল্পনা, ধাপ ৫, ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * পরিবহনের সব ঘর ঐচ্ছিক। চালান পাকা হত গাড়ি, বাহক কিছু না লিখেই — আর
 * পরে কেউ জানত না মালটা কার গাড়িতে গেল, ভাড়া কার খাতায় উঠবে, বা আদৌ
 * ডিপোর গাড়ি লেগেছিল কি না।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * নিশ্চিত করার আগে তিনটার একটা: গাড়ি (বহর থেকে বা নম্বর), বাহক (তালিকা
 * থেকে বা নাম), নয়তো "পরিবহন লাগবে না (ক্রেতার নিজের)" টিক। ⓘ চালকের
 * নাম একা যথেষ্ট নয় — কোন গাড়ি, সেটাই আসল প্রশ্ন।
 * খসড়া রাখায় লাগে না; কেবল নিশ্চিতে।
 *
 * ── ⓘ কোম্পানি পরিবহনের ঘর বন্ধ রাখলে ───────────────────────────────
 * (`sales.field_transport` = না) নিয়ম খাটে না — পর্দায় ঘরই নেই, মানা অসম্ভব।
 *
 * ── ⚠️ দরজাগুলো ────────────────────────────────────────────────────────
 * সেবার `DeliveryChallanService::confirm()` নিজে এটা জিজ্ঞেস করে না; প্রশ্নটা
 * প্রতিটা দরজায় ([[TheGoodsLeftWithNoWordOnHowTheyTravelledTest]]), আর নতুন
 * দরজা যেন ফাঁকে না ঢোকে সেটা দেখে [[EveryChallanConfirmAsksHowTheGoodsTravelTest]]।
 */
final class TransportRule
{
    public function __construct(private readonly SettingsService $settings) {}

    public function applies(): bool
    {
        return (bool) $this->settings->get('sales.field_transport', true);
    }

    /**
     * @param  array<string, mixed>|DeliveryChallan  $source  পর্দার ঘরগুলো, নয়তো চালান নিজে
     */
    public function assertNamed(array|DeliveryChallan $source): void
    {
        if (! $this->applies() || self::named($source)) {
            return;
        }

        throw ValidationException::withMessages([
            'transport' => __('sales::validation.transport_required'),
        ]);
    }

    /**
     * কাউন্টারে রাখা বা সইয়ে থাকা বিক্রি — প্রশ্নটা তার চালানকে ([[SalesInvoiceController::confirm()]])।
     *
     * ⓘ চালান খোঁজা হয় [[DirectSaleService::finishHeld()]]-এর মতোই: বিলের প্রথম সারির চালান।
     */
    public function assertNamedForHeld(SalesInvoice $invoice): void
    {
        $invoice->loadMissing(['lines.challanLine']);
        $challanId = $invoice->lines->first()?->challanLine?->delivery_challan_id;

        if ($challanId !== null) {
            $this->assertNamed(DeliveryChallan::query()->findOrFail($challanId));
        }
    }

    /** @param  array<string, mixed>|DeliveryChallan  $source */
    public static function named(array|DeliveryChallan $source): bool
    {
        $get = fn (string $key) => $source instanceof DeliveryChallan ? $source->getAttribute($key) : ($source[$key] ?? null);

        if (filter_var($get('own_transport'), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        // ⭐ নতুন কাউন্টার (৪ অক্টোবর ২০২৬): "গাড়ি কার — ক্রেতার নিজের / গাড়ি নেই" বাছাটাও একটা উত্তর
        if (in_array($get('vehicle_owner'), ['customer', 'none'], true)) {
            return true;
        }

        foreach (['vehicle_id', 'vehicle_no', 'carrier_id', 'carrier_name'] as $key) {
            if (trim((string) $get($key)) !== '') {
                return true;
            }
        }

        return false;
    }
}
