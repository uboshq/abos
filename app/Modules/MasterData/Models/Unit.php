<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা একক — পিস, কার্টন, কেজি, বস্তা।
 *
 * রূপান্তর এই মডেলেই: কার্টনের base_unit_id পিস আর factor ১২ মানে
 * "১ কার্টন = ১২ পিস"। আলাদা টেবিলে রাখলে একই এককের দুইটা রূপান্তর
 * বসানো যেত, আর তখন কোনটা সত্যি তা বলার উপায় থাকত না।
 */
class Unit extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    /**
     * এককের সিঁড়ি সর্বোচ্চ কত ধাপ গভীর হতে পারে।
     *
     * ── কেন সংখ্যাটার একটা নাম দরকার ────────────────────────────────
     * সীমাটা এই ফাইলে দুইবার লাগে (`toBase()` আর `rootUnitId()`), আর
     * `PackConversion`-এও এর একটা কপি বসে ছিল — `private const
     * MAX_DEPTH = 8`, যার মন্তব্যে লেখা ছিল "Unit::toBase()-এর সমান"।
     *
     * ⛔ কিন্তু ঐ কপিটা কোথাও ব্যবহার হত না, আর এখানে সংখ্যাটা কাঁচা
     * লেখা ছিল। অর্থাৎ একই সীমার তিনটা রূপ: দুইটা কাঁচা `8`, আর একটা
     * নাম যা কিছুই বাঁধত না। কেউ একটা বদলালে বাকিগুলো **নীরবে** দ্বিমত
     * করত, আর মৃত ধ্রুবকটা পড়ে মনে হত সীমাটা এক জায়গায় বাঁধা আছে।
     *
     * ⓘ এখন নামটা এখানেই, যেখানে সীমাটা সত্যিই বসানো হয়।
     */
    public const MAX_DEPTH = 8;

    protected $table = 'mdm_units';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'base_unit_id', 'factor', 'allows_fraction',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:6',
            'allows_fraction' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_unit_id');
    }

    /** এটাই কি ভিত্তি একক — যার নিচে আর কিছু নেই। */
    public function isBase(): bool
    {
        return $this->base_unit_id === null;
    }

    /**
     * এই এককের কতটা মানে ভিত্তি এককের কতটা।
     *
     * সরাসরি factor ফেরত দেওয়া হয় না, কারণ একক দুই স্তর গভীর হতে
     * পারে: বস্তা → কেজি → গ্রাম। প্রতিটা স্তরের factor গুণ করতে হয়,
     * নাহলে বস্তার হিসাব কেজিতে থেমে যেত।
     *
     * গভীরতার সীমা: তথ্য নষ্ট হয়ে চক্র তৈরি হলে (একক নিজের ভিত্তি)
     * এই লুপটা কখনো থামত না।
     */
    public function toBase(string $quantity = '1'): string
    {
        $factor = '1';
        $node = $this;

        for ($depth = 0; $node !== null && $depth < self::MAX_DEPTH; $depth++) {
            $factor = bcmul($factor, (string) $node->factor, 6);
            $node = $node->baseUnit;
        }

        return bcmul($quantity, $factor, 6);
    }

    /**
     * সিঁড়ির একদম নিচের একক — যার আর কোনো ভিত্তি নেই।
     *
     * দুইটা একক তুলনাযোগ্য কিনা এটাই বলে দেয়: বস্তা আর গ্রাম দুইটারই
     * গোড়া গ্রাম, তাই একটাকে অন্যটায় বদলানো যায়। পিস আর কেজির গোড়া
     * আলাদা — ওদের মধ্যে রূপান্তর মানে বানানো একটা সংখ্যা।
     *
     * সীমাটা toBase()-এর মতোই: তথ্য নষ্ট হয়ে চক্র হলে লুপটা থামত না।
     */
    public function rootUnitId(): int
    {
        $node = $this;

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            if ($node->base_unit_id === null || $node->baseUnit === null) {
                return $node->id;
            }

            $node = $node->baseUnit;
        }

        return $node->id;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'unit';
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
        return ['master_data.unit.show', ['unit' => $this->id]];
    }
}
