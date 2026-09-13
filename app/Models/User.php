<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\CompanyContext;
use App\Notifications\PasswordResetLink;
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
/*
 * টোকেন — মোবাইল অ্যাপের জন্য, ২ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন Sanctum, JWT নয় ─────────────────────────────────────────────
 * একটাই কারণ, আর সেটা ব্যবসার: **হারানো ফোন**। Sanctum-এর টোকেন
 * ডাটাবেজের একটা সারি, তাই সারিটা মুছলেই ওই ফোন তৎক্ষণাৎ বাইরে। JWT
 * বাতিল করা যায় না — মেয়াদ শেষ না হওয়া পর্যন্ত সেটা বৈধ থাকে, আর
 * ডিপোর ফোন হারায়।
 *
 * দাম: প্রতি অনুরোধে একটা বাড়তি DB পড়া। "ফোনটা এখনই বন্ধ করো" বলতে
 * পারার তুলনায় সেটা সস্তা।
 */
use Laravel\Sanctum\HasApiTokens;
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
    use HasApiTokens, HasFactory, HasPublicId, HasRoles, Notifiable, SoftDeletes;

    /*
     * ⛔ কে ঢুকতে পারেন — সেই সিদ্ধান্তটাও এখন খাতায়, ১২ সেপ্টেম্বর ২০২৬।
     *
     * ── কী ভাঙা ছিল ─────────────────────────────────────────────────
     * এই মডেলে অডিট ছিল না। ফলে `UserController::store()` একটা গোটা
     * অ্যাকাউন্ট বানাত আর `update()` নাম-ইমেইল-`is_active` বদলাত,
     * অথচ **খাতায় একটা সারিও বসত না**। কেবল তিনটা কাজ আলাদা করে
     * লেখা হত — `password_set`, `roles_changed`, `scopes_changed` —
     * কারণ ওগুলোর কোনোটাই ব্যবহারকারীর নিজের সারিতে ঘটে না।
     *
     * ⚠️ অর্থাৎ যে প্রশ্ন তিনটা নিরীক্ষায় প্রথমে আসে, তার একটারও উত্তর
     * ছিল না:
     *
     *     এই অ্যাকাউন্টটা কে বানাল, আর কবে
     *     কার ইমেইল বদলে দেওয়া হলো (ইমেইলই লগইনের পরিচয়)
     *     কাকে নিষ্ক্রিয় করা হলো, আর কে করল
     *
     * ⓘ শেষেরটাই সবচেয়ে ধারালো: `is_active` মিথ্যা হলে একজন মানুষ
     * আর ঢুকতে পারেন না, আর কেউ সেটা নীরবে করে দিতে পারতেন।
     *
     * ── কেন এটা রুচির প্রশ্ন নয় ─────────────────────────────────────
     * ⭐ আর্থিক নিরীক্ষায় ব্যবহারকারী-ব্যবস্থাপনার **পূর্ণ** ট্রেইল
     * বাধ্যতামূলক — কারণ প্রতিটা বিলের নিচে যে নামটা বসে, সেই নামটা
     * কে তৈরি করল আর কে তার অধিকার বদলাল, সেটা না জানলে বিলের
     * স্বাক্ষরটারও কোনো মানে থাকে না।
     *
     * ⓘ [[EveryChangeableRowRemembersWhoChangedItTest]] এটা ধরতে পারত
     * না: সে `class X extends ...Model` খোঁজে, আর [[User]] বাড়ে
     * `Authenticatable` থেকে। ⚠️ পাহারাটা ফাঁকি দেয়নি, **তার চোখই
     * এখানে পৌঁছাত না** — আর সেজন্যই ফাঁকটা এত দিন টিকে ছিল।
     */
    use IsAudited;

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
     * যে ঘরগুলো অডিটে যায় না — আর কেন।
     *
     * ── ⛔ প্রথম দুইটা নিরাপত্তার, বাকিগুলো পাঠযোগ্যতার ───────────────
     * `password` আর `remember_token` [[AuditEngine]]-র `NEVER_LOGGED`
     * তালিকাতেও আছে, অর্থাৎ এখানে না লিখলেও ওগুলো যেত না। ⭐ তবু
     * **দুই জায়গাতেই** লেখা, ইচ্ছাকৃতভাবে: ঐ বৈশ্বিক তালিকাটা একদিন
     * কেউ ছেঁটে দিতে পারেন, আর তখন এই মডেলটাই শেষ দেয়াল। ⚠️ একটা
     * পাসওয়ার্ডের হ্যাশ `audit_field_changes`-এ বসা মানে খাতাটাই
     * একটা পাসওয়ার্ডের তালিকা — আর অডিট পড়ার অনুমতি বদলানোর
     * অনুমতির চেয়ে **অনেক বেশি লোকের** থাকে।
     *
     * ── বাকিগুলো কেন: প্রতিটাই যন্ত্রের বা রুচির ঘর ─────────────────
     * ⓘ প্রতিটার পাশে **কে লেখে** সেটা মেপে দেখা:
     *
     *     last_login_at       CredentialCheck — প্রতিটা সফল লগইনে
     *     current_company_id  switchCompany() — কোম্পানি বদলালেই
     *     current_branch_id   একই জায়গা, একই সাথে
     *     locale              WorkspaceController::switchLocale()
     *     theme               WorkspaceController::switchTheme()
     *     ui, accent          চেহারা পাতার সেভ
     *     avatar_path         AvatarService
     *
     * ⚠️ থিমের মন্তব্যেই লেখা আছে *"যে জিনিস দিনে দুবার বদলায়"* — আর
     * সেটা প্রতিটা ব্যবহারকারীর জন্য। ⛔ এগুলো অডিটে তুললে একজন
     * মানুষের খাতা **"theme: light → dark"** সারিতে ভরে যেত, আর তার
     * নিচে চাপা পড়ত ঠিক সেই একটামাত্র সারি যেটার জন্য খাতাটা বানানো:
     * "is_active: 1 → 0"।
     *
     * ⓘ লগইনের ইতিহাস হারায় না — সেটা `login_attempts`-এ নিজেই একটা
     * পূর্ণ খাতা (`LoginAttempt`, শুধু-যোগের)।
     *
     * ── ⭐ যা ইচ্ছাকৃতভাবে এই তালিকায় **নেই** ────────────────────────
     *     is_active     কাউকে বাইরে করে দেওয়া — সবচেয়ে দরকারি সারি
     *     email         লগইনের পরিচয়টাই বদলে যায়
     *     name          প্রতিটা বিলে ও প্রতিটা অডিট সারিতে এই নামটা বসে
     *     mfa_*         এনক্রিপ্টেড, তাই মান নয় — কিন্তু **ঘটনাটা** যায়
     *                   (`AuditEngine::HIDDEN`), আর "কে কার দ্বিতীয়
     *                   তালাটা খুলে দিল" নিরীক্ষার আসল প্রশ্ন
     *     deleted_at    ⚠️ ছুঁয়ো না — [[IsAudited]] ঠিক এই ঘরটা দেখেই
     *                   "ফেরানো" আর "সম্পাদনা" আলাদা করে
     *
     * @return list<string>
     */
    public function auditIgnores(): array
    {
        return [
            'password', 'remember_token',
            'last_login_at', 'current_company_id', 'current_branch_id',
            'locale', 'theme', 'ui', 'accent', 'avatar_path',
        ];
    }

    /**
     * ⛔ ব্যবহারকারীর সারিটা কোনো শাখার নয় — ১২ সেপ্টেম্বর ২০২৬।
     *
     * ── কেন এটা লাগল (সুইট চালিয়ে ধরা পড়েছে) ────────────────────────
     * ⓘ [[AuditEngine]]-র স্বাভাবিক নিয়ম: সারির নিজের `branch_id` না
     * থাকলে **চলতি শাখা** বসাও। ⚠️ [[User]]-এর সেই ঘরটা নেই, তাই
     * চলতি শাখাটাই বসত — আর `DemoSeeder` চলার সময় সেটা এমন একটা
     * শাখার দিকে ইশারা করত **যার তখনো অস্তিত্ব নেই**:
     *
     *     SQLSTATE[23000] … audit_trails_branch_id_foreign
     *     insert into `audit_trails` … (1, 1, …, created, App\Models\User, 5)
     *
     * ⓘ সিডারে কোম্পানি ও ব্যবহারকারী তৈরি হয় **শাখার আগে**, আর
     * `CompanyContext` স্ট্যাটিক — তাই আগের টেস্টের প্রসঙ্গ পরেরটায়
     * রয়ে যেত। ⛔ ফলে ভুলটা **একা চালালে ধরা পড়ত না**, কেবল পুরো
     * সুইটে; আর সেই অস্থিরতাটাই আসল বিপদ ছিল। ⚠️ হুবহু এই জিনিসটাই
     * [[Company]]-তে অডিট বসানোর দিনে ঘটেছিল, আর সমাধানও এক।
     *
     * ── ⭐ কিন্তু এটা কেবল সিডারের সারাই নয় ─────────────────────────
     * ⓘ ব্যবহারকারী-ব্যবস্থাপনা **কোম্পানির স্তরের কাজ**, শাখার নয়।
     * একজন মানুষ কোম্পানিতে ঢোকেন (`company_user` পিভট); শাখা কেবল
     * তাঁর কার্সার কোথায় বসবে। ⚠️ চলতি শাখা বসালে খাতাটা মিথ্যা বলত:
     * নেত্রকোনায় বসে কেউ একজন কর্মী বানালে সারিটা **"নেত্রকোনার
     * ঘটনা"** হয়ে যেত, অথচ কর্মীটি চারটা শাখারই।
     */
    public function auditBranchId(): ?int
    {
        return null;
    }

    /**
     * ⛔ যে কোম্পানির খাতায় সারিটা বসবে — আর সে সত্যিই আছে কি না।
     *
     * ── কেন [[User]]-এর জন্য এই প্রশ্নটা আলাদা ───────────────────────
     * ⓘ প্রায় প্রতিটা অডিটেড সারির নিজের `company_id` আছে, তাই প্রশ্নই
     * ওঠে না। ব্যবহারকারীর নেই — সে `company_user` পিভটে ঝোলে — তাই
     * [[AuditEngine]] **চলতি প্রসঙ্গের** কোম্পানিটা বসায়।
     *
     * ⚠️ আর ঠিক এখানেই [[User]] বাকি সবার থেকে আলাদা: **সে-ই একমাত্র
     * মডেল যে কোম্পানির আগেও জন্মাতে পারে**। `FirstRun::open()`
     * ব্যবহারকারী বানায় আগে (লাইন ~১৪৫), কোম্পানি তার পরে (~২০৩) —
     * কারণ কোম্পানিটা কার, সেটা বলতে একজন মালিক লাগে।
     *
     * ── কী ভাঙে, মেপে দেখা (১২ সেপ্টেম্বর ২০২৬) ─────────────────────
     * প্রসঙ্গটা স্ট্যাটিক, আর সে **রোলব্যাক হওয়া লেনদেনের পরেও বেঁচে
     * থাকে**। ফলে সুইটে আগের পরীক্ষার কোম্পানি-আইডি পরেরটায় রয়ে
     * যেত, অথচ সারিটা আর টেবিলে নেই:
     *
     *     SQLSTATE[23000] … audit_trails_company_id_foreign
     *     insert into `audit_trails` … values (1, …, created, App\Models\User, 5)
     *
     * ⛔ অর্থাৎ **খাতা লেখার চেষ্টাটাই ব্যবহারকারী তৈরি ফেলে দিত**।
     * ⓘ ছয়টা স্থাপত্য-পরীক্ষা এতে লাল হয়েছিল, আর ভুলটা একা চালালে
     * ধরা পড়ত না — কেবল পুরো সুইটে। সেই অস্থিরতাটাই আসল বিপদ।
     *
     * ── ⭐ কেন "না লেখা", "ভুল লেখা" নয় ─────────────────────────────
     * ⓘ `AuditEngine::record()`-এ নিয়মটা আগে থেকেই লেখা: *"কোম্পানি
     * ছাড়া অডিট নয় — বসালে কোন কোম্পানির পর্দায় দেখাবে তার উত্তর
     * থাকত না।"* ⚠️ যে কোম্পানি নেই, তার খাতা তো আরও নেই। এখানে
     * `null` ফেরানো মানে ঐ নিয়মটাই মানা, ব্যতিক্রম নয়।
     *
     * ⚠️ এর একটা দাম আছে, আর সেটা খোলাখুলি লেখা থাকা দরকার: **প্রথম
     * সেটআপের প্রথম ব্যবহারকারীর জন্য কোনো সারি বসে না**, কারণ ঐ
     * মুহূর্তে কোনো কোম্পানিই নেই। ⓘ তাঁর জন্ম `FirstRun` নিজেই
     * ধরে রাখে, আর তিনি একজনই — বাকি প্রতিটা অ্যাকাউন্ট খাতায় ওঠে।
     *
     * ⓘ `withTrashed()` — নরম-মোছা কোম্পানির সারিটা টেবিলে **আছেই**,
     * তাই বিদেশি চাবি সন্তুষ্ট। ওটা বাদ দিলে বন্ধ হয়ে যাওয়া
     * প্রতিষ্ঠানের ব্যবহারকারী-বদলগুলো নীরবে খাতার বাইরে চলে যেত।
     */
    public function auditCompanyId(): ?int
    {
        /*
         * ⛔ প্রসঙ্গ না থাকলে মানুষটার নিজের কোম্পানি — ১৩ সেপ্টেম্বর ২০২৬।
         *
         * ── কেন এই ধাপটা লাগল ───────────────────────────────────────
         * পাসওয়ার্ড রিসেট **অতিথি অবস্থায়** ঘটে: যিনি রিসেট করছেন তিনি
         * তখনো লগইন করেননি, তাই কোনো কোম্পানি বাছাই হয়নি আর
         * `CompanyContext::id()` খালি।
         *
         * ⚠️ কেবল প্রসঙ্গ দেখলে `record()` null পেয়ে **চুপচাপ ফিরে
         * যেত** — অর্থাৎ "কে নিজের পাসওয়ার্ড রিসেট করল" সারিটা কখনো
         * বসত না, আর কেউ টের পেত না, কারণ কিছুই ভাঙত না।
         *
         * ⓘ কিন্তু উত্তরটা হারিয়ে যায়নি — **সারিটা নিজেই জানে**:
         * ব্যবহারকারী কোন কোম্পানিতে বসেন সেটা তাঁর নিজের ঘরে লেখা।
         * ⭐ তাই ক্রমটা এই: চলতি প্রসঙ্গ → তাঁর নিজের চলতি কোম্পানি →
         * তিনি যে কোম্পানিগুলোতে ঢুকতে পারেন তার প্রথমটা।
         *
         * ⚠️ প্রসঙ্গটা **আগে**, আর সেটা ইচ্ছাকৃত: প্রশাসক যখন কাউকে
         * সম্পাদনা করেন তখন সারিটা **প্রশাসক যে খাতায় দাঁড়িয়ে** সেই
         * খাতারই — নাহলে এক কোম্পানিতে বসে অন্য কোম্পানির কাউকে
         * বদলালে সারিটা ভুল পর্দায় গিয়ে বসত।
         */
        $companyId = CompanyContext::id()
            ?? $this->current_company_id
            ?? $this->companies()->orderBy('companies.id')->value('companies.id');

        if ($companyId === null) {
            return null;
        }

        return Company::withTrashed()->whereKey($companyId)->exists() ? (int) $companyId : null;
    }

    /**
     * ⛔ পাসওয়ার্ড রিসেটের চিঠি — ব্যবহারকারীর নিজের ভাষায়।
     *
     * ── কেন Laravel-এর নিজেরটা নয় ───────────────────────────────────
     * ফ্রেমওয়ার্কের `ResetPassword` বিজ্ঞপ্তিটা **কেবল ইংরেজি**, আর
     * তার ছাঁচে ফ্রেমওয়ার্কের নিজের বাক্যও মেশানো থাকে ("If you're
     * having trouble clicking…", "Regards")। ⚠️ ফলে একজন বাংলা
     * ব্যবহারকারীর ইনবক্সে একটা পুরো ইংরেজি চিঠি যেত — আর এই
     * ব্যবস্থার নিয়ম ৯ ঠিক তার উল্টো কথা বলে।
     *
     * ⓘ ভাষাটা নেওয়া হয় **তাঁর নিজের রেকর্ড থেকে**, অনুরোধের চলতি
     * ভাষা থেকে নয়: চিঠিটা লেখা হয় একটা কিউ/অনুরোধের প্রসঙ্গে, কিন্তু
     * পড়া হয় ঘণ্টা পরে তাঁর ফোনে। ⭐ যে ভাষায় তিনি ব্যবস্থাটা চালান,
     * চিঠিটাও সেই ভাষায়।
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetLink($token));
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
