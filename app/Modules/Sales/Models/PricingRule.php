<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\CompanyContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * মান দাম থেকে কতটা সরা যাবে, আর সরলে কী।
 *
 * ── কেন সারি না থাকাও একটা উত্তর ──────────────────────────────────────
 * যে কোম্পানি কোনোদিন সীমা বসায়নি, সে কাউকে থামাতে বলেনি। [[forCompany()]]
 * তাই সবসময় একটা নিয়ম ফেরত দেয় — সারি না থাকলে "সব চলবে", আর সেটা
 * একটা সত্যিকারের নীতি, নিয়মের অনুপস্থিতি নয়।
 *
 * অনুপস্থিতিকে সবচেয়ে কড়া নিয়ম ধরে নিলে আপগ্রেডের দিন সকালে প্রতিটা
 * কাউন্টার থেমে যেত।
 */
class PricingRule extends Model
{
    use HasPublicId;
    use IsAudited;

    /** কিছুই না — শুধু খাতায় লেখা থাকে। */
    public const ALLOW = 'allow';

    /** পর্দায় সতর্কতা, কিন্তু বিল আটকায় না। */
    public const WARN = 'warn';

    /** সারিটাই নেওয়া হয় না। */
    public const BLOCK = 'block';

    protected $table = 'sal_pricing_rules';

    protected $fillable = [
        'company_id', 'tolerance_percent', 'policy',
        'applies_below', 'applies_above', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tolerance_percent' => 'decimal:4',
            'applies_below' => 'boolean',
            'applies_above' => 'boolean',
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * এই কোম্পানির নিয়ম — সারি না থাকলেও একটা উত্তর।
     *
     * সংরক্ষণ করা হয় না, কেবল বানানো হয়: একটা পর্দা "এখন নীতি কী"
     * জিজ্ঞেস করলেই ডাটাবেজে সারি বসে যাওয়ার কোনো কারণ নেই।
     */
    public static function forCompany(?int $companyId = null): self
    {
        $companyId ??= CompanyContext::id();

        return static::query()->where('company_id', $companyId)->first()
            ?? new self([
                'company_id' => $companyId,
                'tolerance_percent' => '0',
                'policy' => self::ALLOW,
                'applies_below' => true,
                'applies_above' => true,
            ]);
    }

    /**
     * এই দরটা মান দাম থেকে কতটা সরে আছে — শতাংশে, চিহ্নসহ।
     *
     * ঋণাত্মক মানে মান দামের নিচে। চিহ্নটা রাখা হয় কারণ নিচে আর উপরে
     * দুইটা আলাদা সুইচ, আর চিহ্ন ছাড়া কোনদিকে সরেছে তা বলা যেত না।
     */
    public function driftOf(string $rate, string $standard): ?string
    {
        if (bccomp($standard, '0', 4) <= 0) {
            /*
             * মান দাম না জানা থাকলে সরে যাওয়া মাপা যায় না।
             *
             * শূন্যকে মান ধরে নিলে প্রতিটা দরই "অসীম শতাংশ উপরে" হত,
             * আর আটকানোর নীতিতে কোনো বিলই কাটা যেত না।
             */
            return null;
        }

        return bcdiv(bcmul(bcsub($rate, $standard, 6), '100', 6), $standard, 4);
    }

    /**
     * এই দরে কী করা হবে — মানা, সতর্কতা, নাকি আটকানো।
     *
     * ── কেন সহনসীমার ভেতরে থাকলে নীতিটাই দেখা হয় না ─────────────────
     * সীমাটার পুরো কাজই হলো "এতটুকু সরা স্বাভাবিক" বলা। ভেতরে থেকেও
     * সতর্কতা এলে সতর্কতাটা রোজ আসত, আর রোজ আসা সতর্কতা কেউ পড়ে না।
     */
    public function verdictOn(string $rate, string $standard): string
    {
        if ($this->policy === self::ALLOW) {
            return self::ALLOW;
        }

        $drift = $this->driftOf($rate, $standard);

        if ($drift === null) {
            return self::ALLOW;
        }

        $below = bccomp($drift, '0', 4) < 0;

        if (($below && ! $this->applies_below) || (! $below && ! $this->applies_above)) {
            return self::ALLOW;
        }

        $distance = ltrim($drift, '-');

        return bccomp($distance, (string) $this->tolerance_percent, 4) > 0
            ? $this->policy
            : self::ALLOW;
    }
}
