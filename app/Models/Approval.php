<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** একটা অনুমোদনের অনুরোধ। polymorphic — যেকোনো ডকুমেন্টে বসে। */
class Approval extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'approvable_type', 'approvable_id', 'module', 'action',
        'amount', 'status', 'current_level', 'payload',
        'requested_reason', 'requested_by', 'requested_at', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'current_level' => 'integer',
            'payload' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * ⭐ সইটা কি **এই** অঙ্কের উপরই দেওয়া হয়েছিল?
     *
     * ── ⛔ কেন এটা লাগল, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * [[ApprovalEngine::approve()]] কাগজটায় হাত দেয় না — কেবল অনুরোধটাকে
     * `approved` করে। ⚠️ মানুষটাকে ফিরে এসে আবার "নিশ্চিত" চাপতে হয়, আর
     * **ঐ দুই চাপের মাঝখানে কাগজটা খসড়াই থাকে, সম্পাদনা করা যায়**।
     *
     * ⛔ ফলে এটা সম্ভব ছিল: ৫০ হাজারের অর্ডারে ব্যবস্থাপক সই দিলেন, তারপর
     * অর্ডারটা ৫ লাখ করে পাশ করিয়ে নেওয়া হলো। ⓘ খাতায় ৫০ হাজারই লেখা
     * থাকত — অর্থাৎ প্রমাণটাও ভুল থাকত।
     *
     * ── ⓘ কেন অঙ্ক, `updated_at` নয় ────────────────────────────────
     * `updated_at` ধরলে সইয়ের পর **যেকোনো** ছোঁয়া — একটা বানান সংশোধনও —
     * সই বাতিল করত, আর মানুষ শিখত সই নেওয়াটা অর্থহীন। ⚠️ অঙ্কটাই সেই
     * জিনিস যার উপর সইটা দেওয়া হয়েছিল ([[ApprovalFlow::appliesTo()]]
     * সীমাটা এর সাথেই মেলায়), তাই প্রশ্নটাও ঐটাই।
     *
     * ⛔ এটা অঙ্কের বাইরের বদল ধরে না — গ্রাহক পাল্টে দিলে সই টিকে থাকে।
     * ⓘ কথাটা লিখে রাখা হলো, নাহলে পরে কেউ ধরে নিত এটা সবটা ঢাকে।
     *
     * ── ⚠️ অঙ্ক নামলেও সই বাতিল ─────────────────────────────────────
     * ⓘ নামা অঙ্ক নিরীহ মনে হয়, কিন্তু *"যা সই হয়েছিল তার চেয়ে কম হলে
     * চলবে"* লিখলেই প্রশ্ন আসে **কত কম**, আর ঐ প্রশ্নের সৎ উত্তর নেই।
     * ⭐ বাস্তবে কারো পথ আটকায় না: নিচে নামলে অঙ্কটা সীমার নিচে পড়ে আর
     * `request()` এমনিতেই `null` ফেরায়।
     */
    public function covers(?string $amount): bool
    {
        /*
         * ⛔ খালি স্ট্রিংটা "শূন্য" নয়, "জানা নেই"।
         *
         * ⚠️ ডাকার জায়গাগুলো `(string) $voucher->amount` লেখে, আর অঙ্কটা
         * null হলে সেটা `''` হয়ে আসে। ⓘ PHP ৮-এ `bccomp('', …)` সরাসরি
         * `ValueError` ছোঁড়ে — অর্থাৎ পাহারাটা বসানোর ফল হত একটা ভেঙে
         * পড়া পোস্টিং, আটকানো নয়।
         */
        $signed = $this->amount === null ? null : (string) $this->amount;

        $signed = ($signed === null || $signed === '') ? null : $signed;
        $amount = ($amount === null || $amount === '') ? null : $amount;

        // ⓘ দুই দিকেই "জানা নেই" — তুলনার কিছু নেই, সইটাই যা ছিল তাই।
        if ($signed === null || $amount === null) {
            return $signed === null && $amount === null;
        }

        return bccomp($signed, $amount, 4) === 0;
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    // ── Drillable — নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে যায় ──────────────

    /**
     * ── কেন অনুরোধটাই গন্তব্য, নিচের কাগজটা নয় ──────────────────────
     * প্রলুব্ধ করে ভাবতে যে "১২টা অপেক্ষমাণ" থেকে ক্লিক করলে ক্রয়াদেশটাই
     * খোলা উচিত। কিন্তু পাঠকের প্রশ্নটা তখন **অনুমোদন নিয়ে** — কে আটকে
     * আছে, কোন স্তরে, কে কী মন্তব্য করেছেন। ওই উত্তরগুলো আছে অনুরোধের
     * পর্দায়, আর সেখান থেকে কাগজটাতেও যাওয়া যায়। উল্টোটা নয়।
     */
    public static function drillSourceType(): string
    {
        return 'approval';
    }

    /**
     * অনুরোধের নিজের কোনো নম্বর নেই — কাগজেরটা আছে।
     *
     * তাই মডিউল ও কাজের নাম, আর সাথে আইডি: রিপোর্টে দুইটা সারি একই
     * রকম দেখালে কোনটা কোনটা বলার আর কোনো উপায় থাকত না।
     */
    public function drillDocumentNo(): string
    {
        return $this->module.'.'.$this->action.'#'.$this->getKey();
    }

    public function drillLabel(): string
    {
        return trim((string) $this->requested_reason) !== ''
            ? (string) $this->requested_reason
            : $this->drillDocumentNo();
    }

    public function drillRoute(): array
    {
        return ['approval.inbox.show', ['approval' => $this->id]];
    }
}
