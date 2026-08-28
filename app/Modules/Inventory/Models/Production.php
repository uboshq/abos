<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * এক হাঁড়ির রান্না — উপকরণ গেল, তৈরি খাবার এল।
 *
 * ── এটা কোন ঘটনার কাগজ ──────────────────────────────────────────────
 * সকাল ৭টায় ১০ কেজি চাল আর ৪ কেজি মাংস হাঁড়িতে গেল, ৪৭ প্লেট হলো।
 * কোনো গ্রাহক নেই, কোনো বিল নেই — তবু গুদামের হিসাব দুই দিকেই নড়ল।
 *
 * ── খসড়া আর নিশ্চিত, দুইটা কেন ───────────────────────────────────────
 * খসড়া অবস্থায় কিছুই নড়ে না; কাগজটা কেবল লেখা থাকে। স্টক নড়ে
 * **নিশ্চিত করার মুহূর্তে**, আর সেটাই ঠিক: রাঁধুনি হাঁড়ি চড়িয়ে দিয়ে
 * পরে গুনে বলেন কয় প্লেট হলো, আর ততক্ষণে সংখ্যাটা বদলাতে পারে।
 *
 * বাকি সব কাগজের মতোই — বিল, চালান, স্থানান্তর।
 */
class Production extends Model
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** নম্বরের সিরিজ — "রান্না"। */
    public const NUMBER_SERIES = 'CKG';

    /** স্টক মুভমেন্টে এই কাগজের পরিচয়। */
    public const STOCK_SOURCE = 'production';

    protected $table = 'inv_productions';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'recipe_id', 'product_id', 'warehouse_id', 'trx_date',
        'qty', 'cost_total', 'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'cancelled_at' => 'datetime',

            // কয়টা হলো, আর মোট কত টাকার মাল গেল — দুইটাই টাকার অঙ্কে যায়
            'qty' => 'decimal:4',
            'cost_total' => 'decimal:4',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionLine::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * এক প্লেটে কত টাকার মাল গেল।
     *
     * ── কেন এটা মডেলে, রিপোর্টে নয় ──────────────────────────────────
     * একই সংখ্যা তিন জায়গায় লাগে: তৈরি খাবারের FIFO স্তরের দর,
     * তালিকার পর্দা, আর খাদ্য-খরচের রিপোর্ট। তিনবার লিখলে একদিন একটা
     * বদলাত আর বাকি দুইটা থেকে যেত।
     */
    public function unitCost(): string
    {
        if (bccomp((string) $this->qty, '0', 4) <= 0) {
            return '0';
        }

        return bcdiv((string) $this->cost_total, (string) $this->qty, 4);
    }
}
