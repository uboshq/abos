<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * এক স্তরের একটা সিদ্ধান্ত।
 *
 * প্রতিটা স্তর আলাদা রো, কারণ শুধু চূড়ান্ত অবস্থা রাখলে "তিন নম্বর স্তরে
 * চার দিন আটকে ছিল কেন" প্রশ্নের উত্তর কখনো পাওয়া যায় না।
 */
class ApprovalDecision extends Model
{
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    /*
     * ⛔ মানগুলো এতদিন কাঁচা লেখা ছিল, নাম ছিল না।
     *
     * ── ⚠️ কেন সেটা বিপদ ────────────────────────────────────────────
     * `'approved'` লেখা ছিল ইঞ্জিনের দুই জায়গায়, আর পড়া হত তৃতীয়
     * জায়গায়। ⓘ একটা বানান ভুল কোথাও ত্রুটি দেখাত না — সিদ্ধান্তটা
     * শুধু **কোনো হিসাবেই পড়ত না**, আর স্তরটা চিরকাল অপেক্ষায় থাকত।
     *
     * ⭐ তৃতীয় মানটা (`forwarded`) যোগ করার দিনই নাম বসানো হলো, কারণ
     * এখন তিনটা মান তিন জায়গায় মিলতে হয়।
     */
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /**
     * ⓘ "হ্যাঁ"-ও নয়, "না"-ও নয় — কাগজটা অন্যের হাতে গেল।
     *
     * ⚠️ এটা **সিদ্ধান্ত নয়**, তাই যিনি ফরওয়ার্ড করেছেন তিনি পরে
     * ঐ স্তরে সই দিতে পারেন ([[ApprovalEngine::canDecide()]])।
     */
    public const FORWARDED = 'forwarded';

    /**
     * ⭐ বাতিলের কারণ — বাছাই করা, মুক্ত লেখা নয়।
     *
     * ⓘ মুক্ত লেখাও থাকে (`remarks`), কিন্তু **গোনার** জন্য কোড
     * লাগে। ⚠️ কেবল মুক্ত লেখা রাখলে *"দাম ভুল"* দশ বানানে
     * লেখা হত, আর কোনো রিপোর্ট ওগুলোকে এক করতে পারত না।
     *
     * @var list<string>
     */
    public const REASONS = [
        'price',        // দাম ভুল
        'document',     // কাগজপত্র অসম্পূর্ণ
        'credit',       // বাকির সীমা
        'budget',       // বাজেট নেই
        'policy',       // নিয়মের বিরুদ্ধে
        'other',        // অন্য কারণ
    ];

    protected $fillable = [
        'approval_id', 'level', 'user_id', 'on_behalf_of', 'forwarded_to', 'decision', 'reason_code',
        'remarks', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['level' => 'integer', 'decided_at' => 'datetime'];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ⓘ ফরওয়ার্ড হলে কার হাতে গেল — নাহলে `null`। */
    public function forwardedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_to');
    }

    /**
     * ⛔ একটা সিদ্ধান্ত বদলানো বা মোছা যায় না।
     *
     * ── ⚠️ কেন এটা লাগল ───────────────────────────────
     * ⓘ আজ কোনো সম্পাদনার পথ নেই — কিন্তু সেটা **দুর্ঘটনাক্রমে**,
     * নকশায় নয়। ⛔ আগামীকাল কেউ একটা সম্পাদনার পর্দা লিখলে
     * কিছুই আটকাত না, আর নিরীক্ষার গোটা ভিত্তিটাই নড়ত।
     *
     * ⓘ ছাড়: কেবল সারিটা তৈরি হওয়ার মুহূর্তেই লেখা হয়।
     */
    protected static function booted(): void
    {
        static::updating(function (self $decision) {
            throw new \RuntimeException(
                '⭔ অনুমোদনের সিদ্ধান্ত বদলানো যায় না — সিদ্ধান্ত #'.$decision->id
                .'। ভুল হলে নতুন একটা অনুরোধ বসানোই একমাত্র পথ।'
            );
        });

        static::deleting(function (self $decision) {
            throw new \RuntimeException(
                '⭔ অনুমোদনের সিদ্ধান্ত মোছা যায় না — সিদ্ধান্ত #'.$decision->id.'।'
            );
        });
    }
}
