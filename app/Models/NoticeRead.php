<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * কে কবে নোটিশটা পড়েছেন।
 *
 * ── ⓘ কেন আলাদা সারি, নোটিশের গায়ে গুনতি নয় ──────────────────────────
 * গুনতি রাখলে *"কয়জন পড়েছেন"* বলা যেত, কিন্তু **"কে পড়েননি"** বলা যেত
 * না। ⚠️ আর নোটিশ দেওয়ার আসল কারণটাই ঐ দ্বিতীয় প্রশ্ন।
 */
final class NoticeRead extends Model
{
    /*
     * ⭐ ঘরের দুইটা নিয়ম — ২৩ সেপ্টেম্বর ২০২৬।
     *
     * ⚠️ প্রথম খসড়ায় দুইটাই বাদ পড়েছিল, কারণ সারিটা "কেবল একটা
     * জোড়" মনে হয়েছিল। ⛔ কিন্তু প্রশ্নটা ভাবুন: *"এই নোটিশটা
     * কে কাদের জন্য করেছিলেন, আর কখন বদলেছিলেন?"* — অডিট ছাড়া
     * ঐ প্রশ্নের উত্তর নেই।
     *
     * ⓘ ধরা পড়েছে ঘরের পুরো পাহারা চালিয়ে, নিজের লেখা পরীক্ষায়
     * নয় — নিজের পরীক্ষা কেবল সেটুকুই মাপে যেটুকু আমি ভেবেছি।
     */
    use HasPublicId;
    use IsAudited;

    public $timestamps = false;

    protected $fillable = ['notice_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
