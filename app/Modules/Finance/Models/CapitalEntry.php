<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * কে ব্যবসায় টাকা দিলেন — মূলধন বা বিনিয়োগ।
 *
 * ── কেন দুইটা অবস্থা, আর কেবল দুইটা ─────────────────────────────────
 * `draft` — কথা হয়েছে, টাকা আসেনি। `posted` — টাকা এসেছে, খাতায় বসেছে।
 *
 * তৃতীয় কোনো অবস্থা নেই কারণ তৃতীয় কোনো ঘটনা নেই: হয় টাকাটা এসেছে,
 * নয় আসেনি। "বাতিল" রাখলে একটা না-আসা টাকার সারি চিরকাল তালিকায়
 * থেকে যেত, আর কেউ বলতে পারত না ওটা আসবে না কি ভুলে গেছে।
 */
class CapitalEntry extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    public const OWNER = 'owner';

    public const PARTNER = 'partner';

    public const INVESTOR = 'investor';

    /** @var list<string> */
    public const WHO = [self::OWNER, self::PARTNER, self::INVESTOR];

    /*
     * মূলধন আর বিনিয়োগের তফাত।
     *
     * মূলধন মালিকের নিজের টাকা — লাভ-লোকসান তাঁর। বিনিয়োগ বাইরের
     * কারও, আর তার শর্ত থাকে। খাতায় দুইটাই ইকুইটিতে বসে, কিন্তু
     * "কার টাকা কত" প্রশ্নে দুইটা আলাদা উত্তর — আর ওটাই অংশীদারি
     * ব্যবসার প্রথম ঝগড়া।
     */
    public const CONTRIBUTION = 'contribution';

    public const INVESTMENT = 'investment';

    /** @var list<string> */
    public const KINDS = [self::CONTRIBUTION, self::INVESTMENT];

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    protected $table = 'acc_capital_entries';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'contributor_name', 'contributor_type',
        'entry_type', 'trx_date', 'amount', 'share_percent', 'narration', 'status',
        'voucher_id', 'received_into_account_id', 'posted_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'amount' => 'decimal:4',
            'share_percent' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'received_into_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<CapitalEntry>  $query */
    public function scopePosted(Builder $query): void
    {
        $query->where('status', self::POSTED);
    }
}
