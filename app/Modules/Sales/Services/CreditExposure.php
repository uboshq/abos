<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\CreditHolds;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * একজন গ্রাহকের বাকির সীমা কতটা ব্যবহার হয়ে গেছে — আর দেয়ালটা।
 *
 * ── ⭐ মালিকের নিয়ম, ২৫–২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"limit mane limit 100%, karo khomota thakbe na limit cross korar,
 * emon ki malikero."* ⓘ কারণ অঙ্কের: মার্জিন **৩.৮২%**, আর *"ekjaygay
 * taka atkale koyek bochorer lav sesh"*।
 *
 * ── ⛔ আগে সীমা কেবল খাতার বকেয়া দেখত ────────────────────────────────
 * ৫০,০০০ সীমার গ্রাহককে ৪০,০০০ করে তিনটা ডিও দেওয়া যেত — প্রতিটা আলাদা
 * করে সীমার ভিতরে, অথচ মাল বেরোত ১,২০,০০০ টাকার। ⓘ কারণ ডিওর মাল
 * খাতায় বসে বিলের দিনে, আর ততদিন "বকেয়া" জানতই না।
 *
 * ⭐ মালিকের উত্তর: *"hya obosoi, sudu tai noy bill khosora hole product
 * zemon atkay temon customer er balanceo atkabe"*। তাই ব্যবহৃত সীমা তিন ভাগ:
 *
 *     খাতার বকেয়া            নিশ্চিত বিল − জমা           (Customer::outstanding)
 *   + বিল না হওয়া চালান       মাল বেরিয়েছে, বিল হয়নি
 *   + খসড়া বিল               কাউন্টারে ধরে রাখা বা "খসড়া রাখুন"
 *
 * ⛔ একই টাকা কখনো দুইবার নয়: চালানের যে সারি কোনো (বাতিল নয় এমন) বিলের
 * সারিতে ঢুকেছে, সে চালানের ভাগ থেকে সরে যায় — বিলটা নিজের ভাগে গোনা
 * হয়। ⓘ বিক্রয় আদেশ কিছুই আটকায় না: *"sales order astei pare"*।
 *
 * ── ⚠️ কেন এটা Sales-এ, Customer-এ নয় ────────────────────────────────
 * Customer মডিউল বিক্রয়কে চেনে না (নির্ভরতার দিক `sales → customer`)।
 * ⓘ খাতার বকেয়া আর শূন্য-সীমার মানে থাকল [[Customer::wouldExceedCreditLimit()]]
 * -এ — এই সেবা কেবল বিক্রয়ের দুইটা ভাগ যোগ করে ওখানে পাঠায়।
 *
 * ── ⛔ কোনো ওভাররাইড নেই ─────────────────────────────────────────────
 * কারো চাবি সীমা পার করায় না, সুপার অ্যাডমিনেরও না — মালিক ২৬
 * সেপ্টেম্বর ক বেছেছেন। ⓘ একমাত্র পথ গ্রাহকের সীমা বাড়ানো, আর সেই বদল
 * খাতায় ওঠে (আগে কত, পরে কত) ও অনুমোদনে যায়। কোম্পানি চাইলে পুরো সীমা
 * বন্ধ রাখতে পারে `customer.credit_limit_enabled` দিয়ে — সুইচটা কেবল
 * সুপার অ্যাডমিনের।
 */
final class CreditExposure implements CreditHolds
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * [[CreditHolds]] — গ্রাহকের পাতার জন্য, একই হিসাব।
     *
     * ⚠️ আলাদা যুক্তি নয়, [[pendingFor()]]-ই — নইলে পাতা আর কাউন্টার
     * একদিন দুই রকম গুনত।
     */
    public function heldFor(int $customerId): string
    {
        return $this->pendingFor([$customerId])[$customerId] ?? '0';
    }

    /** এই প্রতিষ্ঠানে বাকির সীমা চালু কি না — একটাই সুইচ। */
    public function isOn(): bool
    {
        return $this->settings->enabled('customer.credit_limit_enabled');
    }

    /**
     * খাতার বাইরে আটকে থাকা টাকা: বিল না হওয়া চালান + খসড়া বিল।
     *
     * @param  int|null  $exceptInvoiceId  এই বিলটা নিজেকে গোনে না — নইলে
     *                                     খসড়া বিল নিশ্চিত করার সময় নিজের
     *                                     আটকানো টাকার জন্যই আটকে যেত।
     * @param  int|null  $exceptChallanId  এই চালানের খসড়া বিলগুলোও বাদ —
     *                                     কাউন্টারে ধরে রাখা বিক্রয় শেষ করার
     *                                     সময় চালান আর তার বিল একই টাকা।
     */
    public function pending(Customer $customer, ?int $exceptInvoiceId = null, ?int $exceptChallanId = null): string
    {
        return bcadd(
            $this->unbilledChallans($customer),
            $this->draftInvoices($customer, $exceptInvoiceId, $exceptChallanId),
            4,
        );
    }

    /**
     * অনেক গ্রাহকের আটকে থাকা টাকা একবারে — কাউন্টারের তালিকার জন্য।
     *
     * ⚠️ গ্রাহকপ্রতি [[pending()]] ডাকলে দুইশো গ্রাহকে চারশো কোয়েরি, আর
     * কাউন্টারের পাতা প্রতিবার খুলতে সেটা লাগত। ⓘ এখানে দুইটা, দল বেঁধে।
     * ⛔ হিসাবের নিয়ম [[pending()]]-এরই — একই দুই ভাগ, একই ছাঁকনি।
     *
     * @param  list<int>  $customerIds
     * @return array<int, string> গ্রাহক → আটকে থাকা টাকা (যাঁর কিছু নেই, তিনি তালিকায় নেই)
     */
    public function pendingFor(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        /*
         * ⚠️ কোম্পানির ছাঁকনি স্পষ্ট করে — abos-7d আর abos-67-এর ধরা, ২৬ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ গ্রাহকের আইডি এক কোম্পানিরই, তাই আজ অন্য কোম্পানির সারি আসত না।
         * ⛔ তবু কাঁচা কোয়েরি-নির্মাতা গ্লোবাল স্কোপ মানে না, আর এই ক্লাসের দেয়াল
         * ([[assertRoom()]]) কোম্পানি ধরে গোনে — দুই জায়গায় দুই ছাঁকনি থাকলে পর্দা
         * এক সংখ্যা দেখাত আর দেয়াল আরেক সংখ্যায় আটকাত।
         */

        $billed = DB::table('sal_invoice_lines as il')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->whereColumn('il.delivery_challan_line_id', 'cl.id')
            ->whereNull('i.deleted_at')
            ->where('i.status', '!=', DocumentStatus::CANCELLED);

        $challans = DB::table('sal_challan_lines as cl')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            ->whereIn('c.customer_id', $customerIds)
            ->where('c.company_id', CompanyContext::id())
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', DocumentStatus::POSTED)
            ->whereNotExists($billed)
            ->groupBy('c.customer_id')
            ->selectRaw('c.customer_id, SUM(cl.amount) as held')
            ->pluck('held', 'customer_id');

        $drafts = DB::table('sal_invoices as i')
            ->whereIn('i.customer_id', $customerIds)
            ->where('i.company_id', CompanyContext::id())
            ->whereNull('i.deleted_at')
            ->where('i.status', DocumentStatus::DRAFT)
            ->groupBy('i.customer_id')
            ->selectRaw('i.customer_id, SUM(i.total) as held')
            ->pluck('held', 'customer_id');

        $out = [];

        foreach ([$challans, $drafts] as $part) {
            foreach ($part as $customerId => $held) {
                $out[(int) $customerId] = bcadd($out[(int) $customerId] ?? '0', (string) $held, 4);
            }
        }

        return $out;
    }

    /**
     * ⛔ দেয়াল — এই কাগজটা এলে সীমা পার হবে কি না।
     *
     * ⚠️ ডাকতে হবে **অনুমোদনের আগে**। [[DocumentApproval::assertClear()]]
     * কাগজ অনুমোদনে পাঠিয়ে ফিরে যায়, আর তখন দেয়াল কোনো প্রশ্নই পেত না —
     * ৮৯,৭২০ টাকার বিল ৫০,০০০ সীমায় ঠিক এভাবেই সইয়ের সারিতে চলে গিয়েছিল।
     *
     * @param  string  $adding  এই কাগজের মোট টাকা
     * @param  string  $payingNow  এখনই গোনা টাকা — কাউন্টারে নগদ, ব্যাংক, মোবাইল।
     *                             ⓘ চেক কাউন্টারে নেওয়াই যায় না (মালিক, ২৬
     *                             সেপ্টেম্বর), তাই এখানে চেক আসে না।
     */
    public function assertRoom(
        Customer $customer,
        string $adding,
        string $payingNow = '0',
        ?int $exceptInvoiceId = null,
        ?int $exceptChallanId = null,
    ): void {
        if (! $this->isOn()) {
            return;
        }

        /*
         * ⚠️ বাড়তি টাকা বকেয়া থেকে বিয়োগ হতে দেওয়া হয় না।
         *
         * ⓘ বিলের চেয়ে বেশি গুনলে বাড়তিটা গ্রাহকের খাতায় ক্রেডিট হয়ে বসে
         * — সেটা পরের হিসাবে এমনিই আসে। ⛔ এখানে বিয়োগ করলে সীমা ছাড়ানো
         * একজন গ্রাহক বাড়তি টাকা দেখিয়ে **আগের** খসড়া বা চালানের জন্যও
         * জায়গা করে নিতেন, অথচ সেই টাকা ঐ কাগজের নয়।
         */
        $unpaid = bcsub($adding, $payingNow, 4);

        if (bccomp($unpaid, '0', 4) <= 0) {
            return;
        }

        $pending = $this->pending($customer, $exceptInvoiceId, $exceptChallanId);

        if (! $customer->wouldExceedCreditLimit(bcadd($pending, $unpaid, 4))) {
            return;
        }

        $limit = (string) $customer->credit_limit;
        $used = bcadd($customer->outstanding(), $pending, 4);
        $left = bcsub($limit, $used, 4);
        $left = bccomp($left, '0', 4) > 0 ? $left : '0';
        $short = bcsub($unpaid, $left, 4);

        /*
         * ⚠️ ঘরের নাম `customer_id` — বিলের পর্দাগুলো ঐ ঘরের বার্তা গ্রাহকের
         * ঘরের নিচেই দেখায়। ⛔ নতুন নামের ঘর (`credit_limit`) কোনো পর্দা
         * আঁকে না: বিল আটকাত, অথচ কারণটা কেউ দেখত না। ⓘ ধরা পড়েছে
         * [[ZeroMeansZeroOnTheDayYouSayTest]]-এ, ২৬ সেপ্টেম্বর ২০২৬।
         */
        throw ValidationException::withMessages([
            'customer_id' => __('sales::validation.over_credit_limit_hard', [
                'customer' => $customer->name(),
                'limit' => Money::format($limit),
                'left' => Money::format($left),
                'short' => Money::format($short),
            ]),
        ]);
    }

    /**
     * নিশ্চিত চালানের যে সারিগুলো এখনো কোনো বিলে ঢোকেনি।
     *
     * ⓘ বাতিল বিল গোনা হয় না — বাতিল হলে মালটা আবার "বিল না হওয়া"।
     * ⓘ মোছা (soft-deleted) বিল বা চালানও বাদ।
     */
    private function unbilledChallans(Customer $customer): string
    {
        $billed = DB::table('sal_invoice_lines as il')
            ->join('sal_invoices as i', 'i.id', '=', 'il.sales_invoice_id')
            ->whereColumn('il.delivery_challan_line_id', 'cl.id')
            ->whereNull('i.deleted_at')
            ->where('i.status', '!=', DocumentStatus::CANCELLED);

        $sum = DB::table('sal_challan_lines as cl')
            ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
            ->where('c.customer_id', $customer->id)
            ->where('c.company_id', $customer->company_id)
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', DocumentStatus::POSTED)
            ->whereNotExists($billed)
            ->sum('cl.amount');

        return bcadd((string) $sum, '0', 4);
    }

    /** খসড়া বিলের মোট — নিজেকে আর নিজের চালানের বিলকে বাদ দিয়ে। */
    private function draftInvoices(Customer $customer, ?int $exceptInvoiceId, ?int $exceptChallanId): string
    {
        $query = DB::table('sal_invoices as i')
            ->where('i.customer_id', $customer->id)
            ->where('i.company_id', $customer->company_id)
            ->whereNull('i.deleted_at')
            ->where('i.status', DocumentStatus::DRAFT);

        if ($exceptInvoiceId !== null) {
            $query->where('i.id', '!=', $exceptInvoiceId);
        }

        if ($exceptChallanId !== null) {
            $query->whereNotExists(fn ($q) => $q->from('sal_invoice_lines as il')
                ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
                ->whereColumn('il.sales_invoice_id', 'i.id')
                ->where('cl.delivery_challan_id', $exceptChallanId));
        }

        return bcadd((string) $query->sum('i.total'), '0', 4);
    }
}
