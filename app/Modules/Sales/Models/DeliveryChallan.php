<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Vehicle;
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
class DeliveryChallan extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use SoftDeletes;

    protected $table = 'sal_challans';

    public const STOCK_SOURCE = 'delivery_challan';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'customer_id', 'warehouse_id', 'sales_order_id', 'trx_date',
        'vehicle_id', 'vehicle_no', 'driver_name', 'do_no', 'total',
        'discount_amount', 'expense_amount', 'rounding_amount',
        'deposit_amount', 'credit_period_days',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
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
                ->orWhere('vehicle_no', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->search($term));
        });
    }

    public static function drillSourceType(): string
    {
        return 'delivery_challan';
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
