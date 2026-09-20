<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * একটা আর্থিক প্রতিষ্ঠান — যার কাছে ঋণ, আমানত, বীমা বা হিসাব আছে।
 *
 * ⓘ কেন এই তালিকা, আর কেন কেবল অর্থ মডিউলে: মাইগ্রেশনের মাথায়
 * (`the_same_bank_was_typed_a_new_way_every_time`)।
 *
 * ⚠️ মোছা হয় না, বন্ধ হয় — পুরনো ঋণ ও আমানত নামটা ধরে রাখে।
 */
class Institution extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const BANK = 'bank';

    public const NBFI = 'nbfi';

    public const INSURANCE = 'insurance';

    public const MFS = 'mfs';

    /** তালিকার ক্রমেই — ট্যাবগুলোও এই ক্রমে বসে */
    public const KINDS = [self::BANK, self::NBFI, self::INSURANCE, self::MFS];

    protected $table = 'fin_institutions';

    protected $fillable = [
        'company_id', 'kind', 'name_en', 'name_bn', 'short_code',
        'branch_name', 'contact_person', 'phone', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        /*
         * ⓘ তুলনার চাবি সবসময় নাম থেকেই — হাতে বসানো হয় না, তাই নাম
         * বদলালে চাবিও বদলায় আর নকলের পাহারা ফাঁকি খায় না।
         */
        static::saving(function (self $institution): void {
            $institution->name_key = self::keyFor((string) $institution->name_en);
        });
    }

    /**
     * দুইটা নাম "একই" কি না বোঝার চাবি।
     *
     * ⓘ ছোট হাতের অক্ষর, ফাঁকা এক ঘরে, আর `.` `,` বাদ: "Islami Bank Ltd."
     * আর "islami  bank ltd" একই। ⚠️ এর বেশি নয় — "IBBL" আর "Islami Bank"
     * আলাদাই থাকে; ওরা এক কি না সেটা মানুষ জানেন, যন্ত্র নয়।
     */
    public static function keyFor(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = str_replace(['.', ','], ' ', $name);

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'bn' && filled($this->name_bn) ? (string) $this->name_bn : (string) $this->name_en;
    }

    /** তালিকায় নাম, সংক্ষেপ থাকলে সাথে */
    public function label(): string
    {
        return filled($this->short_code) ? $this->name().' ('.$this->short_code.')' : $this->name();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind(Builder $query, ?string $kind): Builder
    {
        return $kind === null ? $query : $query->where('kind', $kind);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** জোড়া দেওয়া ব্যাংক/MFS খাত — [[InstitutionAccount]] */
    public function accountLinks(): HasMany
    {
        return $this->hasMany(InstitutionAccount::class);
    }

    public function insurancePolicies(): HasMany
    {
        return $this->hasMany(InsurancePolicy::class);
    }
}
