<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
/*
 * ⚠️ এই import-টা ছাড়া `Account::class` নিজের namespace-এ খোঁজা হত —
 * `App\Modules\Finance\Models\Account`, যেটা নেই। ⛔ ফল: মূলধনের পাতা
 * খুললেই ৫০০, আর ত্রুটিটা লাইভে ধরা পড়েছে, এখানে নয়।
 *
 * ⓘ Finance-এর বাকি চারটা মডেল (Deposit · DepositMovement ·
 * HandLoanMovement · Withdrawal) এটা আগে থেকেই লেখে — কেবল এই ফাইলটাই
 * বাদ পড়েছিল। ⭐ `accounts` module.php-এর `depends_on`-এ ঘোষিত, তাই
 * সীমানা ভাঙে না।
 */
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\MasterData\Models\Person;
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
        'company_id', 'branch_id', 'document_no', 'person_id', 'contributor_type',
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

    /**
     * কে দিলেন — নাম নয়, তালিকার সারি।
     *
     * ── ⛔ কেন নামটা সরানো হলো, ১৩ সেপ্টেম্বর ২০২৬ ───────────────────
     * আগে ঘরটা ছিল `contributor_name`, মুক্ত লেখা। মালিক নিজে প্রশ্নটা
     * করেছেন: *"একই মালিক আবার বিনিয়োগ করলে আবার নাম লিখতে হবে?"*
     *
     * দামটা টাইপ করার কষ্ট ছিল না। `CapitalService::positions()` দল
     * বাঁধত ওই নাম ধরে, তাই `Al Amin` ও `Al-Amin` **দুইজন অংশীদার** হয়ে
     * যেতেন — দুইজনের আলাদা নিট, আর দুইজনের আলাদা **অংশ %**। আর ওই
     * শতাংশটাই মুনাফা ভাগের হিসাব।
     *
     * ⓘ `contributor_type` (মালিক / অংশীদার) রয়ে গেছে, আর সেটা ঠিক —
     * ওটা **ভূমিকা**, পরিচয় নয়। একই মানুষ এক বছর অংশীদার, পরের বছর
     * মালিক হতে পারেন, আর তখনো তিনি একই সারি।
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    /**
     * কোন ভাউচারে খাতায় বসেছে — নিয়ম ১, "সংখ্যা থেকে কাগজে"।
     *
     * ── কেন এটা আজ পর্যন্ত ছিল না (১৩ সেপ্টেম্বর ২০২৬) ───────────────
     * `voucher_id` ঘরটা চিরকাল ছিল আর `post()` সেটা ভরত, কিন্তু সম্পর্কটা
     * ঘোষণা করা হয়নি — তাই `$entry->voucher` **চুপচাপ `null`** দিত।
     * ⚠️ কোনো ত্রুটি নয়: Eloquent অচেনা নামকে অনুপস্থিত অ্যাট্রিবিউট ধরে,
     * আর PHP `null->instrument_no` পড়তে গিয়েই তবে বাজে।
     *
     * ⓘ [[Withdrawal]]-এ ঠিক একই ঘর, আর ওখানে সম্পর্কটা আছে — অর্থাৎ
     * দুইটা প্রায়-একই নথি দুই রকম আচরণ করত, আর পার্থক্যটা কোথাও লেখা
     * ছিল না। ধরা পড়েছে লেনদেন নম্বরের পরীক্ষা লিখতে গিয়ে।
     *
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
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
