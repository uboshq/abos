<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা প্রবাহ কখন ধরবে — টাকার অঙ্ক ছাড়াও।
 *
 * ── ⚠️ সব শর্তকেই মিলতে হয় (AND) ────────────────────────────────────
 * ⓘ OR রাখা হয়নি, আর সেটা ইচ্ছাকৃত: *"ছাড় ১৫% পার **অথবা** অঙ্ক পাঁচ
 * লাখ পার"* দরকার হলে **দুইটা আলাদা প্রবাহ** বানানো যায়, আর তখন
 * প্রতিটার নিজের ধাপ ও নিজের সময়সীমা থাকে।
 *
 * ⛔ এক প্রবাহে AND আর OR মিশতে দিলে *"কোনটা আগে"* প্রশ্নটা আসত, আর
 * ঐ প্রশ্নের ভুল উত্তরে অনুমোদন নীরবে এড়ানো যেত।
 */
class ApprovalCondition extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    /*
     * ⭐ অডিট — ২৫ সেপ্টেম্বর ২০২৬, পাহারাটাই ধরিয়ে দিয়েছে।
     *
     * ── ⛔ কেন এটা বাদ পড়া চলে না ──────────────────────────────────
     * এই সারিগুলোই ঠিক করে **কোন কাগজে অনুমোদন লাগবে**। ⚠️ একটা শর্ত
     * নীরবে বদলে দিলে (`>= 500000` কে `>= 5000000`) অনুমোদনের দরজাটা
     * খোলা থেকে যায়, অথচ পর্দায় প্রবাহটা দিব্যি চালু দেখায়।
     *
     * ⓘ পাশের প্রতিটা ভাই-মডেল আগে থেকেই অডিট করা — [[Approval]],
     * [[ApprovalFlow]], [[ApprovalLimit]], [[ApprovalDelegation]],
     * [[ApprovalDecision]]। ⛔ কেবল এটাই বাদ ছিল, আর সেটা সিদ্ধান্ত
     * নয়, ভুলে যাওয়া — তাই ছাড়ের তালিকায় না লিখে অডিটটাই বসানো হলো।
     */
    use IsAudited;

    /** যে অপারেটরগুলো চেনা — এর বাইরে কিছু বসানো যায় না। */
    public const OPERATORS = ['>', '>=', '<', '<=', '=', '!=', 'in'];

    protected $fillable = ['company_id', 'approval_flow_id', 'field', 'operator', 'value'];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    /**
     * ⭐ কাগজের ঘরগুলোর সাথে মিলিয়ে দেখা।
     *
     * ── ⛔ ঘরটা না থাকলে শর্ত **মেলে না** ─────────────────────────────
     * ⚠️ এটাই এখানকার সবচেয়ে দামি সিদ্ধান্ত। ⓘ ঘরটা না পাঠালে যদি
     * শর্তটা "মিলল" ধরা হত, তবে একটা মডিউল ঘর পাঠাতে ভুলে গেলে **সব
     * কাগজ** অনুমোদনে আটকে যেত। ⛔ আর উল্টোটা ধরলে — "মিলল না" —
     * কাগজ চুপচাপ পার হয়ে যেত।
     *
     * ⭐ দুইটার মধ্যে "মেলে না" নিরাপদ **নয়**, কিন্তু কম ক্ষতিকর নয়ও।
     * তাই তৃতীয় পথ: [[ApprovalFlow::unknownFields()]] ঘরটা অনুপস্থিত
     * পেলে সেটা গোনে, আর `flow/coverage` পর্দা সেই প্রবাহগুলো দেখায়।
     *
     * @param  array<string, mixed>  $fields
     */
    public function matches(array $fields): bool
    {
        if (! array_key_exists($this->field, $fields)) {
            return false;
        }

        $left = $fields[$this->field];

        if ($this->operator === 'in') {
            $list = array_map('trim', explode(',', (string) $this->value));

            return in_array((string) $left, $list, true);
        }

        /*
         * ⓘ সংখ্যা হলে `bccomp` — টাকার তুলনা কখনো float-এ নয়
         * ([[MoneyIsNeverAFloatTest]])। ⚠️ `0.1 + 0.2 > 0.3` float-এ সত্য,
         * আর ঐ ভুলটা অনুমোদনে বসলে একটা কাগজ এড়িয়ে যেত।
         */
        if (is_numeric($left) && is_numeric($this->value)) {
            $cmp = bccomp((string) $left, (string) $this->value, 4);

            return match ($this->operator) {
                '>' => $cmp > 0,
                '>=' => $cmp >= 0,
                '<' => $cmp < 0,
                '<=' => $cmp <= 0,
                '=' => $cmp === 0,
                '!=' => $cmp !== 0,
                default => false,
            };
        }

        /*
         * ⓘ বুলিয়ান ঘর (`is_backdated`) স্ট্রিং হয়ে আসে, তাই তুলনার
         * আগে দুই পাশকে একই রূপে আনা হয়।
         */
        $l = $this->normalise($left);
        $r = $this->normalise($this->value);

        return match ($this->operator) {
            '=' => $l === $r,
            '!=' => $l !== $r,
            default => false,
        };
    }

    private function normalise(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = strtolower(trim((string) $value));

        return match ($text) {
            'true', 'yes' => '1',
            'false', 'no' => '0',
            default => $text,
        };
    }
}
