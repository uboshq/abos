<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ক্রয় বিল — কী দিতে হবে।
 *
 * বিলটা দায়টা সরবরাহকারীর নামে বসায়, আর ঠিক সেই কারণে সরবরাহকারীর প্রদেয়
 * নিজে থেকেই মেলে: প্রদেয়ের সংখ্যাটা কোথাও জমা থাকে না, খতিয়ান থেকে গোনা
 * হয়। জমা রাখলে বিল বাতিল হলে দুই জায়গায় বদলাতে হত, আর একটা বাদ পড়ত।
 */
class PurchaseBill extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'pur_bills';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'supplier_id', 'warehouse_id', 'trx_date', 'due_on', 'supplier_bill_no',

        /*
         * ⭐ যেদিন মাল এল — বিলের তারিখ থেকে আলাদা।
         *
         * ⓘ `trx_date` সরবরাহকারীর কাগজে ছাপা তারিখ, আর খতিয়ান ওটাই
         * ধরে। ⚠️ কিন্তু গাড়ি প্রায়ই পরে আসে, আর **মজুদ বসে গাড়ির
         * দিনে** — নাহলে জুলাইয়ের বিলের মাল জুলাইয়ের মজুদে বসত যদিও
         * আগস্টে এসেছে, আর মাস-শেষের গণনা কোনোদিন মিলত না।
         *
         * ⓘ খালি মানে "আগের মতোই" — সেবা স্তরে `received_on ?? trx_date`।
         */
        'received_on',

        /*
         * ⭐ কাগজটার পরিশোধের শর্ত — মালিকের `Payment Terms`।
         *
         * ⓘ সাতটা বিকল্পই শেষে একটা তারিখে গিয়ে দাঁড়ায় (`due_on`), কিন্তু
         * তারিখটা **কেন** সেই তারিখ তা বলে না। ⚠️ "৫ সেপ্টেম্বর" মানে
         * নগদে কেনা, নাকি মাল পৌঁছে দিয়ে টাকা — একই দিন হতে পারে, অর্থ
         * আলাদা। ⛔ ধরনটা না রাখলে *"এই মাসে COD-তে কত কিনলাম"* প্রশ্নের
         * উত্তর কোথাও থাকত না।
         */
        'payment_term',

        'carrier_id', 'carrier_name', 'transport_cost', 'vehicle_no', 'driver_name',

        /*
         * ── আমদানি চালান — পাঁচটা ঘর, একই কারণে এখানে ─────────────
         *
         * ⚠️ উপরের ভাড়ার পাঁচটার মতোই: `$fillable`-এ না লিখলে
         * `preventSilentlyDiscardingAttributes()` ব্যতিক্রম ছুড়বে
         * (local ও testing-এ), আর লাইভে ঘরগুলো নীরবে খালি যেত।
         *
         * ⓘ পাঁচটাই ঐচ্ছিক — দেশের ভিতরের ক্রয়ে আমদানির কিছুই নেই।
         */
        'lc_no', 'be_no', 'be_date', 'vessel', 'port_of_entry',

        'subtotal', 'discount', 'tax', 'total',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'received_on' => 'date',
            'due_on' => 'date',

            // খালাসের তারিখ — বিলের তারিখের সাথে এক নয়, তাই নিজের ঘর
            'be_date' => 'date',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',

            // ⓘ বাকি টাকার ঘরগুলোর সমান মাপ — নাহলে যোগ-বিয়োগে
            // এক পয়সা করে হারাত, আর ধরা পড়ত মাস শেষে
            'transport_cost' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class)->orderBy('line_no');
    }

    /**
     * মিল যা সাথে দিয়ে দিল — অন্য পণ্য, বিলের মোটে নেই।
     *
     * ⚠️ [[lines]]-এর সাথে মিশিয়ে ফেলা যাবে না। বিলের যোগফল কেবল
     * `lines` থেকে আসে; এই সারিগুলোর কোনো দর নেই বলে তারা যোগ হয় না,
     * অথচ গুদামে ঠিকই ঢোকে (ফ্রি ভাণ্ডারে)।
     */
    public function giftLines(): HasMany
    {
        return $this->hasMany(PurchaseBillGiftLine::class)->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** এই বিলের বিপরীতে যত পরিশোধ বসেছে। */
    public function paymentLines(): HasMany
    {
        return $this->hasMany(PaymentLine::class, 'purchase_bill_id');
    }

    /**
     * এই বিলে কত টাকা দেওয়া হয়েছে।
     *
     * ── কেবল খাতায় বসা পরিশোধ ───────────────────────────────────────
     * খসড়া পরিশোধে টাকা এখনো যায়নি — ওটা লেখা হয়েছে, পোস্ট হয়নি।
     * গুনলে বিলটা শোধ দেখাত অথচ সরবরাহকারীর কাছে টাকা যায়নি, আর
     * বকেয়ার তালিকা থেকে বিলটা নীরবে হারিয়ে যেত।
     *
     * ধরা পড়েছে পর্দা চালিয়ে: একটা খসড়া পরিশোধ তৈরি করেই দেখা গেল
     * ১,০০০ টাকার বিলের বাকি ৪০০ দেখাচ্ছে।
     *
     * বাতিল হয়ে যাওয়া পরিশোধও বাদ — টাকাটা ফেরত এসেছে, বিলটা আবার বাকি।
     */
    public function paidAmount(): string
    {
        /*
         * তালিকা withPaid() দিয়ে এলে অঙ্কটা সারির সাথেই এসেছে।
         *
         * পরিশোধের পর্দায় বকেয়া বিলগুলো ঝুলন্ত তালিকায় দেখানো হয়, আর
         * প্রতিটার পাশে বাকি টাকা লেখা থাকে। এই ঘরটা না থাকলে বিলপ্রতি
         * একটা করে যোগফল — বকেয়ার তালিকা ছাঁকার সময় একবার, তারপর
         * পর্দায় লেখার সময় আরেকবার।
         */
        $preloaded = $this->getAttribute('paid_total');

        $paid = $preloaded ?? $this->paymentLines()
            ->whereHas('payment', fn ($q) => $q->posted())
            ->sum('amount');

        return (string) ($paid ?: '0');
    }

    /**
     * তালিকার জন্য পরিশোধের যোগফল — বিলপ্রতি একটা নয়, পুরোটার জন্য একটা।
     *
     * শর্তগুলো উপরের paidAmount()-এর হুবহু নকল, আর সেটা ইচ্ছাকৃত ঝুঁকি:
     * দুই জায়গায় দুই রকম হলে তালিকায় এক অঙ্ক আর একক পাতায় আরেক অঙ্ক
     * দেখা যেত। একটা বদলালে অন্যটাও বদলাতে হবে — PaymentServiceTest
     * দুই পথেই একই ফল আসছে কি না দেখে।
     */
    public function scopeWithPaid(Builder $query): Builder
    {
        $paid = PaymentLine::query()
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('pur_payment_lines.purchase_bill_id', 'pur_bills.id')
            ->whereHas('payment', fn ($q) => $q->posted());

        // pur_bills.* না দিলে addSelect শুধু সাব-কোয়েরিটাই আনত
        return $query->addSelect(['pur_bills.*', 'paid_total' => $paid]);
    }

    /**
     * এখনো কত বাকি।
     *
     * ঋণাত্মক হয় না: অতিরিক্ত শোধ (অগ্রিম) বিলের বাকি নয়, সরবরাহকারীর
     * খাতার ব্যাপার — ওটা এখানে দেখালে "বাকি −৫০০" পড়ে কেউ বুঝত না।
     */
    public function dueAmount(): string
    {
        $due = bcsub((string) $this->total, $this->paidAmount(), 4);

        return bccomp($due, '0', 4) > 0 ? $due : '0.0000';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('document_no', 'like', "%{$term}%")
                ->orWhere('supplier_bill_no', 'like', "%{$term}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->search($term));
        });
    }

    /**
     * স্টকের চলাচলে যে উৎস বসে — StockService::move()-এ পাঠানো হয়।
     *
     * বিল সচরাচর মাল নড়ায় না; নড়ায় কেবল যখন তার পেছনে কোনো চালান নেই,
     * অর্থাৎ মাল আর বিল একসাথে এসেছে। উৎসটা আলাদা রাখা হয়েছে যাতে
     * গুদামের খতিয়ানে দেখা যায় মালটা কোন কাগজে ঢুকেছিল।
     */
    public const STOCK_SOURCE = 'purchase_bill';

    // ── Drillable ───────────────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'purchase_bill';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->supplier?->name() ?? $this->document_no;
    }

    public function drillRoute(): array
    {
        return ['purchase.bill.show', ['bill' => $this->id]];
    }
}
