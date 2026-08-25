<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা রূপের প্রকাশিত সংস্করণ — থিম ইঞ্জিনের ধাপ ৩ (অংশ ৮)।
 *
 * ── কেন খসড়া আর প্রকাশিতটা আলাদা সারি ────────────────────────────────
 * `look_skins`-এর সারিটা সম্পাদকের **কাজের কপি**। এই সারিটা যা মানুষ
 * সত্যিই দেখেন।
 *
 * এক সারিতে দুইটা রাখলে সম্পাদনা শুরু করা মাত্র গোটা ডিপো অর্ধেক লেখা
 * একটা রূপ নিয়ে কাজ করত — আর ভুল হলে ফেরার কিছু থাকত না, কারণ আগেরটা
 * ততক্ষণে মুছে গেছে।
 *
 * ── এখানে কোম্পানির স্কোপ নেই, আর সেটা ইচ্ছাকৃত ───────────────────────
 * সংস্করণ নিজে থেকে কারো নয় — সে তার স্কিনের। স্কিনটাই কোম্পানির, আর
 * সেখানেই স্কোপটা বসে। এখানেও বসালে একই প্রশ্ন দুই জায়গায় জিজ্ঞেস
 * করা হত, আর একদিন দুইটা আলাদা উত্তর দিত।
 */
class LookSkinVersion extends Model
{
    use HasPublicId;

    protected $table = 'look_skin_versions';

    protected $fillable = [
        'look_skin_id', 'version', 'parent', 'tokens',
        'note', 'reverted_from', 'published_at', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function skin(): BelongsTo
    {
        return $this->belongsTo(LookSkin::class, 'look_skin_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * কেবল এই সংস্করণের নিজের বদলগুলো, এক থিমে।
     *
     * গাঢ়ে হালকাটাও লাগে — গাঢ় ব্লকে কেবল যা বদলায় তাই লেখা থাকে,
     * ঠিক কোড-রূপগুলোর মতোই।
     *
     * @return array<string, string>
     */
    public function ownTokens(string $theme = 'light'): array
    {
        $said = $this->tokens ?? [];

        $light = $said['light'] ?? [];

        return $theme === 'dark'
            ? [...$light, ...($said['dark'] ?? [])]
            : $light;
    }
}
