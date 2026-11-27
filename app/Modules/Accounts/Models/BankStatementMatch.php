<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ ব্যাংক-সারি ↔ খাতার সারি — একটা ব্যাংক-সারির কয়েকটা কাগজ (মালিক, ২১ নভেম্বর ২০২৬; one-to-many reconciliation)।
 *
 * ⓘ ৳১,০০,০০০-এর এক ব্যাংক-সারি = RCV-0537 (৳৫০,০০০) + RCV-0538 (৳৫০,০০০)। ⛔ এক খাতার সারি একবারই (unique);
 * দলের মোট = ব্যাংক-সারির অঙ্ক, হুবহু ([[\App\Modules\Accounts\Services\BankStatementService::matchBooks()]])।
 */
class BankStatementMatch extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_bank_statement_matches';

    protected $fillable = [
        'company_id', 'statement_line_id', 'voucher_line_id', 'amount', 'matched_by', 'matched_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'matched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BankStatementLine, $this> */
    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'statement_line_id');
    }

    /** @return BelongsTo<VoucherLine, $this> */
    public function voucherLine(): BelongsTo
    {
        return $this->belongsTo(VoucherLine::class, 'voucher_line_id');
    }

    /** @return BelongsTo<User, $this> */
    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    /**
     * ⛔ এই খাতার সারিগুলোর কোনোটা কি ব্যাংকে মেলানো? — নতুন টেবিল, আর পুরনো চিহ্ন (`matched_line_id`) দুইটাই দেখে।
     *
     * @param  iterable<int>  $voucherLineIds
     */
    public static function holdsAnyOf(iterable $voucherLineIds): bool
    {
        $ids = collect($voucherLineIds)->map(fn ($id) => (int) $id)->filter()->values()->all();

        if ($ids === []) {
            return false;
        }

        return self::query()->withoutGlobalScopes()->whereIn('voucher_line_id', $ids)->exists()
            || BankStatementLine::query()->withoutGlobalScopes()->whereIn('matched_line_id', $ids)->exists();
    }
}
