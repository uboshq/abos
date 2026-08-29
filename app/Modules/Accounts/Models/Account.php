<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
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
    use IsAudited;
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

        /*
         * ── খোলার জের এখানে আর যোগ হয় না, ২৯ আগস্ট ২০২৬ ────────────
         * আগে `openingWithin()` দিয়ে সংখ্যাটা কোডে যোগ করা হত, অথচ
         * ট্রায়াল ব্যালেন্স ও স্থিতিপত্র সরাসরি খতিয়ান পড়ে — তাই
         * একই খাতের ব্যালেন্স দুই পর্দায় দুই রকম আসত। এখন জেরটা
         * সত্যিকারের দাখিলা হয়ে খতিয়ানেই বসে
         * ([[OpeningBalanceService]]), আর এখানে যোগ করলে দ্বিগুণ হত।
         */
        $net = bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);

        return $this->nature === self::CREDIT ? bcmul($net, '-1', 4) : $net;
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
        /*
         * তালিকা primeAncestry() দিয়ে এলে শিকড়টা সারির সাথেই এসেছে।
         *
         * না দেখলে প্রতিটা সারির জন্য parent, তার parent, তার parent —
         * স্তরে স্তরে একটা করে কোয়েরি। হিসাবের ছকের পাতায় ঠিক সেটাই
         * হচ্ছিল: ৮০টা খাত × ৩ স্তর, আর পাতাটা ১.১ সেকেন্ড নিত যেখানে
         * বাকি সব পাতা ০.১৫। বকেয়ার ক্ষেত্রে যেভাবে outstanding_net
         * বসিয়ে দেওয়া হয়, এটাও ঠিক তাই।
         */
        $primed = $this->getAttribute('ancestor_chain');

        if ($primed instanceof Collection) {
            return $primed;
        }

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
     * একগুচ্ছ খাতের শিকড় একবারেই বসিয়ে দেওয়া — সারিপ্রতি নয়, সব মিলিয়ে একটা কোয়েরি।
     *
     * ── কেন গোটা কোম্পানির খাত আনা হয়, শুধু দেখানোরগুলো নয় ──────────
     * তালিকায় সচরাচর সক্রিয় খাতগুলোই থাকে, কিন্তু একটা সক্রিয় খাতের
     * উপরের গোষ্ঠীটা নিষ্ক্রিয় হতে পারে। তখন শুধু তালিকার খাতগুলো দিয়ে
     * শিকড় বানালে পথটা মাঝপথে কেটে যেত — "১১০১ নগদ" দেখাত, অথচ ওটা
     * "১১০০ চলতি সম্পদ"-এর নিচে। পথ ভুল দেখানোর চেয়ে একটা বাড়তি
     * কোয়েরি সস্তা।
     *
     * @param  Collection<int, self>  $accounts
     * @return Collection<int, self>
     */
    public static function primeAncestry(Collection $accounts): Collection
    {
        if ($accounts->isEmpty()) {
            return $accounts;
        }

        $nodes = static::query()
            ->select(['id', 'parent_id', 'code', 'name_en', 'name_bn'])
            ->get()
            ->keyBy('id');

        foreach ($accounts as $account) {
            $chain = new Collection;
            $node = $nodes->get($account->parent_id);

            // একই ৩২ স্তরের সীমা, একই কারণে — চক্র থাকলে থেমে যেতে হবে।
            for ($depth = 0; $node !== null && $depth < 32; $depth++) {
                $chain->prepend($node);
                $node = $nodes->get($node->parent_id);
            }

            $account->setAttribute('ancestor_chain', $chain);

            /*
             * ঘরটা টেবিলে নেই, তাই "বদলে গেছে" তালিকাতেও থাকা চলবে না।
             *
             * না সরালে এরপর কেউ ওই মডেলটা save() করলে Eloquent
             * ancestor_chain নামের একটা কলাম লিখতে যেত আর SQL ভেঙে
             * পড়ত — দেখানোর জন্য বসানো একটা ঘর লেখার পথে বাধা হয়ে
             * দাঁড়াত।
             */
            $account->syncOriginalAttribute('ancestor_chain');
        }

        return $accounts;
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
