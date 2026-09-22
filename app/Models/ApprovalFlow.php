<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\CompanyContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** কোন কাজে কয় স্তরের অনুমোদন লাগবে — Control Panel থেকে সাজানো। */
class ApprovalFlow extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'company_id', 'code', 'module', 'action', 'document_type',
        'threshold_amount', 'remarks', 'is_active',
    ];

    protected function casts(): array
    {
        return ['threshold_amount' => 'decimal:4', 'is_active' => 'boolean'];
    }

    /**
     * সংকেত বসে **সারি তৈরির মুহূর্তে**, সেবা স্তরে নয়।
     *
     * ── ⛔ কেন, আর এটা মেপে ধরা পড়েছে ───────────────────────────────
     * প্রথমে সংকেতটা [[ApprovalFlowService::create()]]-এ বসানো ছিল।
     * ⓘ কিন্তু [[DemoSeeder]] সেবা স্তর দিয়ে যায় না, সে সরাসরি
     * `ApprovalFlow::create()` ডাকে — তাই নতুন ইনস্টলের ছকটা **সংকেত
     * ছাড়াই** বসত। ⚠️ পর্দায় একটা খালি ঘর, আর কেউ বুঝত না কেন।
     *
     * ⭐ নিয়মটা সেবা স্তরে রাখলে সেটা *"যে পথে মনে থাকে সে পথে"* —
     * আর মডেলে রাখলে **যে পথেই সারি বসুক**। ⓘ সংকেত থাকা এই সারির
     * নিজের শর্ত, কোনো একটা পর্দার শর্ত নয়।
     *
     * ── ⚠️ কেন `count()` নয়, `MAX()` ────────────────────────────────
     * একটা ছক মুছে ফেললে গুনতি কমে যেত, আর পরের ছকটা **মুছে যাওয়া
     * সংকেতটাই** পেত। ⛔ তখন ছয় মাস আগের কাগজে লেখা `AN007` আর
     * আজকের `AN007` দুইটা আলাদা নিয়ম — ঠিক যে জিনিসটা এড়াতে সংকেতটা
     * বসানো।
     */
    protected static function booted(): void
    {
        static::creating(function (self $flow): void {
            if (trim((string) $flow->code) !== '') {
                return;
            }

            /*
             * ⓘ কোম্পানিটা সারির নিজের ঘর থেকে, প্রসঙ্গ থেকে নয় —
             * [[BelongsToCompany]] ততক্ষণে ওটা বসিয়ে দিয়েছে, আর
             * সিডার কোম্পানি বদলে বদলে চালায়।
             */
            $companyId = $flow->company_id ?? CompanyContext::id();

            $last = static::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->selectRaw('MAX(CAST(SUBSTRING(code, 3) AS UNSIGNED)) as n')
                ->value('n');

            $flow->code = 'AN'.str_pad((string) ((int) $last + 1), 3, '0', STR_PAD_LEFT);
        });
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalFlowStep::class)->orderBy('level');
    }

    /**
     * এই পরিমাণে অনুমোদন লাগবে কি না।
     *
     * সীমা না থাকলে সবসময় লাগে। সীমা থাকলে তার নিচে লাগে না — কারণ
     * "৫০ টাকার ডিসকাউন্টে মালিকের অনুমোদন" বাস্তবে কেউ মানে না, আর
     * একবার না-মানা শুরু হলে পুরো ব্যবস্থাটাই অকেজো হয়ে যায়।
     */
    public function appliesTo(?string $amount): bool
    {
        if ($this->threshold_amount === null) {
            return true;
        }

        if ($amount === null) {
            return true;
        }

        return bccomp((string) $amount, (string) $this->threshold_amount, 4) >= 0;
    }
}
