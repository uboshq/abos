<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * জমার একটা ধরন — খোলা তালিকার সারি, কোডের enum নয়।
 *
 * ── কেন তিনটা আকৃতি, পনেরোটা ধরন নয় ─────────────────────────────────
 * খুঁজে দেখা গেছে সঞ্চয়পত্র পাঁচ রকম, ব্যাংকের স্কিম আরও দশ-বারো —
 * আর কাল আরেকটা আসবে। কিন্তু টাকার চলাচলের আকৃতি মাত্র তিনটা, আর
 * পর্দায় কোন ঘর দেখাতে হবে সেটা ওটাই ঠিক করে।
 */
class DepositKind extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    /** একবারে জমা, মেয়াদান্তে মুনাফা — FDR · ডাবল বেনিফিট · ৫ বছর সঞ্চয়পত্র */
    public const AT_MATURITY = 'at_maturity';

    /** একবারে জমা, নিয়মিত মুনাফা তোলা — মাসিক মুনাফা · পরিবার · পেনশনার */
    public const PERIODIC_PAYOUT = 'periodic_payout';

    /** মাসে মাসে জমা, মেয়াদান্তে একসাথে — DPS · শিক্ষা · বিবাহ */
    public const INSTALMENT = 'instalment';

    /** @var list<string> */
    public const SHAPES = [self::AT_MATURITY, self::PERIODIC_PAYOUT, self::INSTALMENT];

    /**
     * কে কাগজটা ছাপে — আর এটাই মেনুর তিনটা সারি।
     *
     * ── কেন এটা ছাঁকনি, আলাদা টেবিল নয় ─────────────────────────────
     * তিনটার ঘর একই: কোথায় রাখা, কত, কত হারে, কবে মেয়াদ। তিনটা
     * টেবিল করলে "মোট কত টাকা সরিয়ে রাখা আছে" প্রশ্নের উত্তর দিতে
     * তিনটা জোড়া লাগত।
     *
     * মেনুতে তিনটা সারি থাকে অন্য কারণে — কেউ "জমা" খোঁজে না, খোঁজে
     * "সঞ্চয়পত্র"।
     */
    public const BANK = 'bank';

    public const NATIONAL_SAVINGS = 'national_savings';

    public const BOND = 'bond';

    /** @var list<string> */
    public const ISSUERS = [self::BANK, self::NATIONAL_SAVINGS, self::BOND];

    protected $table = 'fin_deposit_kinds';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'shape', 'issuer',
        'personal_only', 'is_active', 'sort',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'personal_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * পর্দায় যে নামটা বসে।
     *
     * বাংলা নামটা ঐচ্ছিক, তাই না থাকলে ইংরেজিটাই — নাহলে ড্রপডাউনে
     * একটা ফাঁকা সারি বসত, আর ব্যবহারকারী জানতেন না ওটা কী।
     */
    public function name(): string
    {
        return app()->getLocale() === 'bn'
            ? ($this->name_bn ?: $this->name_en)
            : $this->name_en;
    }

    /** কিস্তির ঘর দেখাতে হবে কি না। */
    public function takesInstalments(): bool
    {
        return $this->shape === self::INSTALMENT;
    }

    /** মুনাফা কোথায় জমা হবে, সেটা জিজ্ঞেস করতে হবে কি না। */
    public function paysOut(): bool
    {
        return $this->shape === self::PERIODIC_PAYOUT;
    }
}
