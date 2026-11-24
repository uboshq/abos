<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ⭐ স্টক ছাড়া বিক্রয় ভাউচার (SV) আর ক্রয় ভাউচার (PUV) — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬।
 *
 * ⓘ মজুদ নড়ে না: সেবা, হিসাবে-কেবল পণ্য, খাত। ⛔ টাকার মাধ্যম, ব্যাংক বা নগদের ঘর নেই — বিল কেবল পাওনা (১১১০) বা দেনা
 * (২১১৮) বানায়; টাকা আসে রসিদে (RCV — মালিকের এক-কাগজের নিয়ম), যায় পরিশোধ ভাউচারে (SAP/D365/Odoo-র একই ভাগ)।
 *
 *   SV:  Dr ১১১০ প্রাপ্য (গ্রাহক)  /  Cr সারির আয়ের খাত · Cr ২১২০ ভ্যাট
 *   PUV: Dr সারির খরচ/ক্রয়ের খাত · Dr ১১৩৯ ক্রয়-ভ্যাট  /  Cr ২১১৮ দেনা (নিট) · Cr ২১২১ উৎসে কর
 *
 * ⓘ PUV-তে উৎসে কর বিলেই — সরবরাহকারীর খাতে জমা তখনই (আয়কর আইন ২০২৩: পরিশোধ বা জমা, যেটা আগে)। উৎসে ভ্যাট (VDS) এখানে নয়,
 * পরিশোধের সময় পরিশোধ ভাউচারে। গ্রাহকের কাটা কর বিক্রয়ের দিকে রসিদে, SV-তে নয়।
 */
class TradeVoucher extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    public const SALE = 'sale';

    public const PURCHASE = 'purchase';

    /** @var array<string, string> ধরন => নম্বরের সিরিজ */
    public const DOC_TYPES = [self::SALE => 'SV', self::PURCHASE => 'PUV'];

    protected $table = 'acc_trade_vouchers';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'kind', 'trx_date', 'party_type', 'party_id', 'due_on',
        'ref_no', 'mushak_no', 'mushak_date', 'amount', 'tax_amount', 'total', 'tds_amount', 'party_total',
        'narration', 'status', 'confirmed_by', 'confirmed_at', 'cancel_reason', 'cancelled_by', 'cancelled_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'due_on' => 'date',
            'mushak_date' => 'date',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'tds_amount' => 'decimal:4',
            'party_total' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TradeVoucherLine::class)->orderBy('line_no');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSale(): bool
    {
        return $this->kind === self::SALE;
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === DocumentStatus::CANCELLED;
    }

    /**
     * ⓘ খাতার নাম ধরন ধরে — "sales_voucher", "purchase_voucher"; লেজারের ঘর থেকে কাগজে ফেরার পথ দুটোই এই ক্লাস
     * (module.php `drill_sources`)।
     */
    public static function drillSourceType(): string
    {
        return 'trade_voucher';
    }

    public function sourceType(): string
    {
        return $this->kind === self::SALE ? 'sales_voucher' : 'purchase_voucher';
    }

    public function drillDocumentNo(): string
    {
        return (string) $this->document_no;
    }

    public function drillLabel(): string
    {
        return (string) $this->document_no;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['accounts.trade_voucher.show', ['kind' => $this->kind, 'voucher' => $this->id]];
    }
}
