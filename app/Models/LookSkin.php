<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\LookGate;
use App\Core\Support\LookRegistry;
use App\Core\Support\LookSchema;
use App\Core\Support\Ui;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা রূপ যেটা কোডে নেই — কোম্পানির নিজের, বা আমাদের পাঠানো।
 *
 * ── কেন কেবল পার্থক্যটুকু রাখা হয় ────────────────────────────────────
 * একটা রূপে ষাটের বেশি টোকেন, আর কোম্পানি সাধারণত ছয়টা চায় — তার
 * নিজের নীল, তার লোগোর সবুজ। পুরো সেট কপি করে রাখলে আমরা মূল রূপের
 * একটা রং শোধরালে কোম্পানিরটা পুরনো থেকে যেত, আর কোনটা সে ইচ্ছে করে
 * বদলেছে সেটাও বলা যেত না।
 *
 * তাই সারিতে থাকে কেবল বদলগুলো, আর `parent` বলে কার উপর দাঁড়ানো।
 */
class LookSkin extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /**
     * চেইনের সর্বোচ্চ গভীরতা।
     *
     * ── কেন একটা সীমা লাগে ──────────────────────────────────────────
     * A দাঁড়ায় B-র উপর, B দাঁড়ায় A-র উপর — আর তখন সমাধান করতে গিয়ে
     * অসীম চক্র। ওটা কেবল ভুলে ঘটে না; সম্পাদনার পর্দায় পূর্বপুরুষ
     * বদলাতে গিয়ে দুইজন মানুষ পালা করে বদলালেও ঘটতে পারে।
     *
     * দশটা যথেষ্ট: সিস্টেম → কোম্পানি → শাখা, তিন স্তরের বেশি কেউ
     * চায় না, আর দশে থামলে ভুলটা ব্যতিক্রম হয়ে ধরা পড়ে — পাতা ঝুলে
     * থেকে নয়।
     */
    public const MAX_DEPTH = 10;

    protected $table = 'look_skins';

    protected $fillable = [
        'company_id', 'name', 'parent', 'tokens', 'published_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** প্রকাশিত রূপগুলো — খসড়া কারো পর্দায় যায় না। */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    /**
     * এই রূপের **চূড়ান্ত** টোকেনগুলো — পূর্বপুরুষসহ মিলিয়ে।
     *
     * ── ক্রমটা কেন এই ──────────────────────────────────────────────
     * পূর্বপুরুষ আগে, নিজেরটা পরে — তাই সন্তান যা বলে সেটাই জেতে।
     * উল্টো করলে "Navy + আমার নীল" মানে দাঁড়াত "Navy", আর মানুষ
     * ভাবতেন তাঁর সম্পাদনা সেভ হয়নি।
     *
     * @return array<string, string>
     */
    public function tokens(string $theme = 'light'): array
    {
        $chain = [];
        $skin = $this;
        $depth = 0;

        while ($depth++ < self::MAX_DEPTH) {
            $chain[] = $skin->ownTokens($theme);

            $parent = self::query()->withoutGlobalScopes()
                ->where('public_id', $skin->parent)->first();

            if ($parent === null) {
                /*
                 * পূর্বপুরুষ একটা কোড-রূপ (`navy`) — চেইনের গোড়া।
                 *
                 * অচেনা নাম হলে `Ui::clean()` ডিফল্টে ফেরত আনে, তাই
                 * একটা মুছে ফেলা পূর্বপুরুষ পাতাটা ভাঙে না — কেবল
                 * ডিফল্ট রূপে নামে।
                 */
                $chain[] = LookRegistry::tokens(Ui::clean($skin->parent), $theme);

                break;
            }

            $skin = $parent;
        }

        $tokens = [];

        foreach (array_reverse($chain) as $set) {
            $tokens = [...$tokens, ...$set];
        }

        return $tokens;
    }

    /**
     * কেবল এই সারির নিজের বদলগুলো।
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

    /**
     * প্রকাশ করা যায় কি না — না গেলে কারণগুলো।
     *
     * ── কেন দুইটা যাচাই একসাথে ──────────────────────────────────────
     * স্কিমা বলে নামগুলো চেনা ও মানগুলো সঠিক ধরনের; গেট বলে লেখাটা
     * পড়া যায়। একটা পাশ করে অন্যটা ফেল করা খুবই সম্ভব — একটা নিখুঁত
     * বানানের হালকা ধূসর।
     *
     * দুইটা আলাদা করে ডাকলে একদিন কেউ একটা ডাকতে ভুলত, আর তখন
     * প্রকাশের পথে একটা ফাঁক থেকে যেত।
     *
     * @return list<string>
     */
    public function complaints(): array
    {
        $said = [];

        foreach (['light', 'dark'] as $theme) {
            $said = [
                ...$said,
                ...LookSchema::complaints($this->ownTokens($theme)),
                ...LookGate::complaints($this->tokens($theme)),
            ];
        }

        return array_values(array_unique($said));
    }
}
