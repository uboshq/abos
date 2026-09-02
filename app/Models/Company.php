<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
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
 *
 * ── অডিট কেন (২ সেপ্টেম্বর ২০২৬) ─────────────────────────────────────
 * এতদিন এই মডেলে অডিট ছিল না। অথচ এখানে যা বদলায় তার প্রায় সবই
 * প্রতিষ্ঠানের পরিচয় বা আইনি তথ্য — **BIN, TIN, আইনি নাম, মুদ্রা**, আর
 * সবচেয়ে বড়টা **`is_active`**: ওই একটা ঘর মিথ্যা হলে একটা গোটা
 * প্রতিষ্ঠান বন্ধ হয়ে যায়, আর কে করল তার কোনো চিহ্ন থাকত না।
 */
class Company extends Model
{
    use HasFactory;
    use HasPublicId;
    use IsAudited;
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

    /**
     * অডিটের সারিটা কোন কোম্পানির খাতায় বসবে — নিজেরটায়।
     *
     * বাকি সব মডেল নিজের `company_id` ঘর থেকে উত্তর দেয়; কোম্পানির
     * সেই ঘরটা নেই, আর চলতি প্রসঙ্গ ধরে নিলে প্ল্যাটফর্ম-প্রশাসকের
     * করা সম্পাদনা **ভুল প্রতিষ্ঠানের খাতায়** বসত।
     */
    public function auditCompanyId(): ?int
    {
        return $this->id;
    }

    /**
     * কোম্পানির নিজের সম্পাদনা কোনো শাখার নয়।
     *
     * শাখাগুলো এরই সন্তান; বাবার ঘটনাকে একটা সন্তানের নামে লেখা মানে
     * অর্ধেক সত্য। আর সিডারে কোম্পানি তৈরি হয় শাখার আগে, তাই চলতি
     * প্রসঙ্গের শাখাটা তখনো থাকেই না।
     */
    public function auditBranchId(): ?int
    {
        return null;
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

    /**
     * ছাপার জন্য লোগো — ছবিটা নিজেই, পথ নয়।
     *
     * ── কেন পথ দিয়ে কাজ চলে না ──────────────────────────────────────
     * ছাপার লেআউটে লেখা ছিল `src="{{ storage_path('app/public/'.$path) }}"`।
     * Trade Depot-এর ফাইলটার নাম "Trade Depot.png" — **নামের মাঝে একটা
     * স্পেস**। উদ্ধৃতিহীন HTML অ্যাট্রিবিউটে স্পেস মানে সেখানেই মানটা
     * শেষ, তাই mPDF পথটা পেত "…/logos/Trade" পর্যন্ত, আর লোগোটা ভাঙা
     * দেখাত। FamilyMart-এর নামে স্পেস নেই বলে ওখানে ধরা পড়ত না, আর
     * Provati Traders-এ কোনো লোগোই ছিল না — এ কারণেই বাগটা "শুধু একটা
     * কোম্পানিতে" বলে মনে হয়েছিল।
     *
     * base64 বসালে পথের প্রশ্নই থাকে না: স্পেস, ব্যাকস্ল্যাশ, storage
     * symlink আছে কি নেই — কোনোটাই আর ব্যাপার নয়, আর mPDF-কে ডিস্কে
     * যেতেই হয় না।
     *
     * ফাইল না থাকলে বা পড়া না গেলে null — logoUrl()-এর মতোই। কলামে পথ
     * লেখা আছে অথচ ফাইল মুছে গেছে, এটা বাস্তবে ঘটে।
     */
    public function logoData(): ?string
    {
        if (! $this->logo_path || ! Storage::disk('public')->exists($this->logo_path)) {
            return null;
        }

        $bytes = Storage::disk('public')->get($this->logo_path);

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($this->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
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
