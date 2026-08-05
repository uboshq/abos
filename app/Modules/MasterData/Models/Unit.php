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

        for ($depth = 0; $node !== null && $depth < 8; $depth++) {
            $factor = bcmul($factor, (string) $node->factor, 6);
            $node = $node->baseUnit;
        }

        return bcmul($quantity, $factor, 6);
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
