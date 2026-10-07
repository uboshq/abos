<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * গেট পাস = মাল বেরোনো (Post Goods Issue) — আর তখনই ইনভয়েস। মালিক, ৪ অক্টোবর ২০২৬: *"অবশ্যই ইন্টারন্যাশনাল স্ট্যান্ডার্ড"*
 * (সুইচ `sales.invoice_at_goods_issue`; পরিকল্পনা সংস্করণ ২, ধাপ ৫–৬; IFRS ১৫)।
 *
 * ── ⭐ এক লেনদেনে, গেট পাসের সাথে ([[DeliveryStageService::move()]] — রওনা বা সোজা "পৌঁছেছে") ───────────
 *   ১ · নিশ্চিতে যা আটকে ছিল তা ছেড়ে তাক থেকে বেরোয়, লট আর ছাপা দামের যাচাইসহ ([[DeliveryChallanService::issueHeldGoods()]])
 *   ২ · ফ্রি আর উপহারও এখন বেরোয় ([[DirectSaleService::issueFreeAtGate()]])
 *   ৩ · চালানে বাঁধা খসড়া বিল পাকা হয় — একই নম্বরে (INV-0154 / CHA-0154), বাকির সীমা আবার মেপে; খরচ (COGS) তখনই ওঠে
 *   ৪ · `goods_issued_at` — দ্বিতীয় রওনা (পৌঁছায়নি, আবার পাঠানো) মাল দুইবার বের করে না
 * ⛔ যেকোনো ধাপ আটকালে (বাকির সীমা, লটে কম) গেট পাসও হয় না, রওনাও না — পুরোটা ফেরত।
 * ⓘ এই নিয়মে নিশ্চিত না হওয়া চালান (`issue_at_gate` false — পুরনো কাগজ, সুইচ বন্ধ, "এখনই নিয়ে যাবেন") এখানে কিছুই করে না।
 */
final class GoodsIssue
{
    public function __construct(
        private readonly DeliveryChallanService $challans,
        private readonly SalesInvoiceService $invoices,
    ) {}

    public function issue(DeliveryChallan $challan): ?SalesInvoice
    {
        $challan = $challan->fresh();

        if (! $challan->issue_at_gate || $challan->goods_issued_at !== null || $challan->status !== DocumentStatus::CONFIRMED) {
            return null;
        }

        $this->challans->issueHeldGoods($challan);
        app(DirectSaleService::class)->issueFreeAtGate($challan);

        $draft = SalesInvoice::query()
            ->where('status', DocumentStatus::DRAFT)
            ->whereHas('lines.challanLine', fn ($q) => $q->where('delivery_challan_id', $challan->id))
            ->first();

        /*
         * ⓘ বিলের তারিখ মাল বেরোনোর দিন — আয় স্বীকৃত হয় মাল বেরোনোয়, নিশ্চিতে নয় (IFRS ১৫); খরচও আজকের ([[DeliveryChallanService::issueHeldGoods()]])।
         */
        if ($draft !== null) {
            /*
             * ⛔ তারিখের সাথে অর্থবছরও — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (বিক্রয় ⓘ১২; [[TheGateBillTakesTodaysYearTest]])। ⓘ আগে কেবল
             * তারিখ আজকের হত, বছর পুরনো থাকত: জুনে লেখা বিল জুলাইয়ে গেট পার হলে নতুন বছরের তারিখে, পুরনো বছরের নম্বরের ঘরে। আজকের
             * কোনো বছর না পেলে আগেরটাই — খাতা নিজে তারিখ ধরে বছর খোঁজে আর তখন পরিষ্কার কথায় থামে।
             */
            $draft->update([
                'trx_date' => now()->toDateString(),
                'financial_year_id' => FinancialYear::forDate(now())?->id ?? $draft->financial_year_id,
            ]);
        }

        $invoice = $draft === null ? null : $this->invoices->confirm($draft->fresh(['lines']), '0');

        $challan->update(['goods_issued_at' => now()]);

        return $invoice;
    }
}
