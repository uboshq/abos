<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/**
 * একটা রোল কত টাকা পর্যন্ত সই দিতে পারে।
 *
 * ── ⚠️ নির্দিষ্ট সারি সাধারণ সারিকে ছাপিয়ে যায় ──────────────────────
 * ⓘ *"বিক্রয় ব্যবস্থাপক · সবখানে · ১ লাখ"* একটা সাধারণ নিয়ম।
 * *"বিক্রয় ব্যবস্থাপক · বিক্রয় · ময়মনসিংহ · ৫ লাখ"* সেটাকে ছাপায়।
 *
 * ⛔ উল্টোটা হলে — সাধারণ সারি জিতলে — শাখার নিয়মটা লেখা থাকত আর
 * কোনোদিন চলত না, যা না-লেখার চেয়ে খারাপ।
 */
class ApprovalLimit extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'company_id', 'role_id', 'module', 'action', 'branch_id', 'max_amount',
    ];

    protected function casts(): array
    {
        return ['max_amount' => 'decimal:4'];
    }

    public function role(): BelongsTo
    {
        /*
         * ⛔ `Role` Spatie-র, `App\Models\Role` নয় — ওই ক্লাসটা নেই।
         *
         * ⚠️ import ছাড়া `Role::class` এই namespace-এ `App\Models\Role`
         * হয়ে যেত, আর সীমার পর্দা একটা সারি আঁকার সাথে সাথে
         * *"Class not found"* ছুঁড়ত।
         *
         * ⓘ সম্পর্কটা আর কেউ ছোঁত না, তাই পর্দাটা বানানোর আগ
         * পর্যন্ত ভুলটা চুপ ছিল — আর সেই জন্যই পর্দার দাবি লাগে।
         */
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * ⭐ কত নির্দিষ্ট — বেশি নির্দিষ্ট সারি আগে।
     *
     * ⓘ শাখা ২, কাজ ১, মডিউল ১ — যোগফল যত বড়, সারিটা তত নির্দিষ্ট।
     * ⚠️ শাখাকে বেশি ওজন দেওয়া ইচ্ছাকৃত: *"এই শাখায় এই মানুষ"* সবসময়
     * *"সব শাখায় এই কাজ"*-এর চেয়ে বেশি নির্দিষ্ট কথা।
     */
    public function weight(): int
    {
        return ($this->branch_id === null ? 0 : 2)
            + ($this->action === null ? 0 : 1)
            + ($this->module === null ? 0 : 1);
    }

    /** এই সারিটা কি এই কাজে ও এই শাখায় খাটে? */
    public function fits(string $module, string $action, ?int $branchId): bool
    {
        if ($this->module !== null && $this->module !== $module) {
            return false;
        }

        if ($this->action !== null && $this->action !== $module.'.'.$action) {
            return false;
        }

        return $this->branch_id === null || (int) $this->branch_id === $branchId;
    }
}
