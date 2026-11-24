<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ নোটের একটা সারি — পণ্য, সেবা বা খাত (মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬)।
 *
 * ⓘ পণ্যের সারি মূল বিলের সারি ধরে (`source_line_id`) — পরিমাণ বিলের বেশি নয়, ভ্যাটের হার বিলের। "মাল ফেরত" টিক দেওয়া সারির
 * টাকা ফেরতের কাগজ (SR/PR) তোলে, নোট নয় ([[\App\Core\Contracts\ReturnsGoodsForNotes]])।
 */
class NoteLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const PRODUCT = 'product';

    public const SERVICE = 'service';

    public const ACCOUNT = 'account';

    /** @var list<string> */
    public const TYPES = [self::PRODUCT, self::SERVICE, self::ACCOUNT];

    protected $table = 'acc_note_lines';

    protected $fillable = [
        'company_id', 'note_id', 'line_no', 'line_type', 'product_id', 'item_name', 'description',
        'source_line_id', 'billed_qty', 'qty', 'unit', 'rate', 'amount', 'tax_id', 'vat_rate', 'vat_amount', 'total',
        'account_id', 'goods_moved', 'return_type', 'return_id', 'return_no', 'stock_share', 'stock_value', 'revalue_unit',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billed_qty' => 'decimal:4',
            'qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:4',
            'vat_rate' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'stock_share' => 'decimal:6',
            'stock_value' => 'decimal:4',
            'revalue_unit' => 'decimal:6',
            'goods_moved' => 'boolean',
        ];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** ⛔ এই সারির টাকা ফেরতের কাগজ তোলে — নোট তোলে না */
    public function postedByItsReturn(): bool
    {
        return $this->line_type === self::PRODUCT && (bool) $this->goods_moved;
    }
}
