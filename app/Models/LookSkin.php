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
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /** এক অনুরোধে একবারই পড়া — `live()` চেইনের প্রতিটা ধাপে ডাকা হয়। */
    private ?LookSkinVersion $liveVersion = null;

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

    /** এই রূপের প্রকাশিত সংস্করণগুলো, নতুনটা আগে। */
    public function versions(): HasMany
    {
        return $this->hasMany(LookSkinVersion::class, 'look_skin_id')
            ->orderByDesc('version');
    }

    /**
     * সর্বশেষ প্রকাশিত সংস্করণ — নাল হলে রূপটা এখনো খসড়া।
     *
     * এক অনুরোধে একবারই পড়া: `liveTokens()` চেইনের প্রতিটা ধাপে এটা
     * ডাকে, আর একই সারির জন্য বারবার কোয়েরি করার কোনো কারণ নেই।
     */
    public function live(): ?LookSkinVersion
    {
        return $this->liveVersion ??= $this->versions()->first();
    }

    /**
     * খসড়ায় এমন কিছু আছে যা এখনো প্রকাশ হয়নি?
     *
     * তালিকার পর্দায় এটাই "অপ্রকাশিত বদল আছে" ব্যাজটা তোলে। ওটা না
     * থাকলে কেউ সম্পাদনা করে চলে যেতেন, আর ভাবতেন কাজটা সবাই দেখছে।
     */
    public function hasUnpublishedChanges(): bool
    {
        $live = $this->live();

        if ($live === null) {
            return true;   // কিছুই প্রকাশ হয়নি — পুরোটাই অপ্রকাশিত
        }

        return $live->parent !== $this->parent
            || $live->tokens !== ($this->tokens ?? []);
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
        return $this->walk($theme, live: false);
    }

    /**
     * মানুষ সত্যিই যা দেখেন — সর্বশেষ **প্রকাশিত** সংস্করণ।
     *
     * ── কেন খসড়ার আলাদা একটা পথ ─────────────────────────────────────
     * `tokens()` কাজের কপি মেলায় — সম্পাদক ও প্রিভিউয়ের জন্য। এটা
     * মেলায় প্রকাশিতটা। একটাই পথ রাখলে সম্পাদনা শুরু করা মাত্র গোটা
     * ডিপোর পর্দা বদলে যেত।
     *
     * ── পূর্বপুরুষও প্রকাশিতটাই দেয় ──────────────────────────────────
     * চেইনের উপরের স্কিনগুলোও এখানে তাদের প্রকাশিত সংস্করণ দেয়,
     * খসড়া নয়। নাহলে একটা সন্তান প্রকাশ করলে পূর্বপুরুষের **অপ্রকাশিত**
     * কাজটাও নীরবে সবার পর্দায় চলে যেত — যে কাজটা কেউ প্রকাশযোগ্য
     * বলে মেনে নেয়নি।
     *
     * @return array<string, string>
     */
    public function liveTokens(string $theme = 'light'): array
    {
        return $this->walk($theme, live: true);
    }

    /**
     * চেইন ধরে হাঁটা — খসড়া বা প্রকাশিত, একই পথে।
     *
     * @return array<string, string>
     */
    private function walk(string $theme, bool $live): array
    {
        $own = $live
            ? ($this->live()?->ownTokens($theme) ?? [])
            : $this->ownTokens($theme);

        return [...$this->inherited($theme, $live), ...$own];
    }

    /**
     * এই সারিটা বাদ দিয়ে বাকি চেইন — অর্থাৎ যা **উত্তরাধিকারে পাওয়া**।
     *
     * ── কেন এটা আলাদা করে লাগে ──────────────────────────────────────
     * কনট্রাস্ট গেট নাহলে কোম্পানিকে এমন কিছুর জন্য শাস্তি দিত যা সে
     * করেনি। Odoo-র নিজের ফিকে কালি (#8A7F90, ৩.৮:১) AA-র নিচে, আর
     * মালিকের সিদ্ধান্ত ওটা আসল পণ্যের মতোই রাখা।
     *
     * পুরো সেট মেপে আটকালে **Odoo, Redwood বা Fiori-র উপর দাঁড়ানো
     * কোনো রূপ কোনোদিন প্রকাশ হত না** — দশটার তিনটাই বন্ধ, অথচ
     * কোম্পানি ওই টোকেনগুলো ছোঁয়নি পর্যন্ত।
     *
     * @return array<string, string>
     */
    private function inherited(string $theme, bool $live = false): array
    {
        $chain = [];
        $skin = self::query()->withoutGlobalScopes()
            ->where('public_id', $this->parent)->first();

        if ($skin === null) {
            return LookRegistry::tokens(Ui::clean($this->parent), $theme);
        }

        $depth = 0;

        while ($depth++ < self::MAX_DEPTH) {
            $chain[] = $live
                ? ($skin->live()?->ownTokens($theme) ?? [])
                : $skin->ownTokens($theme);

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
     * চেইনের গোড়ার কোড-রূপটা — `navy`, `apps`, `redwood`…
     *
     * ── কেন এটা লাগে ────────────────────────────────────────────────
     * একটা রূপ কেবল রং নয়। মেনু বাঁয়ে না উপরে, শিরোনাম পটিতে না
     * পাতার ভিতরে — ওগুলো টোকেন নয়, **markup**, আর ওগুলো ঠিক হয়
     * কোড-রূপের নাম দেখে।
     *
     * স্কিনের `public_id` দিয়ে ওই প্রশ্নের উত্তর হয় না। উত্তর না
     * পেলে খোলসটা ডিফল্টে নামত, আর Odoo-র উপর দাঁড়ানো একটা রূপ
     * Odoo-র রং নিয়ে Navy-র খোলসে বসত — অর্ধেক নকল, যা নকল না
     * হওয়ার চেয়েও খারাপ।
     */
    public function rootLook(): string
    {
        $skin = $this;
        $depth = 0;

        while ($depth++ < self::MAX_DEPTH) {
            $parent = self::query()->withoutGlobalScopes()
                ->where('public_id', $skin->parent)->first();

            if ($parent === null) {
                return Ui::clean($skin->parent);
            }

            $skin = $parent;
        }

        return Ui::DEFAULT;
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
                ...$this->newlyFaint($theme),
            ];
        }

        return array_values(array_unique($said));
    }

    /**
     * এই রূপ যে জোড়াগুলো **খারাপ করেছে** — যেগুলো আগেই খারাপ ছিল সেগুলো নয়।
     *
     * ── কেন উত্তরাধিকারের ব্যর্থতা এখানে গোনা হয় না ───────────────────
     * নকলের দাম হিসেবে আজ বারোটা জোড়া AA-র নিচে — Odoo-র ফিকে কালি,
     * Redwood-এর ধূসর, Fiori-র সতর্ক-ব্যাজ। মালিকের সিদ্ধান্ত
     * (২৪ আগস্ট ২০২৬): **আসল পণ্যের মতোই রাখা**, আর ছাড়টা
     * `FaintTextOnACheapMonitorTest`-এ নাম ধরে গোনা।
     *
     * পুরো সেট মেপে আটকালে ওই তিনটা রূপের উপর কোনো কোম্পানির রূপ
     * কোনোদিন প্রকাশ হত না — যদিও সে ওই টোকেনগুলো ছোঁয়নি। গেটটা তখন
     * নিরাপত্তা নয়, একটা বন্ধ দরজা।
     *
     * তাই নিয়মটা ব্যবহারিক ও বলা যায় এমন: **আপনি যা পেয়েছেন তা
     * ঠিক করতে হবে না, কিন্তু খারাপ করা যাবে না।**
     *
     * @return list<string>
     */
    private function newlyFaint(string $theme): array
    {
        $before = [];

        foreach (LookGate::failures($this->inherited($theme)) as $bad) {
            $before[$bad['ink'].'|'.$bad['on']] = true;
        }

        $new = [];

        foreach (LookGate::failures($this->tokens($theme)) as $bad) {
            if (isset($before[$bad['ink'].'|'.$bad['on']])) {
                continue;
            }

            $new[] = __('core.look.too_faint', [
                'ink' => $bad['ink'],
                'on' => $bad['on'],
                'ratio' => number_format($bad['ratio'], 2),
                'need' => number_format(LookGate::AA, 1),
            ]);
        }

        return $new;
    }
}
