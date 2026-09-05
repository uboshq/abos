<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserWarehouse;
use App\Core\Contracts\Drillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা পণ্যের একটা উৎপাদন-লট।
 *
 * ── এখানে কোনো "কত আছে" কলাম নেই ────────────────────────────────────
 * খুঁজলে পাবেন না, আর সেটা ইচ্ছাকৃত। ব্যাচে কত আছে তা `balance()`
 * চলাচলের সারি যোগ করে বের করে — ঠিক যেমন গ্রাহকের পাওনা লেজার যোগ
 * করে বের হয়, আর গুদামের মজুদ চলাচল যোগ করে।
 *
 * একটা কলাম রাখলে সেটা একই সত্যের দ্বিতীয় কপি হত, আর দুই কপি একদিন
 * আলাদা হয়ই — সাধারণত যেদিন কিছু বাতিল হয় আর দুইটার একটা উল্টে যায়।
 * তখন কোনটা সত্যি তা বলার কোনো উপায় থাকে না, আর গুদামে গিয়ে গুনেও
 * মেলানো যায় না, কারণ প্রশ্নটা "তাকে কত" নয়, "খাতা কী বলছে"।
 */
class Batch extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    /*
     * মেয়াদ আর ছাপা দাম বদলালে চিহ্ন থাকতে হবে।
     *
     * একটা লটের মেয়াদের তারিখ পিছিয়ে দিলে মেয়াদোত্তীর্ণ মাল আবার
     * বিক্রয়যোগ্য হয়ে যায়, আর MRP বাড়িয়ে দিলে ছাপা দামের সীমাটাই
     * সরে যায়। দুইটাই এক ঘরের সম্পাদনা, আর দুইটাই ধরা না পড়ার মতো —
     * তাই পুরনো আর নতুন মান দুইটাই রাখা হয়।
     */
    use IsAudited;
    use ScopedToUserWarehouse;
    use SoftDeletes;

    protected $table = 'inv_batches';

    protected $fillable = [
        'company_id', 'branch_id', 'product_id', 'batch_no',
        'expiry_date', 'manufactured_on', 'mrp', 'supplier_ref', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'manufactured_on' => 'date',
            'mrp' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }

    /**
     * এই ব্যাচে এখন কতটা আছে।
     *
     * তাকের সংখ্যা (`floor_change`), কারণ ব্যাচ একটা ভৌত লট — কতটা
     * বেচা যাবে সেই প্রশ্নটা আলাদা, আর সেটা পণ্য-স্তরের চারটা অবস্থার
     * কাজ।
     *
     * ── কেন `unplaced_change`-ও গোনা হয়, ৫ সেপ্টেম্বর ২০২৬ ───────────
     * Stock Placement আসার পর আসা মাল আর সরাসরি তাকে ওঠে না — কেউ
     * বুঝে নেওয়ার আগে সে অপেক্ষার ঘরে বসে। ⛔ কেবল তাক গুনলে **সদ্য
     * আসা গোটা লটটাই অদৃশ্য** হয়ে যেত।
     *
     * ⚠️ আর ফার্মেসিতে এটা নিরাপত্তার প্রশ্ন: মেয়াদ আর রিকল ভৌত মালের
     * কথা বলে, তাকের কথা নয়। রিকলের দিন গুদামে পড়ে থাকা কার্টনটাই
     * সবচেয়ে সহজে আটকানো যায় — সেটাকে "নেই" বলা সবচেয়ে খারাপ উত্তর।
     *
     * ⓘ বিক্রয়ের FEFO এই সংখ্যাটা ব্যবহার করে না (মেপে দেখা: একমাত্র
     * পাঠক [[BatchTrace::onHand()]]), তাই বসানো হয়নি এমন লট থেকে
     * বেচার সুযোগ এতে তৈরি হয় না — ওই দরজা `floor` দেখেই থামে।
     */
    public function balance(?Warehouse $warehouse = null): string
    {
        return (string) $this->movements()
            ->when($warehouse !== null, fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->sum(DB::raw('floor_change + unplaced_change'));
    }

    /**
     * এই ব্যাচে কতটা **ফ্রি** মাল আছে।
     *
     * ── কেন আলাদা, `balance()`-এর সাথে যোগ করা নয় ────────────────────
     * ফ্রি মাল একই ভৌত লটেরই অংশ — একই কার্টন, একই ব্যাচ নম্বর, একই
     * মেয়াদ। তাই লোভ হয় দুইটা একসাথে গুনে "এই লটে মোট কত" বলার।
     *
     * কিন্তু তাহলে বিক্রয়ের FEFO এমন লট বেছে নিত যেখানে কেবল ফ্রি মাল
     * আছে — কাউন্টারে "লটে ৫ আছে" দেখিয়ে বেচতে গেলে থামত, আর কর্মী
     * বুঝতেন না কেন। ভাণ্ডার দুইটা আলাদা রাখার যে যুক্তি (৮ আগস্ট),
     * লটের ভেতরেও সেই একই যুক্তি খাটে।
     */
    public function freeBalance(?Warehouse $warehouse = null): string
    {
        return (string) $this->movements()
            ->when($warehouse !== null, fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->sum('free_change');
    }

    /** মেয়াদ পেরিয়ে গেছে কি না — যেদিন জিজ্ঞেস করা হচ্ছে সেই দিন ধরে। */
    public function hasExpired(?Carbon $on = null): bool
    {
        if ($this->expiry_date === null) {
            return false;
        }

        return $this->expiry_date->lt(($on ?? now())->startOfDay());
    }

    /**
     * মেয়াদ শেষ হতে কত দিন — শেষ হয়ে গেলে ঋণাত্মক।
     *
     * মেয়াদহীন ব্যাচে `null`, শূন্য নয়: শূন্য মানে "আজ শেষ", আর
     * "মেয়াদ নেই" তার উল্টো কথা। দুইটা এক করে ফেললে মেয়াদহীন মাল
     * প্রতিদিন সতর্কতার তালিকায় উঠত।
     */
    public function daysLeft(?Carbon $on = null): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        return (int) ($on ?? now())->startOfDay()->diffInDays($this->expiry_date, false);
    }

    /**
     * FEFO — আগে যেটার মেয়াদ শেষ, সেটা আগে।
     *
     * ── কেন মেয়াদহীন ব্যাচ সবার শেষে ────────────────────────────────
     * ওগুলো খারাপ হয় না, তাই তাড়া নেই। SQL-এ NULL-এর ক্রম ডাটাবেজভেদে
     * আলাদা (MySQL-এ NULL আগে, PostgreSQL-এ পরে), তাই ক্রমটা হাতে বলা
     * — নাহলে একই কোড দুই জায়গায় দুই রকম মাল বের করত।
     *
     * সমান মেয়াদে আগে-আসাটা আগে (`id`), যাতে ক্রমটা স্থির থাকে।
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id');
    }

    /** মেয়াদ পেরোয়নি এমন ব্যাচ। */
    public function scopeUnexpired(Builder $query, ?Carbon $on = null): Builder
    {
        $day = ($on ?? now())->startOfDay()->toDateString();

        return $query->where(fn ($q) => $q
            ->whereNull('expiry_date')
            ->orWhere('expiry_date', '>=', $day));
    }

    public function label(): string
    {
        $parts = array_filter([
            $this->batch_no,
            $this->expiry_date?->format('m/Y'),
        ]);

        return implode(' · ', $parts);
    }

    public static function drillSourceType(): string
    {
        return 'batch';
    }

    /**
     * ব্যাচের "ডকুমেন্ট নম্বর" তার লট নম্বরই।
     *
     * আমাদের বানানো কোনো নম্বর নয় — কার্টনের গায়ে সরবরাহকারী যা
     * লিখেছেন। রিকলে ওই নম্বরটাই বলা হবে, তাই ড্রিল-ডাউনেও ওটাই।
     */
    public function drillDocumentNo(): string
    {
        return (string) $this->batch_no;
    }

    public function drillLabel(): string
    {
        return ($this->product?->name() ?? '').' — '.$this->label();
    }

    public function drillRoute(): array
    {
        // ব্যাচের নিজের পর্দা এখনো নেই; পণ্যের পাতাই তার ঘর।
        return ['inventory.product.show', ['product' => $this->product_id]];
    }
}
