<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Core\Services\SettingsService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * এলাকার একটাই গাছ।
 *
 * দেশ › বিভাগ › অঞ্চল › এরিয়া › টেরিটরি › পয়েন্ট › রুট — সাতটা স্তর,
 * একটাই টেবিল।
 *
 * দুইটা টেবিল (এলাকা ও রুট) বানানোর প্রলোভনটা আসল, কারণ রুট দেখতে
 * আলাদা জিনিস মনে হয়। কিন্তু তখন "এই রুটটা কোন টেরিটরিতে" প্রশ্নের
 * উত্তর দুই জায়গা জোড়া দিয়ে বের করতে হত, আর একটা স্তর যোগ করতে গেলে
 * দুইটাই বদলাতে হত। এক গাছে সেই দুইটাই মিটে যায়।
 *
 * অঞ্চল ও টেরিটরি সুইচযোগ্য: ছোট প্রতিষ্ঠানে ওই দুই স্তর অর্থহীন, আর
 * বাধ্যতামূলক করলে সবাই একটা ভুয়া "মূল অঞ্চল" বানিয়ে রাখত — আর ভুয়া
 * সারি একবার ঢুকলে আর কখনো বেরোয় না।
 */
class Location extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_locations';

    public const COUNTRY = 'country';

    public const DIVISION = 'division';

    public const REGION = 'region';

    public const AREA = 'area';

    public const TERRITORY = 'territory';

    public const POINT = 'point';

    public const ROUTE = 'route';

    /**
     * উপর থেকে নিচে — এই ক্রমটাই মইয়ের সংজ্ঞা।
     *
     * @var list<string>
     */
    public const LADDER = [
        self::COUNTRY, self::DIVISION, self::REGION, self::AREA,
        self::TERRITORY, self::POINT, self::ROUTE,
    ];

    /**
     * যে দুইটা স্তর বন্ধ করা যায়।
     *
     * বাকি পাঁচটা নয়: দেশ ও বিভাগ ছাড়া ঠিকানা অসম্পূর্ণ, আর এরিয়া,
     * পয়েন্ট ও রুট ছাড়া ডেলিভারির পরিকল্পনা করা যায় না।
     *
     * @var list<string>
     */
    public const OPTIONAL_LEVELS = [self::REGION, self::TERRITORY];

    protected $fillable = [
        'company_id', 'parent_id', 'code', 'name_en', 'name_bn',
        'level', 'assigned_to', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /** রুটে কে যায় — ডেলিভারি ও আদায়ের দায়িত্ব। */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeAtLevel(Builder $query, string|array $level): Builder
    {
        return $query->whereIn('level', (array) $level);
    }

    /**
     * এই প্রতিষ্ঠানে যে স্তরগুলো চালু।
     *
     * সেটিংস থেকে আসে, কোড থেকে নয় — একই ইনস্টলেশনে এক কোম্পানি
     * টেরিটরি ব্যবহার করতে পারে আর অন্যটা না।
     *
     * @return list<string>
     */
    public static function activeLadder(): array
    {
        $settings = app(SettingsService::class);

        return array_values(array_filter(self::LADDER, fn (string $level) => match ($level) {
            self::REGION => $settings->enabled('master_data.region_enabled'),
            self::TERRITORY => $settings->enabled('master_data.territory_enabled'),
            default => true,
        }));
    }

    /**
     * এই স্তরের ঠিক উপরের চালু স্তর।
     *
     * অঞ্চল বন্ধ থাকলে এরিয়ার বাবা হয় বিভাগ, অঞ্চল নয় — বন্ধ স্তরটা
     * এড়িয়ে যেতে হয়, নাহলে গাছে একটা ফাঁক তৈরি হত যেখানে কিছু বসানো
     * যেত না।
     */
    public static function parentLevelOf(string $level): ?string
    {
        $ladder = self::activeLadder();
        $index = array_search($level, $ladder, true);

        if ($index === false || $index === 0) {
            return null;
        }

        return $ladder[$index - 1];
    }

    public static function childLevelOf(string $level): ?string
    {
        $ladder = self::activeLadder();
        $index = array_search($level, $ladder, true);

        if ($index === false || $index === count($ladder) - 1) {
            return null;
        }

        return $ladder[$index + 1];
    }

    /**
     * পূর্বপুরুষ থেকে নিজে পর্যন্ত পথ।
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $chain = new Collection;
        $node = $this->parent;

        // গভীরতার সীমা: তথ্য নষ্ট হয়ে চক্র তৈরি হলে এই লুপটা কখনো
        // থামত না। মই সাত স্তরের, তাই দশ যথেষ্ট।
        for ($depth = 0; $node !== null && $depth < 10; $depth++) {
            $chain->prepend($node);
            $node = $node->parent;
        }

        return $chain;
    }

    /** "ময়মনসিংহ › ত্রিশাল › রুট-৩" — ঠিকানার পুরো পথ। */
    public function path(string $separator = ' › '): string
    {
        return $this->ancestors()
            ->map(fn (self $node) => $node->name())
            ->push($this->name())
            ->implode($separator);
    }

    /**
     * নিজে ও নিচের সব।
     *
     * @return Collection<int, self>
     */
    public function selfAndDescendants(): Collection
    {
        /*
         * এক কোয়েরিতে সবাই — গাছ বেয়ে ধাপে ধাপে নয়।
         *
         * সম্পাদনার পর্দা এটাকে ডাকে ("নিজে ও নিজের নিচের কেউ বাবা হতে
         * পারে না")। আগে প্রতিটা ধাপে একটা কোয়েরি যেত, আর এলাকার মই
         * সাতটা ধাপ গভীর — দেশ খুলতে গেলে শাখা যত, কোয়েরিও তত।
         * [[Account::selfAndDescendants()]]-এ একই সমস্যা, একই সমাধান।
         */
        $pool = $this->relationLoaded('children')
            ? null
            : static::query()->select(['id', 'parent_id'])->get()->groupBy('parent_id');

        return $this->gather($pool);
    }

    /**
     * নিজে ও নিচের সবাই — আগে থেকে আনা তালিকা ধরে।
     *
     * @param  \Illuminate\Support\Collection<int|string, Collection<int, self>>|null  $pool
     * @return Collection<int, self>
     */
    private function gather(?\Illuminate\Support\Collection $pool): Collection
    {
        $all = new Collection([$this]);

        $children = $pool === null
            ? $this->children
            : ($pool->get($this->getKey()) ?? new Collection);

        foreach ($children as $child) {
            $all = $all->merge($child->gather($pool));
        }

        return $all;
    }

    /** এটাই কি সবচেয়ে নিচের স্তর — যেখানে ডেলিভারি হয়। */
    public function isRoute(): bool
    {
        return $this->level === self::ROUTE;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'location';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->path();
    }

    public function drillRoute(): array
    {
        return ['master_data.location.show', ['location' => $this->id]];
    }
}
