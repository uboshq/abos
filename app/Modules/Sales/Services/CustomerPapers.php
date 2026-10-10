<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\LoginJournal;
use App\Core\Support\CompanyContext;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * গ্রাহক নিজের যে কাগজগুলো দেখতে পান — আর কেবল নিজেরগুলো।
 *
 * ── কেন একটা আলাদা সেবা ─────────────────────────────────────────────
 * পোর্টালের প্রতিটা পাতা আগে **নিজে** মডেল ডাকত, **নিজে** শাখার
 * ছাঁকনি সরাত, আর **নিজে** পার্টি মেলাত — একই কথা তিন জায়গায় হাতে
 * লেখা। আজ কাজ করত, কিন্তু প্রতিটা নতুন পাতায় ভুলের সুযোগ একটা করে
 * বাড়ত।
 *
 * ⚠️ আর ভুলটা দুই দিকে যায়, **অসমান**:
 *
 *   ছাঁকনি সরাতে ভুলে গেলে   ৫০০ — জোরে ভাঙে, সাথে সাথে ধরা পড়ে
 *   বেশি সরিয়ে ফেললে        গ্রাহক **অন্যের কাগজ** দেখেন — নীরব, আর
 *                            একবার দেখে ফেললে ফেরানো যায় না
 *
 * ── ⭐ সবচেয়ে জরুরি নিয়ম: কোনো পদ্ধতি "কার" জিজ্ঞেস করে না ──────────
 * এখানে একটাও পদ্ধতি গ্রাহকের আইডি **প্যারামিটার হিসেবে নেয় না**।
 * নিলে একদিন কেউ URL থেকে নেওয়া একটা সংখ্যা পাঠাতেন, আর সেদিন কোনো
 * ত্রুটি আসত না — শুধু একজন গ্রাহক অন্যের খাতা দেখতেন।
 *
 * **কে, সেটা সবসময় গার্ড থেকে** ([[CustomerPapers::customer()]]), কখনো
 * ডাকার জায়গা থেকে নয়।
 *
 * ── আর একটা ফল: `withoutGlobalScope` আর কোথাও লেখা হয় না ────────────
 * লাইনটা এখন এই একটা ফাইলে, একবার। **যে লাইনটা কেউ ভুলতে পারে,
 * সেটার অস্তিত্বই না থাকা** — এটাই আসল সুরক্ষা, মনে রাখা নয়।
 *
 * ⛔ ── রিপোর্ট ইঞ্জিন এখানে ব্যবহার করা হয় না, ইচ্ছাকৃতভাবে ──────────
 * ইঞ্জিনের রিপোর্টগুলো কোম্পানি ও শাখা ধরে চলে, **পার্টি ধরে নয়** —
 * আর সে রপ্তানি ও ছাপার পথও দেয়। ওখানে একটা প্যারামিটার দিয়ে ছাঁকতে
 * গেলে **প্রতিটা পথে একই ছাঁকনি লাগত**, আর একটা ফসকালে সবাই সবার
 * কাগজ দেখে ফেলতেন।
 */
final class CustomerPapers
{
    /** ⓘ কেবল [[recordSignIn()]]-এর জন্য — ঢোকার খাতা, কর্মীর দরজার সেই একই লেখক। */
    public function __construct(
        private readonly LoginJournal $logins,
    ) {}

    /**
     * যিনি ঢুকেছেন — আর কেবল তিনিই।
     *
     * ⓘ কোম্পানির প্রসঙ্গটাও এখান থেকেই বসে: গ্রাহকের "কোম্পানি বাছাই"
     * বলে কিছু নেই, তিনি একটাই কোম্পানির। প্রসঙ্গ না বসালে
     * `BelongsToCompany` ওয়েব অনুরোধে ব্যতিক্রম ছুঁড়ত।
     */
    public function customer(): Customer
    {
        /** @var Customer|null $customer */
        $customer = Auth::guard('portal')->user();

        abort_if($customer === null, 403);

        CompanyContext::set($customer->company_id, $customer->branch_id);

        return $customer;
    }

    /**
     * সফল ঢোকা `login_history`-তে — ডিলারের নিজের নামে, গার্ড থেকে।
     *
     * ── ⚠️ কেন এটা লাগে, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * পোর্টালের দরজা এখন কর্মীর দরজার সেই একই [[LoginLock]] মানে, আর
     * তালাটা গোনে "শেষ সফল লগইনের পর থেকে"। ⛔ সফলটা না লিখলে গোনা কখনো
     * শূন্যে ফিরত না — সারা বছরের আটটা টাইপের ভুল জমে ডিলার প্রতিটা
     * নতুন ভুলের পর পনেরো মিনিট বাইরে থাকতেন।
     *
     * ── ⓘ কেন এখানে, কন্ট্রোলারে নয় ────────────────────────────────────
     * এই শ্রেণির ভিত্তি-নিয়মটাই খাটে: পদ্ধতিটা "কার" জিজ্ঞেস করে না,
     * গ্রাহক আসে [[customer()]] থেকে — তাই ডাকা হয় লগইনের **পরে**, আর
     * ভুল কোম্পানির নামে সারি বসানোর কোনো পথ থাকে না।
     * [[EveryPortalScreenAsksTheNarrowPathTest]] পোর্টালের কন্ট্রোলারে
     * সরাসরি মডেল-ডাক গোনে, আর সেই তালিকা কেবল কমে।
     *
     * ── ⓘ লেখাটা জার্নালেরই ─────────────────────────────────────────────
     * [[LoginJournal::succeededFor()]] — কর্মীর সারির সেই একই লেখক, তাই
     * নাম ছাঁটা, IP, ব্রাউজার আর নীরবে-ব্যর্থ হওয়ার নিয়ম দুই দরজায় এক।
     */
    public function recordSignIn(string $identifier): void
    {
        /*
         * ⓘ গ্রাহক গার্ড থেকে, কোম্পানি তাঁর নিজের সারি থেকে — ডাকার
         * জায়গা কোনো আইডি পাঠায় না।
         */
        $this->logins->succeededFor($identifier, $this->customer()->company_id);
    }

    /**
     * নিজের বিলগুলো — নতুনটা আগে।
     *
     * @return Collection<int, SalesInvoice>
     */
    public function invoices(int $limit = 20): Collection
    {
        return $this->mine(SalesInvoice::query())
            ->orderByDesc('trx_date')->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * নিজের খতিয়ানের সারি — একটা সময়ের ভিতরে, পুরনো আগে।
     *
     * ⚠️ ক্রম দুইটা কলাম ধরে, আর দ্বিতীয়টা বাদ দেওয়া যাবে না: একই
     * তারিখে তিনটা সারি থাকলে ডাটাবেস যেকোনো ক্রমে দিতে পারে, আর
     * তখন **প্রতিবার পাতা খুললে চলমান জের আলাদা দেখাত** — অথচ একটা
     * সংখ্যাও বদলায়নি।
     *
     * @return Collection<int, LedgerEntry>
     */
    public function ledgerBetween(string $from, string $to): Collection
    {
        return $this->ledgerRows()
            ->whereDate('trx_date', '>=', $from)
            ->whereDate('trx_date', '<=', $to)
            ->orderBy('trx_date')->orderBy('id')
            ->get();
    }

    /**
     * ⭐ খতিয়ানের ডিফল্ট শুরু — গ্রাহকের কোম্পানির চলতি অর্থবছরের প্রথম দিন, না থাকলে বছরের প্রথম দিন (পুনঃঅডিট ৯ অক্টোবর ২০২৬, গ্রাহক ১৫)।
     * ⓘ গ্রাহক আসে গার্ড থেকে, তাই কোম্পানি তাঁরই।
     */
    public function yearStart(): string
    {
        $start = FinancialYear::query()->withoutGlobalScopes()
            ->where('company_id', $this->customer()->company_id)
            ->whereDate('starts_on', '<=', now()->toDateString())
            ->whereDate('ends_on', '>=', now()->toDateString())
            ->value('starts_on');

        return $start === null ? now()->startOfYear()->toDateString() : Carbon::parse($start)->toDateString();
    }

    /**
     * ছাঁকনির **আগের** সব সারির নিট — খোলার জের।
     *
     * ⚠️ এটা না গুনলে জেরের কলাম শূন্য থেকে শুরু হত, আর গ্রাহক পড়তেন
     * "আমার কোনো বকেয়া ছিল না" — যা প্রায় সবসময়ই মিথ্যা।
     */
    public function openingBefore(string $from): string
    {
        return (string) ($this->ledgerRows()
            ->whereDate('trx_date', '<', $from)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
            ->value('net') ?? '0');
    }

    /**
     * খতিয়ানের কোয়েরি — দুইটা শর্ত, সবসময় একসাথে।
     *
     * ⚠️ একটা ছাড়া অন্যটা কখনো নয়। `forParty` বাদ পড়লে **অন্য গ্রাহকের
     * সারি** চলে আসত; শাখার ছাঁকনি না সরালে গ্রাহক **নিজের অর্ধেক
     * কাগজ** দেখতেন না। দ্বিতীয়টা বিরক্তিকর, প্রথমটা মারাত্মক।
     */
    private function ledgerRows()
    {
        return LedgerEntry::query()
            ->withoutGlobalScope('user-branch')
            ->forParty('customer', (int) $this->customer()->id);
    }

    /**
     * ⭐ নিজের একটা বিক্রি — ডেলিভারি ট্র্যাকিংয়ের দাগ (মালিক, ২ অক্টোবর ২০২৬)।
     * ⛔ অন্যের বিক্রি খোঁজাতেই আসে না — ৪০৪, "আছে কি নেই" সেটাও বলা হয় না।
     */
    public function trackedSale(string $kind, string $publicId): DeliveryChallan|SalesOrder
    {
        $query = match ($kind) {
            'challan' => DeliveryChallan::query(),
            'order' => SalesOrder::query(),
            default => abort(404),
        };

        return $this->mine($query)->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * নিজের DO-গুলো — নতুনটা আগে, পাতায় ৫০ ([[PortalDeliveryOrderController]])।
     *
     * @return LengthAwarePaginator<int, DeliveryOrder>
     */
    public function deliveryOrders(int $perPage = 50): LengthAwarePaginator
    {
        return $this->mine(DeliveryOrder::query())
            ->orderByDesc('trx_date')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    /** নিজের একটা DO, লাইনসহ — অন্যেরটা ৪০৪ */
    public function deliveryOrder(string $publicId): DeliveryOrder
    {
        return $this->mine(DeliveryOrder::query())->where('public_id', $publicId)->with('lines.product')->firstOrFail();
    }

    /**
     * ⭐ নিজের বিক্রয় আদেশ — নতুনটা আগে, পাতায় ৫০ ([[PortalOrderController]]; DO+SO মেশানো, ধাপ ৯, ৫ অক্টোবর ২০২৬)।
     * ⓘ অফিস বা SR-এর লেখা নিজের আদেশও — "কার" প্রশ্নের উত্তর গ্রাহক, লেখক নয়।
     *
     * @return LengthAwarePaginator<int, SalesOrder>
     */
    public function salesOrders(int $perPage = 50): LengthAwarePaginator
    {
        return $this->mine(SalesOrder::query())
            ->orderByDesc('trx_date')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    /** নিজের একটা বিক্রয় আদেশ, লাইনসহ — অন্যেরটা ৪০৪ */
    public function salesOrder(string $publicId): SalesOrder
    {
        return $this->mine(SalesOrder::query())->where('public_id', $publicId)->with('lines.product')->firstOrFail();
    }

    /**
     * DO-তে চাওয়া যায় এমন পণ্য — সক্রিয়, নামের ক্রমে, দামসহ (দাম পণ্যের, ডিলারের নয়)।
     * ⓘ কোম্পানির ক্যাটালগ — "কার" প্রশ্ন নেই, তবু এখানে, যাতে পোর্টালের পর্দা নিজে কোয়েরি না লেখে।
     *
     * @return Collection<int, Product>
     */
    public function orderableProducts(): Collection
    {
        return Product::query()->where('is_active', true)->orderBy('name_en')
            ->get(['id', 'name_en', 'name_bn', 'code', 'sale_price']);
    }

    /**
     * ⭐ এই একটা পদ্ধতিই "কার" প্রশ্নের একমাত্র উত্তরদাতা।
     *
     * ⓘ গ্রাহকের কোনো শাখা নেই, তাই কর্মীর শাখা-ছাঁকনি সরাতে হয় —
     * কিন্তু সেই সাথেই পার্টির শর্তটা বসে, **একই লাইনে**। দুইটা আলাদা
     * জায়গায় থাকলে একদিন কেউ প্রথমটা লিখে দ্বিতীয়টা ভুলতেন।
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    private function mine($query)
    {
        return $query
            ->withoutGlobalScope('user-branch')
            ->where('customer_id', $this->customer()->id);
    }
}
