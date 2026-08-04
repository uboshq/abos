<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Contracts\Drillable;
use App\Core\Support\RunningBalance;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * হিসাবের একটা খাত।
 *
 * পুরো সিস্টেমের সবচেয়ে নিচের স্তর: বিক্রয়, ক্রয়, বেতন, ঋণ — প্রতিটা
 * লেনদেন শেষমেশ দুইটা খাতের মধ্যে টাকা সরায়। তাই এই মডেলটা ভুল হলে বাকি
 * সব মডিউল ভুল হয়।
 */
class Account extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasDocumentStatus;
    use HasFactory;
    use HasPublicId;
    use SoftDeletes;

    /** পাঁচটা মূল ধরন — এর বাইরে কিছু নেই। */
    public const ASSET = 'asset';

    public const LIABILITY = 'liability';

    public const EQUITY = 'equity';

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    /** @var list<string> */
    public const TYPES = [self::ASSET, self::LIABILITY, self::EQUITY, self::INCOME, self::EXPENSE];

    /**
     * ব্যালেন্স শিটে যায় যেগুলো — বাকিগুলো লাভ-লোকসানে।
     *
     * @var list<string>
     */
    public const BALANCE_SHEET_TYPES = [self::ASSET, self::LIABILITY, self::EQUITY];

    public const DEBIT = 'debit';

    public const CREDIT = 'credit';

    protected $fillable = [
        'company_id', 'parent_id', 'code', 'name_en', 'name_bn',
        'type', 'nature', 'is_group', 'is_cash', 'is_bank', 'is_system',
        'opening_balance', 'opening_date',
        'account_number', 'bank_name', 'branch_name',
        'status', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'is_cash' => 'boolean',
            'is_bank' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'opening_balance' => 'decimal:4',
            'opening_date' => 'date',
        ];
    }

    /**
     * কোন ধরনের স্বাভাবিক দিক কী।
     *
     * সম্পদ ও খরচ বাড়ে ডেবিটে; দায়, মূলধন ও আয় বাড়ে ক্রেডিটে। এটাই
     * নতুন খাতের ডিফল্ট, কিন্তু বাধ্যতামূলক নয় — "সঞ্চিত অবচয়" সম্পদ
     * হয়েও ক্রেডিট প্রকৃতির, আর সেটা হাতে বদলানো যায়।
     */
    public static function defaultNatureFor(string $type): string
    {
        return in_array($type, [self::ASSET, self::EXPENSE], true) ? self::DEBIT : self::CREDIT;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ব্যবহারকারীর ভাষায় নাম — বাংলা না থাকলে ইংরেজি (সেকশন ১৮.৩)। */
    public function name(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'bn' && filled($this->name_bn)) {
            return $this->name_bn;
        }

        return $this->name_en;
    }

    /** কোড ও নাম একসাথে — ড্রপডাউনে ও রিপোর্টে এই রূপেই দেখা যায়। */
    public function label(?string $locale = null): string
    {
        return $this->code.' — '.$this->name($locale);
    }

    /** যেগুলোতে সরাসরি এন্ট্রি বসানো যায়। */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_group', false);
    }

    public function scopeOfType(Builder $query, string|array $type): Builder
    {
        return $query->whereIn('type', (array) $type);
    }

    /** নগদ বা ব্যাংক — টাকার খাত। */
    public function scopeMoney(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('is_cash', true)->orWhere('is_bank', true));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('code', 'like', $like)
                ->orWhere('name_en', 'like', $like)
                ->orWhere('name_bn', 'like', $like);
        });
    }

    /**
     * এই খাতের ব্যালেন্স — একটা তারিখ পর্যন্ত।
     *
     * গ্রুপ খাতে নিজের কোনো এন্ট্রি থাকে না, তাই সন্তানদের যোগফল ফেরত
     * দেওয়া হয়। এটা না করলে ব্যালেন্স শিটে প্রতিটা মাথা শূন্য দেখাত আর
     * শুধু পাতার খাতগুলোতে সংখ্যা থাকত।
     *
     * ফেরত আসে স্বাভাবিক দিক অনুযায়ী ধনাত্মক: ক্রেডিট প্রকৃতির খাতে
     * ক্রেডিট বেশি হলে সংখ্যাটা ধনাত্মক। নাহলে প্রতিটা রিপোর্টে আলাদা
     * করে চিহ্ন উল্টাতে হত, আর কোথাও না কোথাও বাদ পড়ত।
     */
    public function balanceOn(?string $upto = null, ?int $branchId = null): string
    {
        if ($this->is_group) {
            return $this->children->reduce(
                fn (string $carry, self $child) => bcadd($carry, $child->balanceOn($upto, $branchId), 4),
                '0',
            );
        }

        $row = LedgerEntry::query()
            ->forAccount($this->id)
            ->when($upto, fn (Builder $q, string $date) => $q->whereDate('trx_date', '<=', $date))
            ->when($branchId, fn (Builder $q, int $branch) => $q->where('branch_id', $branch))
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $signed = bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);
        $opening = $this->openingWithin($upto);

        $net = bcadd($opening, $signed, 4);

        return $this->nature === self::CREDIT ? bcmul($net, '-1', 4) : $net;
    }

    /**
     * খোলা ব্যালেন্স হিসাবে ধরা হবে কি না।
     *
     * তারিখ দেওয়া থাকলে খোলার তারিখের পরের রিপোর্টেই কেবল ধরা হয় —
     * নাহলে ব্যবসা শুরুর আগের একটা রিপোর্টেও খোলা ব্যালেন্স দেখা যেত।
     */
    private function openingWithin(?string $upto): string
    {
        $opening = (string) $this->opening_balance;

        if (bccomp($opening, '0', 4) === 0) {
            return '0';
        }

        if ($upto !== null && $this->opening_date !== null && $this->opening_date->gt($upto)) {
            return '0';
        }

        // সংরক্ষিত হয় স্বাভাবিক দিকে ধনাত্মক হিসেবে; এখানে ডেবিট-ধনাত্মক
        // চিহ্নে ফেরানো হয়, কারণ উপরের হিসাবটা ওই চিহ্নেই চলে।
        return $this->nature === self::CREDIT ? bcmul($opening, '-1', 4) : $opening;
    }

    /** এই খাতে কোনো এন্ট্রি বসেছে কি না — মোছার আগে দেখা হয়। */
    public function hasEntries(): bool
    {
        return LedgerEntry::query()->forAccount($this->id)->exists();
    }

    /**
     * পূর্বপুরুষ থেকে নিজে পর্যন্ত পথ — "১১০০ চলতি সম্পদ › ১১০১ নগদ"।
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $chain = new Collection;
        $node = $this->parent;

        // গভীরতার সীমা: তথ্য নষ্ট হয়ে চক্র তৈরি হলে (parent নিজের সন্তান)
        // এই লুপটা কখনো থামত না আর পাতাটা ঝুলে যেত। AccountService চক্র
        // তৈরি হতে দেয় না, কিন্তু পড়ার কোড তার উপর নির্ভর করে না।
        for ($depth = 0; $node !== null && $depth < 32; $depth++) {
            $chain->prepend($node);
            $node = $node->parent;
        }

        return $chain;
    }

    /**
     * নিজে ও নিচের সব — শাখা সরানো বা নিষ্ক্রিয় করার সময় লাগে।
     *
     * @return Collection<int, self>
     */
    public function selfAndDescendants(): Collection
    {
        $all = new Collection([$this]);

        foreach ($this->children as $child) {
            $all = $all->merge($child->selfAndDescendants());
        }

        return $all;
    }

    /**
     * লেজারের সারিগুলো চলমান ব্যালেন্স সহ — খাতের পাতা ও Ledger রিপোর্ট।
     *
     * @param  \Illuminate\Support\Collection<int, LedgerEntry>|Collection<int, LedgerEntry>  $entries
     */
    public function withRunningBalance(iterable $entries, string $opening = '0'): void
    {
        $running = new RunningBalance($opening);

        foreach ($entries as $entry) {
            $entry->running_balance = $running->add($entry->debit, $entry->credit);
        }
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'account';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['accounts.coa.show', ['account' => $this->id]];
    }
}
