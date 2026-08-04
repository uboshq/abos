<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * একটা প্রতিষ্ঠান। টেন্যান্সির উপরের স্তর — বাকি প্রায় সব টেবিলেই এর id বসে।
 *
 * এই মডেলে BelongsToCompany নেই, স্বাভাবিকভাবেই: কোম্পানি নিজেই সেই স্কোপের
 * উৎস, তাই নিজের উপর স্কোপ বসালে চক্র তৈরি হত।
 */
class Company extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name_en', 'name_bn', 'legal_name',
        'address_en', 'address_bn', 'phone', 'email', 'website',
        'bin', 'tin', 'logo_path', 'currency', 'locale', 'timezone', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function financialYears(): HasMany
    {
        return $this->hasMany(FinancialYear::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['default_branch_id', 'is_active'])
            ->withTimestamps();
    }

    public function defaultBranch(): ?Branch
    {
        return $this->branches()->where('is_default', true)->first()
            ?? $this->branches()->where('is_active', true)->orderBy('id')->first();
    }

    public function currentFinancialYear(): ?FinancialYear
    {
        return $this->financialYears()->where('is_current', true)->first();
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

    /**
     * লোগোর ঠিকানা, না থাকলে null।
     *
     * ডিস্কের নামটা এখানে একবার লেখা — ভিউতে নয়। ভিউতে Storage::url()
     * ডাকা হচ্ছিল, যেটা ডিফল্ট ডিস্ক ধরে; ফাইলটা আসলে public ডিস্কে,
     * আর দুটো একই পথ দিচ্ছিল বলে ভুলটা চোখে পড়েনি। ডিফল্ট ডিস্ক বদলালেই
     * প্রতিটা লোগো ভেঙে যেত।
     */
    public function logoUrl(): ?string
    {
        if (! $this->logo_path || ! Storage::disk('public')->exists($this->logo_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    public function address(?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'bn' && filled($this->address_bn)) {
            return $this->address_bn;
        }

        return $this->address_en;
    }
}
