<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা স্থায়ী সম্পদ — ভ্যান, ফ্রিজ, আসবাব।
 *
 * ── কেন দাম আর ক্ষয় আলাদা থাকে ──────────────────────────────────────
 * সম্পদের খাত ধরে রাখে কেনার দাম, আর সঞ্চিত অবচয়ের খাত ধরে রাখে কতটা
 * ক্ষয়ে গেছে। সরাসরি সম্পদের খাত থেকে কাটলে দুইটার একটাই থাকত, আর
 * "গাড়িটা কত দিয়ে কেনা হয়েছিল" প্রশ্নের উত্তর হারিয়ে যেত — অথচ বিমা,
 * বিক্রি ও কর, তিন জায়গাতেই ওই সংখ্যাটা লাগে।
 */
class FixedAsset extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    // ⛔ শাখার দেয়াল — হেডারের শাখা আর মানুষের নাগাল (অডিট ⛔৪, ৬ অক্টোবর ২০২৬; [[ScopedToUserBranch]])
    use ScopedToUserBranch;
    use SoftDeletes;

    /** সমান কিস্তিতে ক্ষয় — প্রতি মাসে একই অঙ্ক। */
    public const STRAIGHT_LINE = 'straight';

    /** অবশিষ্ট দামের উপর হার — প্রথম বছরগুলোয় বেশি, পরে কম। */
    public const REDUCING = 'reducing';

    /** ⭐ ব্যবহারের এককে — চলা কিলোমিটার, যন্ত্রের ঘণ্টা (IAS 16.62; স্থায়ী সম্পদ ধাপ ২, [[DepreciationEngine]]) */
    public const UNITS = 'units';

    /** @var list<string> */
    public const METHODS = [self::STRAIGHT_LINE, self::REDUCING, self::UNITS];

    public const ACTIVE = 'active';

    public const DISPOSED = 'disposed';

    /** ⭐ নিবন্ধন সইয়ের অপেক্ষায় — অবচয় ধরে না, খাতায় নেই (গ১, ৪ অক্টোবর ২০২৬) */
    public const AWAITING = 'awaiting';

    /*
     * ⭐ ব্যবহারের অবস্থা — অলস, মেরামতে, বাতিল, হারানো (মালিক, ১০ অক্টোবর ২০২৬; IAS 16.55)।
     * ⓘ "ব্যবহারে" আগের মতোই `active` — পুরনো সারি, পুরনো কোড অক্ষত। অলস আর মেরামতে থাকা জিনিসও খাতায় থাকে আর ক্ষয়
     * ধরে ([[inService()]]): IAS 16 বলে অলস থাকলেও অবচয় থামে না। বাতিল আর হারানো খাতা থেকে বেরোনোর ঘটনা — টাকার কাজ,
     * তাই সইসহ আলাদা পথে (ধাপ ৩)।
     */
    public const IDLE = 'idle';

    public const UNDER_REPAIR = 'under_repair';

    public const WRITTEN_OFF = 'written_off';

    public const LOST = 'lost';

    /** @var list<string> খাতায় আছে, কাজে লাগছে বা লাগতে পারে */
    public const IN_SERVICE = [self::ACTIVE, self::IDLE, self::UNDER_REPAIR];

    /** @var list<string> হাতে বদলানো যায় এমন অবস্থা — টাকা নড়ে না */
    public const SWITCHABLE = [self::ACTIVE, self::IDLE, self::UNDER_REPAIR];

    /** @var list<string> খাতা থেকে বিদায়ের তিন পথ — বিক্রি, বাতিল, হারানো/চুরি (ধাপ ৩) */
    public const LEAVING = [self::DISPOSED, self::WRITTEN_OFF, self::LOST];

    /** @var list<string> পর্দা আর ছাঁকনির ক্রম */
    public const STATUSES = [self::AWAITING, self::ACTIVE, self::IDLE, self::UNDER_REPAIR, self::DISPOSED, self::WRITTEN_OFF, self::LOST];

    protected $table = 'acc_fixed_assets';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'name', 'tag_no',
        'asset_account_id', 'accumulated_account_id', 'expense_account_id',
        'cost', 'salvage', 'acquired_on', 'method', 'life_months', 'rate',
        'status', 'disposed_on', 'disposal_amount', 'disposal_reason', 'narration', 'created_by',
        // ⭐ নিবন্ধনের ঘর — ধাপ ১ (মালিক, ১০ অক্টোবর ২০২৬)
        'category_id', 'parent_id', 'location', 'department', 'custodian_id', 'supplier_id',
        'purchase_bill_id', 'purchase_bill_line_id', 'capitalised_qty', 'put_in_use_on',
        'serial_no', 'model_no', 'warranty_ends_on', 'insurance_policy_no', 'insured_until',
        // ⭐ ব্যবহারের এককে মোট একক — ধাপ ২
        'total_units',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
            'salvage' => 'decimal:4',
            'disposal_amount' => 'decimal:4',
            'rate' => 'decimal:4',
            'acquired_on' => 'date',
            'disposed_on' => 'date',
            'life_months' => 'integer',
            'capitalised_qty' => 'decimal:4',
            'total_units' => 'decimal:4',
            'put_in_use_on' => 'date',
            'warranty_ends_on' => 'date',
            'insured_until' => 'date',
        ];
    }

    public function depreciation(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class)->orderBy('period_end');
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    /** ⓘ অংশ হলে তার মূল সম্পদ (IAS 16.43) */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(AssetUsage::class, 'fixed_asset_id')->orderBy('period_end');
    }

    public function estimateChanges(): HasMany
    {
        return $this->hasMany(AssetEstimateChange::class, 'fixed_asset_id')->orderByDesc('changed_on')->orderByDesc('id');
    }

    public function costParts(): HasMany
    {
        return $this->hasMany(AssetCostPart::class, 'fixed_asset_id')->orderBy('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isAwaiting(): bool
    {
        return $this->status === self::AWAITING;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isDisposed(): bool
    {
        return $this->status === self::DISPOSED;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /** খাতায় আছে আর ক্ষয় ধরে — ব্যবহারে, অলস বা মেরামতে */
    public function isInService(): bool
    {
        return in_array($this->status, self::IN_SERVICE, true);
    }

    public function scopeInService(Builder $query): Builder
    {
        return $query->whereIn('status', self::IN_SERVICE);
    }

    /** ⓘ ক্ষয় শুরুর দিন — ব্যবহার শুরু লেখা থাকলে সেটা, নইলে কেনার দিন (IAS 16.55) */
    public function depreciatesFrom(): Carbon
    {
        return ($this->put_in_use_on ?? $this->acquired_on)->copy()->startOfDay();
    }

    public function statusLabel(): string
    {
        return __('accounts::asset.status_'.$this->status);
    }

    /**
     * এ পর্যন্ত মোট কতটা ক্ষয় ধরা হয়েছে।
     *
     * ⭐ পাকা ঘটনাগুলোও (ধাপ ৩): দাম পড়ার লোকসান সঞ্চিত ক্ষয়ে যোগ হয় (IAS 36), পুনর্মূল্যায়নে সঞ্চিত ক্ষয় মুছে যায়
     * (IAS 16.35খ)। খাতার সঞ্চিত ক্ষয়ের খাতও ঠিক এভাবেই নড়ে, তাই খাতা আর সম্পদের পাতা এক অঙ্ক দেখায়।
     */
    public function accumulated(?Carbon $upTo = null): string
    {
        $query = $this->depreciation();

        if ($upTo !== null) {
            $query->where('period_end', '<=', $upTo->toDateString());
        }

        $events = $this->postedEvents($upTo)->sum('accumulated_change') ?: '0';

        return bcadd((string) ($query->sum('amount') ?: '0'), (string) $events, 4);
    }

    /**
     * ⭐ কোনো দিনে সম্পদের দাম — সেদিনের পরের সংযোজন আর পুনর্মূল্যায়ন বাদ দিয়ে (ধাপ ৩)।
     *
     * ⓘ সারির `cost` সবসময় আজকের দাম। পুরনো মাসের অবচয় হিসাব করতে গেলে পরের মাসের সংযোজন তাতে ঢুকে যেত।
     */
    public function costOn(?Carbon $upTo = null): string
    {
        if ($upTo === null) {
            return (string) $this->cost;
        }

        $later = AssetEvent::query()->withoutGlobalScope('user-branch')
            ->where('fixed_asset_id', $this->id)->posted()
            ->where('happened_on', '>', $upTo->toDateString())
            ->sum('cost_change') ?: '0';

        return bcsub((string) $this->cost, (string) $later, 4);
    }

    /**
     * পাকা ঘটনা, দিন পর্যন্ত।
     *
     * ⚠️ শাখার দেয়াল ছাড়া: সম্পদ অন্য শাখায় গেলে আগের শাখার ঘটনাও তার হিসাবের অংশ। দেয়ালটা সম্পদের সারিতে থাকে।
     *
     * @return Builder<AssetEvent>
     */
    public function postedEvents(?Carbon $upTo = null): Builder
    {
        return AssetEvent::query()->withoutGlobalScope('user-branch')
            ->where('fixed_asset_id', $this->id)->posted()
            ->when($upTo !== null, fn ($q) => $q->where('happened_on', '<=', $upTo->toDateString()));
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class, 'fixed_asset_id')->withoutGlobalScope('user-branch')
            ->orderByDesc('happened_on')->orderByDesc('id');
    }

    /**
     * খাতায় এখন জিনিসটার দাম।
     *
     * কেনার দাম বিয়োগ এ পর্যন্তকার ক্ষয়। এটাই ব্যালেন্স শিটে বসে, আর
     * বিক্রির দিন লাভ-লোকসান এই সংখ্যাটার সাথে তুলনা করেই বেরোয়।
     */
    public function bookValue(?Carbon $upTo = null): string
    {
        return bcsub($this->costOn($upTo), $this->accumulated($upTo), 4);
    }

    /**
     * আর কতটা ক্ষয় ধরা বাকি।
     *
     * বাতিল মূল্যের নিচে নামা যায় না — ওটুকু দামে জিনিসটা আয়ু শেষেও
     * বিক্রি হবে বলে ধরা হয়েছে। না আটকালে খাতায় দাম শূন্য হয়ে যেত,
     * অথচ ভাঙারির দোকানে ওটার এখনো দাম আছে।
     */
    public function depreciableLeft(?Carbon $upTo = null): string
    {
        $floor = bcsub($this->costOn($upTo), (string) $this->salvage, 4);
        $done = $this->accumulated($upTo);
        $left = bcsub($floor, $done, 4);

        return bccomp($left, '0', 4) > 0 ? $left : '0.0000';
    }

    public function isFullyDepreciated(): bool
    {
        return bccomp($this->depreciableLeft(), '0', 4) <= 0;
    }

    public static function drillSourceType(): string
    {
        return 'fixed_asset';
    }

    /**
     * বিদায়ের দিনের দাখিলার নিজস্ব চাবি।
     *
     * ── ⛔ কী ভাঙা ছিল, ২০ সেপ্টেম্বর ২০২৬ ─────────────────
     * নিবন্ধন আর বিদায় — দুইটাই `fixed_asset#<id>` নামে খাতায় বসত।
     * ⚠️ [[PostingEngine::assertNotAlreadyPosted]] নিবন্ধনের সারিগুলো খোলা
     * দেখে বিদায়টাই আটকে দিত — ফলে **যে সম্পদের টাকার উৎস লেখা
     * আছে, সেটা আর বিক্রি করাই যেত না**। ⛔ আর সবচেয়ে খারাপ ফলটা
     * নীরব: বিক্রির টাকা খাতায় উঠত না, সম্পদটা স্থিতিপত্রে থেকে যেত,
     * আর প্রতি মাসে এমন জিনিসের উপর অবচয় বসত যেটা আর নেই।
     *
     * ⓘ অবচয় এই ফাঁদে পড়েনি, কারণ তার নিজের সারি আছে
     * ([[DepreciationEntry]]) — তাই প্রতি মাসে আলাদা আইডি। ⭐ বিদায়ের
     * সারি নেই, তাই চাবিটাই আলাদা হয়।
     */
    public static function disposalSourceType(): string
    {
        return 'asset_disposal';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->name.' — '.$this->document_no;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['accounts.asset.show', ['asset' => $this->id]];
    }
}
