<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * একটা সেটিং। company_id null মানে প্রোডাক্টের ডিফল্ট, রো থাকলে কোম্পানির পছন্দ।
 *
 * BelongsToCompany ব্যবহার করা হয়নি ইচ্ছাকৃতভাবে: ডিফল্ট রো-গুলোর company_id
 * null, আর গ্লোবাল স্কোপ সেগুলো ঢেকে ফেলত — তখন কোনো কোম্পানি কিছু ওভাররাইড
 * না করলে সেটিংসের কোনো মানই পাওয়া যেত না।
 *
 * ── অডিট কেন, আর কেন এত দেরিতে ───────────────────────────────────────
 * ২ সেপ্টেম্বর ২০২৬ পর্যন্ত এই মডেলে অডিট ছিল না, আর `SettingsService`ও
 * নিজে কিছু লিখত না। অর্থাৎ **একটা সেটিং কে বদলাল, কবে, আর কী থেকে
 * কীসে — কোথাও কোনো উত্তর ছিল না।**
 *
 * কথাটা তাত্ত্বিক নয়। এই রিপোর নিজের খাতায় (Findings, ৩০ আগস্ট) লেখা
 * আছে *"এক ট্যাব সংরক্ষণ করায় ৩৪টা সেটিং নীরবে বন্ধ"* — আর তখন কে বা
 * কী ওগুলো বন্ধ করল তা বের করতে গোটা একটা সন্ধ্যা লেগেছিল, কারণ
 * জিজ্ঞেস করার মতো কোনো খাতা ছিল না।
 *
 * প্রোডাক্টের ডিফল্ট রো-গুলোয় (`company_id` null) কিছু লেখা হয় না —
 * [[AuditEngine::record()]] কোম্পানি না পেলে নীরবে ফিরে যায়। সেটাই
 * ঠিক: ওই সারিগুলো সিডার ও মাইগ্রেশনের, কারও ব্যবসার সিদ্ধান্ত নয়।
 */
class Setting extends Model
{
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $fillable = ['company_id', 'module', 'key', 'type', 'value', 'group'];

    /** স্ট্রিং হিসেবে সংরক্ষিত মানকে তার আসল ধরনে ফেরানো। */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            'decimal' => (string) $this->value,   // টাকার মান কখনো float-এ নয়
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
