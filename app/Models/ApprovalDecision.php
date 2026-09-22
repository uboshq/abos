<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
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

    protected $fillable = [
        'approval_id', 'level', 'user_id', 'forwarded_to', 'decision', 'remarks', 'decided_at',
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
}
