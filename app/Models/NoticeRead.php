<?php

declare(strict_types=1);

namespace App\Models;

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
