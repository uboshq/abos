<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ একটা চেক বই — কোন ব্যাংক হিসাবের, নম্বরের সীমা, পরের নম্বর, কোন মাপে ছাপা হয় (মালিকের নমুনা, ১০ নভেম্বর ২০২৬)।
 *
 * ⓘ `off_books` = হিসাব ছাড়া বই (মালিকের নিজের) — খাতার কোনো খাতে বাঁধা নয়, "শুধু চেক ছাপা" পাতার জন্য; ভাউচারের চেক এখান থেকে
 * কখনো নয়, আর হিসাব ছাড়া পাতায় কোম্পানির বই কখনো নয় ([[ChequePrinter]])।
 */
class ChequeBook extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_cheque_books';

    protected $fillable = ['company_id', 'bank_account_id', 'off_books', 'label', 'first_no', 'last_no', 'next_no', 'digits', 'layout_id', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['off_books' => 'boolean', 'is_active' => 'boolean', 'first_no' => 'integer', 'last_no' => 'integer', 'next_no' => 'integer', 'digits' => 'integer'];
    }

    /** @return BelongsTo<Account, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /** @return BelongsTo<ChequeLayout, $this> */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(ChequeLayout::class, 'layout_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereColumn('next_no', '<=', 'last_no');
    }

    /** ⓘ বইয়ে কটা পাতা বাকি — পরের নম্বর থেকে শেষ পর্যন্ত */
    public function leavesLeft(): int
    {
        return max(0, $this->last_no - $this->next_no + 1);
    }

    /** ⓘ নম্বর যেমন চেকে ছাপা — আগের শূন্যসহ (0045112) */
    public function number(int $no): string
    {
        return str_pad((string) $no, max(1, $this->digits), '0', STR_PAD_LEFT);
    }
}
