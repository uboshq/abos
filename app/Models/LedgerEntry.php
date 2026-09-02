<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Security\LedgerChain;
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

    /**
     * প্রতিটা সারি আগের সারির ছাপ ধরে রাখে।
     *
     * ── কেন মডেলের ঘটনায়, PostingEngine-এ নয় ────────────────────────
     * ইঞ্জিনে বসালে সেখানে **দুই জায়গায়** লিখতে হত (পোস্ট আর
     * উল্টো-পোস্ট), আর তৃতীয় কোনো পথ এলে তৃতীয়বার। এখানে বসালে যে
     * সারিই বসুক — সিডার, ইমপোর্ট, ভবিষ্যতের কোনো সেবা — চেইনে ঢোকে।
     *
     * ঠিক এই কারণেই এই রিপোতে অডিটও মডেলের ঘটনা থেকে লেখা হয়।
     *
     * ── `creating`, `created` নয় ────────────────────────────────────
     * ছাপটা সারির সাথেই বসতে হবে, পরে নয়। `created`-এ বসালে একটা
     * দ্বিতীয় `UPDATE` লাগত, আর মাঝের ওই মুহূর্তে সারিটা চেইনহীন
     * অবস্থায় থাকত — আর সেখানেই একটা ক্র্যাশ চিরস্থায়ী ফাঁক রেখে যেত।
     */
    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            /*
             * সময়ের ছাপটা আগে বসাতে হয়, নিজে হাতে।
             *
             * Eloquent-এর `performInsert()` **প্রথমে `creating` ডাকে,
             * তারপর `updateTimestamps()`** — অর্থাৎ এই মুহূর্তে
             * `created_at` এখনো শূন্য। ছাপটা শূন্যের উপর বসত, আর
             * ডাটাবেজে সারিটা তারিখ নিয়ে বসত; পরে যাচাই করতে গেলে
             * **প্রতিটা সারিই "বদলে গেছে" দেখাত**।
             *
             * এখানে বসিয়ে দিলে ঘরটা dirty হয়ে যায়, আর Eloquent-এর
             * নিজের `updateTimestamps()` dirty ঘরে হাত দেয় না — তাই
             * ছাপ আর সারি একই সময় ধরে রাখে।
             */
            if ($entry->usesTimestamps() && $entry->getAttribute(self::CREATED_AT) === null) {
                $entry->updateTimestamps();
            }

            [$previous, $hash] = LedgerChain::next($entry);

            $entry->prev_hash = $previous;
            $entry->row_hash = $hash;
        });
    }

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
