<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Core\Concerns\HasPublicId;
use App\Core\Support\CompanyContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'locale', 'theme', 'ui', 'accent', 'is_active'])]
/*
 * গোপন চাবি ও পুনরুদ্ধার কোড কোনো JSON বা লগে যায় না।
 *
 * একটা `dd($user)` বা একটা API রেসপন্সেই চাবিটা বেরিয়ে গেলে MFA
 * শেষ — আর ওই ভুলটা ধরা পড়ত না, কারণ সবকিছু কাজ করতেই থাকত।
 */
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicId, HasRoles, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',

            /*
             * গোপন চাবি ও পুনরুদ্ধার কোড — ডাটাবেজে এনক্রিপ্টেড।
             *
             * ── কেন `encrypted`, শুধু হ্যাশ নয় ──────────────────────
             * TOTP-র চাবিটা যাচাইয়ের সময় **আসল রূপে** লাগে — ওটা দিয়েই
             * কোড বানিয়ে মেলানো হয়, তাই হ্যাশ করা যায় না। কিন্তু সাদা
             * রাখলে ডাটাবেজ ফাঁসে প্রতিটা চাবি বেরিয়ে যেত, আর তখন
             * আক্রমণকারী নিজেই কোড বানিয়ে নিতেন — MFA থাকত নামেই।
             *
             * পুনরুদ্ধার কোডগুলো উল্টো: ওগুলো হ্যাশ করাই থাকে (নিচে
             * `MfaService`), আর এই স্তরটা তার উপরে বাড়তি।
             */
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_confirmed_at' => 'datetime',
        ];
    }

    /**
     * ছবির ঠিকানা, না থাকলে null।
     *
     * ফাইলটা মুছে গেলেও কলামে পথ থেকে যেতে পারে (ব্যাকআপ থেকে ফেরানো,
     * হাতে মোছা)। তখন ভাঙা ছবির আইকনের বদলে আদ্যক্ষর দেখানোই ভালো, তাই
     * ফাইলটা আছে কি না দেখে নেওয়া হয়।
     */
    public function avatarUrl(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        if (! Storage::disk('public')->exists($this->avatar_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    /**
     * ছবি না থাকলে যে অক্ষরটা দেখানো হয়।
     *
     * mb_substr, substr নয় — বাংলা নামে প্রথম "অক্ষর" তিন বাইটের, আর
     * substr সেটাকে মাঝখান থেকে কেটে অর্থহীন বাইট ফেরত দিত।
     */
    public function initial(): string
    {
        $name = trim($this->name ?? '');

        return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
    }

    /** যে কোম্পানিগুলোতে এই ব্যবহারকারী ঢুকতে পারে। */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['default_branch_id', 'is_active'])
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    public function currentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
    }

    public function currentBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'current_branch_id');
    }

    public function canAccessCompany(int $companyId): bool
    {
        return $this->companies()->whereKey($companyId)->exists();
    }

    /**
     * কোম্পানি বদলানো।
     *
     * পছন্দটা ডাটাবেজে লেখা হয়, সেশনে নয় — DMS-এ এই একটা পার্থক্যের কারণেই
     * সুইচ পাতা রিলোড করলেই মুছে যেত। রেকর্ডে থাকলে অন্য ডিভাইসে লগইন করলেও
     * ব্যবহারকারী যেখানে ছিল সেখানেই ফেরে (সেকশন ১৫.১৫)।
     *
     * শাখাও একসাথে বদলাতে হয়: আগের কোম্পানির শাখা ধরে রাখলে পরের এন্ট্রি
     * ভুল কোম্পানির শাখায় বসত।
     */
    public function switchCompany(int $companyId, ?int $branchId = null): void
    {
        /*
         * অস্বীকারটা ব্যবহারকারীর ভাষায়, ভাঙা ৫০০ পাতায় নয়।
         *
         * ── কী ভাঙা ছিল (২ সেপ্টেম্বর ২০২৬) ─────────────────────────
         * এখানে `RuntimeException` ছিল, অর্থাৎ পর্দায় একটা ৫০০। কিন্তু
         * এটা ব্যবস্থার ভুল নয় — **এটা স্বাভাবিক ঘটনা**: কারও ট্যাব
         * খোলা ছিল, ইতিমধ্যে তাঁকে ওই কোম্পানি থেকে সরিয়ে দেওয়া
         * হয়েছে, আর তিনি পুরনো তালিকা থেকেই বেছেছেন।
         *
         * তিনি দেখতেন "কিছু একটা ভেঙে গেছে", অথচ আসল কথাটা ছিল
         * "আপনার আর ওখানে ঢোকার অধিকার নেই" — দুইটা সম্পূর্ণ আলাদা
         * খবর, আর দ্বিতীয়টা তিনি নিজে সামলাতে পারতেন।
         *
         * ── কেন `ValidationException`, `abort(403)` নয় ──────────────
         * ৪০৩ একটা খালি ত্রুটি-পাতা; ব্যবহারকারী যেখানে ছিলেন সেখান
         * থেকে ছিটকে যেতেন। ValidationException তাঁকে ওই পাতাতেই রাখে
         * আর ঘরের পাশে কারণটা লেখে — আর সেটাই তিনি করতে পারেন এমন
         * কাজের (রিফ্রেশ করে আবার বাছা) সবচেয়ে কাছের জায়গা।
         *
         * ── একটা পার্শ্বফল, যেটা কাম্য ──────────────────────────────
         * `ValidationException` [[ErrorJournal]]-এর "ভুল নয়" তালিকায়
         * পড়ে। আগে প্রতিটা বাসি ট্যাব ভুলের খাতায় একটা সারি বসাত —
         * অর্থাৎ যে খাতাটা আসল ভাঙন দেখানোর জন্য, সেটাই ভরে যেত
         * ব্যবস্থা ঠিক কাজ করার প্রমাণে।
         */
        if (! $this->canAccessCompany($companyId)) {
            throw ValidationException::withMessages([
                'company_id' => __('core.company.no_access'),
            ]);
        }

        $company = Company::query()->findOrFail($companyId);

        if ($branchId !== null) {
            $belongs = Branch::acrossAllCompanies()
                ->whereKey($branchId)
                ->where('company_id', $companyId)
                ->exists();

            if (! $belongs) {
                throw ValidationException::withMessages([
                    'branch_id' => __('core.company.branch_elsewhere'),
                ]);
            }
        } else {
            $pivotBranch = $this->companies()->whereKey($companyId)->first()?->pivot->default_branch_id;

            $branchId = $pivotBranch ?? CompanyContext::forCompany(
                $companyId,
                fn () => $company->defaultBranch()?->id,
            );
        }

        $this->forceFill([
            'current_company_id' => $companyId,
            'current_branch_id' => $branchId,
        ])->save();

        CompanyContext::set($companyId, $branchId);
    }
}
