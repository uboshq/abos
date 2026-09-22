<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\IsAudited;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\MasterData\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * এক অংশীদারের এক দফার মুনাফার ভাগ।
 *
 * ── ⭐ কেন সারিটা ব্যক্তির, ঘোষণার নয় ───────────────────────────────
 * এক ঘোষণায় যতজন ভাগ পাবেন, ততটা সারি — সবার `document_no` এক।
 * ⓘ [[CapitalEntry]] আর [[Withdrawal]] হুবহু এই ছাঁচেই বসে, আর
 * কারণটা এক: প্রশ্নগুলো প্রায় সবসময় **ব্যক্তি ধরে** আসে — *"রহিম
 * সাহেব এ পর্যন্ত কত পেয়েছেন"*, *"কার কত বাকি"*।
 *
 * ── ⚠️ অংশ ও ভিত্তি সারিতেই লেখা থাকে ──────────────────────────────
 * ⛔ `share_percent` আর `profit_base` হিসাব করে বের করা হয় না। পরে
 * অনুপাত বদলালে বা বছরের মুনাফা সংশোধিত হলে পুরনো ঘোষণার ভাগ বদলে
 * যেত — আর অনুমোদিত কাগজ নিজে থেকে বদলায় না।
 */
class ProfitShare extends Model
{
    use BelongsToCompany;
    use IsAudited;
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    protected $table = 'acc_profit_shares';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'trx_date', 'person_id',
        'share_percent', 'profit_base', 'amount', 'status', 'voucher_id',
        'narration', 'posted_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'posted_at' => 'datetime',

            /*
             * ⚠️ টাকার ঘরগুলোর মাপ বাকি সবের সমান — নাহলে যোগ-বিয়োগে
             * এক পয়সা করে হারাত, আর ধরা পড়ত মাস শেষে।
             */
            'share_percent' => 'decimal:4',
            'profit_base' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * ⓘ খাতায় বসে গেছে এমন সারিগুলোই — খসড়া টাকা নয়।
     *
     * ⚠️ খসড়া গুনলে "কাকে কত দিতে বাকি" সংখ্যাটা বেশি দেখাত, আর
     * নগদের পরিকল্পনা ভুল হত।
     *
     * @param  Builder<ProfitShare>  $query
     */
    public function scopePosted(Builder $query): void
    {
        $query->where('status', self::POSTED);
    }
}
