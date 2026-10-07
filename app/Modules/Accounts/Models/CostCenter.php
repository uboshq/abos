<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

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
 * খরচের কেন্দ্র — "নেত্রকোনা রুট", "কেন্দুয়া রুট", "গুদাম", "অফিস"।
 *
 * ── কোন প্রশ্নের জন্য ────────────────────────────────────────────────
 * খাত বলে **কী খরচ** হয়েছে (জ্বালানি, বেতন, মেরামত)। কেন্দ্র বলে
 * **কোথায়** হয়েছে। দুইটা মিলিয়ে তবেই "নেত্রকোনার রুটে মাসে কত খরচ,
 * আর ওখান থেকে কত মার্জিন" প্রশ্নের উত্তর হয়।
 *
 * ৪% মার্জিনের ব্যবসায় একটা রুটের খরচ তার মার্জিনের চেয়ে বেশি হওয়া
 * খুবই সম্ভব — আর মোট হিসাবে সেটা দেখাই যায় না, কারণ অন্য রুটগুলো
 * টেনে নেয়।
 *
 * ── কেন এটা লোকেশনের গাছ নয় ─────────────────────────────────────────
 * লোকেশন (`mdm_locations`) বলে **কোথায় গ্রাহক থাকেন** — দেশ থেকে রুট
 * পর্যন্ত একটা ভৌগোলিক গাছ। খরচের কেন্দ্র সবসময় ভৌগোলিক নয়: "অফিস",
 * "গুদাম", "গাড়ি নং ঢাকা-মেট্রো-ট-১১-২২৩৩" — এগুলো কোনো এলাকা নয়।
 *
 * এক করে ফেললে লোকেশনের গাছে এমন সব সারি ঢুকত যেগুলো কোনো জায়গাই নয়,
 * আর গ্রাহকের ঠিকানা বাছার তালিকায় "অফিস" দেখা যেত।
 *
 * ── কেন MasterData-তে নয়, এখানে ──────────────────────────────────────
 * প্রথমে এটা MasterData-তে বসানো হয়েছিল — "কোম্পানির সম্পাদনাযোগ্য
 * তালিকা" ভেবে। কিন্তু কেন্দ্রটা বসে **খতিয়ানের সারিতে**
 * (`ledger_entries.cost_center_id`) আর **ভাউচারের লাইনে**
 * (`voucher_lines.cost_center_id`), আর একে পড়ে হিসাবেরই রিপোর্ট
 * (`accounts.by_cost_centre`)। ওটা হিসাবের মাত্রা, মাস্টার ডেটা নয়।
 *
 * সীমানার দিক থেকেও এটাই একমাত্র পথ: MasterData accounts-এর উপর
 * দাঁড়ায়, তাই accounts MasterData-র ভেতরে হাত দিলে চক্র হত আর
 * `ModuleRegistry` বুট-টাইমেই থেমে যেত। ধরেছে `BoundariesTest`।
 *
 * তালিকাটা সম্পাদনা করার **পর্দাটা** MasterData-তেই থাকে — ওদিকের
 * নির্ভরতাটা ঘোষিত, তাই ওতে কোনো সমস্যা নেই।
 */
class CostCenter extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'acc_cost_centers';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function drillSourceType(): string
    {
        return 'cost_center';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['master_data.list.index', ['kind' => 'cost-centers']];
    }
}
