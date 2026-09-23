<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা নোটিশ কার দিকে তাক করা — একটা সারি, একটা চাবি।
 *
 * ── ⓘ কেন সারিটায় কোনো `type` কলাম নেই ──────────────────────────────
 * চাবিটাই ধরন বহন করে: `branch:7`-এর বাঁ দিকটা ধরন, ডান দিকটা লক্ষ্য।
 * ⚠️ আলাদা কলাম রাখলে দুইটা ঘর একই কথা বলত, আর একদিন একটা বদলে
 * অন্যটা থেকে যেত — তখন কোনটা সত্যি তা বলার উপায় থাকত না।
 *
 * ── ⛔ আর কেন লক্ষ্যটা কোনো মডেলে বাঁধা নয় ──────────────────────────
 * বিভাগ আর পদ মডিউলের সম্পত্তি, আর [[Notice]] কোরে থাকে — কোর কোনো
 * মডিউলের নাম জানতে পারে না (§১৯.৭)। ⓘ তাই সম্পর্কটা লেখায়, বিদেশি
 * কী-তে নয়।
 *
 * ⚠️ এর দাম আছে, আর সেটা জেনে নেওয়া: একটা শাখা মুছে ফেললে তার দিকে
 * তাক করা সারিটা থেকে যায়। ⓘ ফলটা নিরীহ — ঐ চাবির সাথে আর কারও চাবি
 * মেলে না, তাই নোটিশটা কেবল কারও চোখে পড়ে না। ⛔ উল্টোটা, অর্থাৎ
 * নোটিশটা হঠাৎ সবার চোখে পড়া, ঘটে না।
 */
final class NoticeTarget extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $table = 'notice_audiences';

    protected $fillable = ['company_id', 'notice_id', 'match_key'];

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    /** চাবির বাঁ দিক — `branch:7` থেকে `branch`। */
    public function kind(): string
    {
        return str_contains((string) $this->match_key, ':')
            ? explode(':', (string) $this->match_key, 2)[0]
            : '';
    }

    /** আর ডান দিক — `branch:7` থেকে `7`। */
    public function target(): string
    {
        return str_contains((string) $this->match_key, ':')
            ? explode(':', (string) $this->match_key, 2)[1]
            : '';
    }
}
