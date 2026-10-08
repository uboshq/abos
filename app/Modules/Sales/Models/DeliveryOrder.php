<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Contracts\CounterSaleSource;
use App\Modules\Sales\Services\DeliveryOrderStock;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * ডেলিভারি অর্ডার — ডিলার, SR বা উপরের কেউ লেখেন; সুপারভাইজার অনুমোদন দেন; তারপর হিসাব আর ডিপো
 * (মালিকের চূড়ান্ত বিক্রয়-ধারা, ২ অক্টোবর ২০২৬; অবস্থা [[DeliveryOrderStatus]])।
 *
 * ⓘ `created_by_customer_id` থাকলে ডিলার নিজে লিখেছেন (পোর্টাল/অ্যাপ) — তিনি কেবল নিজেরটা দেখেন ও বদলান।
 * ⓘ `accounts_*` কেবল abos-86-এর সেবা লেখে; `sales_invoice_id` কেবল abos-bb-র।
 */
class DeliveryOrder extends Model implements CounterSaleSource
{
    // ⭐ বিজ্ঞপ্তিতে "নাম · পয়েন্ট" ([[NamesItsCustomerInNotices]])
    use \App\Modules\Sales\Models\Concerns\NamesItsCustomerInNotices;
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    // ⭐ বিক্রয়কর্মী কেবল নিজের বাঁধা ডিলারের কাগজ দেখেন — ⛔১৬, ২ অক্টোবর ২০২৬ ([[DealerScope]])
    use \App\Core\Concerns\ScopedToUserDealers;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'sal_delivery_orders';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'sales_order_id', 'customer_id', 'warehouse_id', 'trx_date', 'deliver_on',
        'status', 'subtotal', 'total', 'narration',
        'created_by', 'created_by_customer_id', 'submitted_at', 'approved_at',
        'accounts_short', 'accounts_held_at', 'accounts_checked_at', 'accounts_warnings',
        'sales_invoice_id', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'deliver_on' => 'date',
            'subtotal' => 'decimal:4',
            'total' => 'decimal:4',
            'accounts_short' => 'decimal:4',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'accounts_held_at' => 'datetime',
            'accounts_checked_at' => 'datetime',
            'accounts_warnings' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryOrderLine::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ডিলার নিজে লিখলে তিনি */
    public function dealerWriter(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'created_by_customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function isEditableByWriter(): bool
    {
        return in_array($this->status, DeliveryOrderStatus::EDITABLE_BY_WRITER, true);
    }

    // ── ⭐ কাউন্টারের উৎস — abos-bb-র চুক্তি ([[CounterSaleSource]], b741b750) ───────────────────

    public static function counterSourceKey(): string
    {
        return 'do';
    }

    /** ⛔ হিসাবে অনুমোদিত বা ডিপো যাচাইয়ে থাকলেই কাউন্টারে খোলে; বিল হয়ে গেলে নয়। তালা দিয়ে আবার পড়ে। */
    public function assertReadyForCounter(): void
    {
        $now = (string) static::query()->whereKey($this->getKey())->lockForUpdate()->value('status');

        if (! in_array($now, [DeliveryOrderStatus::ACCOUNTS_APPROVED, DeliveryOrderStatus::DEPOT_CHECK], true)) {
            throw ValidationException::withMessages([
                'source' => __('sales::delivery_order.not_ready', ['status' => DeliveryOrderStatus::label($now)]),
            ]);
        }
    }

    /** কাউন্টারের পর্দা ভরার জন্য — লট ছাড়া; পরিমাণ চূড়ান্ত পরিমাণ ([[DeliveryOrderLine::finalQty()]]) */
    public function counterScreen(): array
    {
        return [
            'ref' => (string) $this->document_no,
            'customer_id' => (int) $this->customer_id,
            'warehouse_id' => $this->warehouse_id === null ? null : (int) $this->warehouse_id,
            'lines' => $this->lines()->get()->map(fn (DeliveryOrderLine $line) => [
                'product_id' => (int) $line->product_id,
                'qty' => $line->finalQty(),
                'free_qty' => (string) $line->free_qty,
                'rate' => (string) $line->rate,
                'discount_percent' => (string) $line->discount_percent,
                'source_line_id' => (int) $line->id,
            ])->values()->all(),
        ];
    }

    /** ডিপো যাচাই শুরু — কেবল হিসাবে অনুমোদিত থেকে; দুইবার ডাকলে কিছু হয় না */
    public function enterDepotCheck(): void
    {
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status === DeliveryOrderStatus::DEPOT_CHECK) {
            return;
        }

        if ($locked->status !== DeliveryOrderStatus::ACCOUNTS_APPROVED) {
            throw ValidationException::withMessages([
                'source' => __('sales::delivery_order.not_ready', ['status' => DeliveryOrderStatus::label((string) $locked->status)]),
            ]);
        }

        $locked->forceFill(['status' => DeliveryOrderStatus::DEPOT_CHECK])->save();
        $this->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * ডিপো যাচাই ছাড়া — কাউন্টারের রাখা খসড়া বাদ গেলে DO আবার ডিপোর তালিকায় (abos-bb, ৩ অক্টোবর ২০২৬)।
     * ⓘ কেবল depot_check থেকে accounts_approved-এ; অন্য যেকোনো অবস্থায় চুপচাপ কিছুই নয়।
     */
    public function leaveDepotCheck(): void
    {
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== DeliveryOrderStatus::DEPOT_CHECK) {
            return;
        }

        $locked->forceFill(['status' => DeliveryOrderStatus::ACCOUNTS_APPROVED])->save();
        $this->setRawAttributes($locked->getAttributes(), true);
    }

    /** বিক্রয় নিশ্চিতের একই লেনদেনে — বিল বসে; একই বিলে দুইবার ডাকলে কিছু হয় না, অন্য বিলে ⛔ */
    public function markInvoiced(SalesInvoice $invoice): void
    {
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status === DeliveryOrderStatus::INVOICED) {
            if ((int) $locked->sales_invoice_id === (int) $invoice->getKey()) {
                return;
            }

            throw ValidationException::withMessages(['source' => __('sales::delivery_order.already_invoiced')]);
        }

        if (! in_array($locked->status, [DeliveryOrderStatus::ACCOUNTS_APPROVED, DeliveryOrderStatus::DEPOT_CHECK], true)) {
            throw ValidationException::withMessages([
                'source' => __('sales::delivery_order.not_ready', ['status' => DeliveryOrderStatus::label((string) $locked->status)]),
            ]);
        }

        $locked->forceFill(['status' => DeliveryOrderStatus::INVOICED, 'sales_invoice_id' => $invoice->getKey()])->save();
        $this->setRawAttributes($locked->getAttributes(), true);
    }

    /** ⓘ DO নিজে বিক্রয় আদেশ নয় — চালান কোনো আদেশে বাঁধা হয় না ([[CounterSaleSource::orderLink()]]) */
    public function orderLink(): ?int
    {
        return null;
    }

    /**
     * ডিপো কম দিল — আটকানো মালও কমে, যাতে বাকিটা অন্য বিক্রিতে যায় (abos-86-এর [[DeliveryOrderStock::resize()]])।
     * ⓘ আগে এই ডাক কাউন্টারে ছিল (`instanceof DeliveryOrder`), এখন DO-র নিজের — নকশার ধাপ ৬।
     *
     * @param  array<int, string>  $less
     */
    public function resizeCounterStock(array $less): void
    {
        if ($less !== []) {
            app(DeliveryOrderStock::class)->resize($this, $less);
        }
    }

    /** বিক্রয় নিশ্চিতের একই লেনদেনে — যা বেরোল ততটা "উঠল", বাকিটা ছাড় ([[DeliveryOrderStock::consume()]]) */
    public function consumeCounterStock(DeliveryChallan $challan): void
    {
        app(DeliveryOrderStock::class)->consume($this, $challan);
    }

    /** লাইনের চূড়ান্ত পরিমাণে আবার গোনা — অনুমোদনকারী পরিমাণ বদলালে মোটও বদলায় (abos-86 এটাই পড়েন) */
    public function recalculate(): void
    {
        $sum = '0';

        foreach ($this->lines()->get() as $line) {
            $line->line_total = $line->computedTotal();
            $line->save();
            $sum = bcadd($sum, (string) $line->line_total, 4);
        }

        $this->forceFill(['subtotal' => $sum, 'total' => $sum])->save();
    }
}
