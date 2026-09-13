<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একজন মানুষ, যাঁর সাথে ব্যবসার টাকার সম্পর্ক আছে।
 *
 * মালিক ও অংশীদার (মূলধন দেন, উত্তোলন করেন), আত্মীয় ও বন্ধু (হাতে ধার
 * দেওয়া-নেওয়া), আর যাঁর নামে আমানত রাখা আছে।
 *
 * ── ⭐ এই তালিকাটা যা **নয়** ──────────────────────────────────────────
 * একটা মাস্টার তালিকার সবচেয়ে দরকারি কথা হলো তার সীমানা, কারণ সীমানা না
 * থাকলে ছয় মাস পরে এখানে সবাই ঢুকে পড়ে আর তালিকাটা কারো কাজে লাগে না:
 *
 *   কর্মী নন          → [[App\Modules\Hr\Models\Employee]]। কর্মীর বেতন,
 *                       হাজিরা, ছুটি — সম্পূর্ণ আলাদা জীবনচক্র। একজন
 *                       কর্মী মালিকের কাছ থেকে ধার নিলে সেটা অগ্রিম,
 *                       হাতে-ধার নয়।
 *   গ্রাহক নন         → [[App\Modules\Customer\Models\Customer]]। তাঁর
 *                       বকেয়া বিক্রয় থেকে আসে, আর তার নিজের খাত আছে।
 *   সরবরাহকারী নন     → [[App\Modules\Supplier\Models\Supplier]]।
 *   ব্যবহারকারী নন    → [[App\Models\User]] হলো যে লগইন করে। মালিক
 *                       সাধারণত দুইটাই, কিন্তু একজন অংশীদার কোনোদিন
 *                       ABOS খুলে না দেখলেও তাঁর মূলধন খাতায় থাকে।
 *
 * ── কেন এটা লাগল, ১৩ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * মালিক নিজে ধরেছেন: মূলধনের পর্দায় "কে" ঘরটা মুক্ত লেখা ছিল, আর তিনি
 * জিজ্ঞেস করেছেন *"একই মালিক আবার বিনিয়োগ করলে আবার নাম লিখতে হবে?"*
 *
 * ⛔ দামটা টাইপ করার কষ্ট নয়। আজ `Al Amin`, পরের মাসে `Al-Amin`, তারপর
 * `আল আমিন` — ব্যবস্থার কাছে **তিনজন আলাদা মানুষ**। তখন "ইনি মোট কত
 * বিনিয়োগ করেছেন" উত্তরটা তিন টুকরো হয়, **অংশ % ভুল হয়** (আর ওটা সোজা
 * মুনাফা ভাগের হিসাব), আর উত্তোলনের মাসিক সীমা **ভুল মানুষের উপর বসে**।
 *
 * ⚠️ আর কোনোটাই ভাঙে না। সংখ্যাগুলো চুপচাপ ভুল থাকে।
 *
 * নিয়মটা এই রিপোতে আগে থেকেই ছিল — [[NoCodeFieldIsEverLeftForTheUserToInventTest]]
 * কোডের বেলায় মানা হয়েছে, নামের বেলায় হয়নি।
 *
 * ── কেন নামে নকল-পাহারা **নরম**, কঠিন নয় ─────────────────────────────
 * ⚠️ দুইজন সত্যিকারের আলাদা মানুষের নাম সত্যিই এক হতে পারে — "মোঃ রহিম"
 * বহু। রিপো এই সিদ্ধান্তটা আগেই নিয়েছে ([[EveryMasterNamesItsDuplicateGuardTest]]-এ
 * `Employee`-র ছাড়ে লেখা), আর এখানেও সেটাই খাটে।
 *
 * তাই [[App\Modules\Customer\Models\Customer]]-এর ধরন: **নাম নরম, মোবাইল
 * কঠিন**। নাম মিললে সতর্ক করে থামে, `allow_duplicate` টিকে এগোতে দেয়
 * (আর সেই override অডিটে বসে)। মোবাইল মিললে থামে, কারণ একটা নম্বর
 * দুইজনের হয় না।
 *
 * ⓘ একটা সীমা জেনে রাখা দরকার: [[App\Core\Services\DuplicateGuard::normaliseName]]
 * যতিচিহ্ন ও কেস সরায়, তাই `Al Amin` ও `Al-Amin` ধরা পড়ে — কিন্তু
 * `আল আমিন` পড়ে না, কারণ লিপি আলাদা (ওই ফাইলে বাংলা বানান ইচ্ছাকৃতভাবে
 * ছোঁয়া হয়নি বলে লেখা আছে)। **আসল সুরক্ষা পাহারা নয়, বাছাইয়ের ঘর** —
 * ওটা টাইপ করাই বন্ধ করে দেয়।
 */
class Person extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_people';

    /**
     * ⓘ `is_default` নেই, ইচ্ছাকৃতভাবে — "ডিফল্ট মানুষ" বলে কিছু হয় না।
     * সাধারণ ফর্মটা সব তালিকার জন্য একটাই, তাই ঘরটা এলেও
     * [[App\Modules\MasterData\Services\MasterListService]] কলাম না পেয়ে
     * নিজেই ফেলে দেয়।
     */
    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'mobile', 'note', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'person';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['master_data.person.edit', ['id' => $this->id]];
    }
}
