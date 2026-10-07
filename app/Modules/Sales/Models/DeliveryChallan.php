<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\ShowsItselfForSigning;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Sales\Models\Concerns\TellsTheDeliveryStage;
use App\Modules\Sales\Support\SalesSigningSheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ডেলিভারি চালান — মাল বেরিয়ে গেছে।
 *
 * এটাই স্টক তাক থেকে নামায়, আর অর্ডারে ধরা থাকলে সেই ধরাটাও ছেড়ে দেয় —
 * দুইটা একই চলাচলে। আলাদা করলে একদিন একটা বসে অন্যটা বসত না, আর তখন
 * মালটা একইসাথে "গেছে" ও "ধরা আছে" দেখাত।
 *
 * খতিয়ানে কিছু বসে না। মাল বেরোনো মানে বিক্রি নয় — ফেরত আসতে পারে, আর
 * দাম এখনো ঠিক হয়নি। আয় বসে বিলের দিনে।
 */
class DeliveryChallan extends Model implements Drillable, ShowsItselfForSigning
{
    use BelongsToCompany;
    use \App\Modules\Sales\Models\Concerns\CarriesTheSalesChannel;
    use TellsTheDeliveryStage;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    // ⭐ বিক্রয়কর্মী কেবল নিজের বাঁধা ডিলারের কাগজ দেখেন — ⛔১৬, ২ অক্টোবর ২০২৬ ([[DealerScope]])
    use \App\Core\Concerns\ScopedToUserDealers;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'sal_challans';

    public const STOCK_SOURCE = 'delivery_challan';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no', 'sale_no',
        'customer_id', 'warehouse_id', 'sales_order_id', 'trx_date',
        'vehicle_id', 'vehicle_no', 'driver_name', 'driver_phone', 'do_no', 'total',
        'discount_amount', 'expense_amount', 'rounding_amount',
        'deposit_amount', 'credit_period_days', 'payment_term',

        /* ছয়টা বোতামের ঘর — সরাসরি বিক্রয়ের পর্দা, ২৯ আগস্ট ২০২৬ */
        'expense_narration', 'carrier_name', 'carrier_id', 'transport_cost', 'own_transport',
        'ship_to', 'ship_date', 'deposit_method', 'deposit_ref',
        // ⭐ মাল কীভাবে যাবে · গাড়ি কার · ভাড়া কে দেবে — কাউন্টার, ৪ অক্টোবর ২০২৬ (খালি = আজকের আচরণ)
        'delivery_mode', 'vehicle_owner', 'fare_paid_by',
        // ⭐ গেট পাসে মাল বেরোনো (sales.invoice_at_goods_issue, ৪ অক্টোবর ২০২৬) — false মানে আগের নিয়ম
        'issue_at_gate', 'goods_issued_at',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            /* টাকা float নয় — [[MoneyIsNeverAFloatTest]] */
            'transport_cost' => 'decimal:4',
            'own_transport' => 'boolean',
            'ship_date' => 'date',
            'issue_at_gate' => 'boolean',
            'goods_issued_at' => 'datetime',

            'trx_date' => 'date',
            'cancelled_at' => 'datetime',
            'total' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'expense_amount' => 'decimal:4',
            'rounding_amount' => 'decimal:4',
            'deposit_amount' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryChallanLine::class)->orderBy('line_no');
    }

    /**
     * ⭐ সইয়ের ছাপে কোন সারি — মালের সারি আর উপহারের সারি (২৮ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ ফ্রি মালও কাগজের অংশ: সইয়ের পর উপহার বাড়ালে সইটা আর খাটে না।
     * ⛔ আগে ছাপ নির্ভর করত ডাকার জায়গায় কী তোলা ছিল তার উপর, আর সই-হওয়া
     * কাউন্টার-বিক্রি চালান পাকা করতে গিয়ে আবার সই চাইত ([[DocumentFingerprint::asStored()]])।
     *
     * @return list<string>
     */
    public function fingerprintRelations(): array
    {
        return ['lines', 'giftLines'];
    }

    /** উপহারের সারি — অন্য পণ্য, বিক্রির জন্য নয়। */
    public function giftLines(): HasMany
    {
        return $this->hasMany(DeliveryChallanGiftLine::class)->orderBy('line_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * কাগজে যে নম্বরটা ছাপা হবে।
     *
     * ── কেন বহরের গাড়িটা আগে ────────────────────────────────────────
     * দুই জায়গায় নম্বর থাকতে পারে, আর দুইটা আলাদা হলে সত্যিটা বহরেরটাই:
     * নম্বরপ্লেট বদলালে মাস্টারে একবার ঠিক করলেই পুরনো চালানগুলোও ঠিক
     * নম্বর দেখায়, অথচ লেখা নম্বরটা যেদিন টাইপ হয়েছিল সেদিনেই আটকে থাকে।
     *
     * বহরের বাইরের গাড়ি হলে লেখা নম্বরটাই একমাত্র নম্বর।
     */
    public function vehiclePlate(): string
    {
        return $this->vehicle?->registration_no ?? (string) $this->vehicle_no;
    }

    /**
     * মাল কীভাবে গেল — পাতা আর ছাপার একই উত্তর (লাইভের যাচাই, ২৯ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ নিশ্চিতের আগে এটা বলতেই হয় ([[TransportRule]]), তাই কাগজেও ওঠে: ক্রেতার নিজের গাড়ি হলে
     * "নিজস্ব পরিবহন", নাহলে বাহকের নাম (লেখা নাম, নয়তো তালিকার বাহক); কিছু না থাকলে ফাঁকা —
     * গাড়ি ও চালকের ঘর আলাদা ([[vehiclePlate()]])।
     */
    public function transportLabel(): string
    {
        if ($this->own_transport) {
            return __('sales::field.transport_own');
        }

        if (filled($this->carrier_name)) {
            return (string) $this->carrier_name;
        }

        return $this->carrier_id !== null ? (string) $this->carrier?->name() : '';
    }

    /** ⓘ তালিকার বাহক — সম্পর্ক, যাতে পাতায় একবারে আসে ([[transportLabel()]]) */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Supplier\Models\Supplier::class, 'carrier_id');
    }

    /**
     * ⭐ মাল কীভাবে গেল — পুরো তথ্য, এক জায়গায় (মালিক, ২ অক্টোবর ২০২৬: *"Gate Pass e transport driver details nai"*)।
     *
     * ⓘ গেট পাসের কাগজ আর অ্যাপের স্ক্যান-পর্দা দুটোই এখান থেকে পড়ে — তাই চালানে যা বসানো, গেট পাসে হুবহু তা।
     * ধরন: `customer_self` (ক্রেতা নিজে নিলেন — `driver_name` তখন যিনি নিলেন), `vehicle` (গাড়ি — বহরের বা লেখা
     * নম্বর), `carrier` (কেবল বাহক/কোম্পানি, গাড়ির নম্বর ছাড়া — সরাসরি ডেলিভারি), না থাকলে null।
     *
     * @return array{mode: ?string, vehicle_no: string, vehicle_type: ?string, driver_name: ?string, driver_phone: ?string, carrier: string, cost: ?string}
     */
    public function transportFacts(): array
    {
        $plate = $this->vehiclePlate();
        $carrier = $this->own_transport ? '' : $this->transportLabel();
        $cost = $this->transport_cost !== null && bccomp((string) $this->transport_cost, '0', 4) > 0
            ? (string) $this->transport_cost : null;

        return [
            'mode' => match (true) {
                (bool) $this->own_transport => 'customer_self',
                $plate !== '' => 'vehicle',
                $carrier !== '' => 'carrier',
                default => null,
            },
            'vehicle_no' => $plate,
            'vehicle_type' => $this->vehicle?->vehicleType?->name(),
            'driver_name' => filled($this->driver_name) ? (string) $this->driver_name : $this->vehicle?->driver_name,
            'driver_phone' => filled($this->driver_phone) ? (string) $this->driver_phone : $this->vehicle?->driver_phone,
            'carrier' => $carrier,
            'cost' => $cost,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
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
                // ⭐ বিক্রির নম্বরেও (S-0154) — নতুন কাগজের নিজের নম্বর INV-/CHA-/GP-0154, বিক্রিরটা `sale_no`-তে (৬ অক্টোবর ২০২৬)
                ->orWhere('sale_no', 'like', "%{$term}%")
                ->orWhere('vehicle_no', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->search($term));
        });
    }

    public static function drillSourceType(): string
    {
        return 'delivery_challan';
    }

    /** সইকারীর পাতায় — পণ্য, পরিমাণ, ফ্রি, দর, টাকা ([[SalesSigningSheet]])। */
    public function signingSheet(): array
    {
        return SalesSigningSheet::ofChallan($this);
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->customer?->name() ?? $this->document_no;
    }

    public function drillRoute(): array
    {
        return ['sales.challan.show', ['challan' => $this->id]];
    }
}
