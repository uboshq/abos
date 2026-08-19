<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Engines\Drill\DrillResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * হিসাবের খাতার একটা লাইন। Posting engine ছাড়া কেউ এখানে লেখে না।
 *
 * মডেলে কোনো create/update পদ্ধতি নেই ইচ্ছাকৃতভাবে — লেজারে সরাসরি রো বসানো
 * গেলে কেউ একদিন শুধু ডেবিট বসিয়ে ক্রেডিট ভুলে যাবে, আর ট্রায়াল ব্যালেন্স
 * মেলাতে কয়েক দিন যাবে। সব লেখা PostingEngine-এর ভেতর দিয়ে, যেখানে
 * ডেবিট-ক্রেডিটের সমতা যাচাই হয়।
 */
class LedgerEntry extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'cost_center_id',
        'company_id', 'branch_id', 'financial_year_id', 'account_id',
        'party_type', 'party_id', 'trx_date', 'debit', 'credit',
        'source_type', 'source_id', 'source_line_id', 'document_no',
        'narration', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
        ];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('trx_date', [$from, $to]);
    }

    public function scopeForParty(Builder $query, string $type, int $id): Builder
    {
        return $query->where('party_type', $type)->where('party_id', $id);
    }

    /** এই লাইনটা কোন ডকুমেন্ট থেকে এল — নিয়ম ১। */
    public function drill(): array
    {
        return app(DrillResolver::class)->describe($this->source_type, $this->source_id);
    }

    public function amount(): string
    {
        return bccomp((string) $this->debit, '0', 4) > 0 ? (string) $this->debit : (string) $this->credit;
    }
}
