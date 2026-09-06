<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * একটা নির্ধারিত রিপোর্ট — কোনটা, কখন, কার কাছে।
 *
 * ফাইলটা সূচিমতো নিজে থেকে তৈরি হয়, রোজ সকালে কারো হাতে চাওয়ার বদলে।
 * `created_by` অনুমতির মালিক — ক্রন এঁর প্রসঙ্গে রিপোর্ট রেন্ডার করে, তাই
 * যে ক্রয়মূল্য পর্দায় দেখতে পান না তা তাঁর ফাইলেও থাকে না।
 */
class ReportSchedule extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'report_key', 'filters', 'format',
        'frequency', 'at_time', 'day_of_week', 'day_of_month', 'on_month_end',
        'timezone', 'recipients', 'created_by', 'is_active',
        'next_run_at', 'last_run_at', 'last_status',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'recipients' => 'array',
            'on_month_end' => 'boolean',
            'is_active' => 'boolean',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * যে সূচিগুলোর সময় হয়েছে — সব কোম্পানি জুড়ে।
     *
     * ⚠️ ক্রনের নিজের কোম্পানি-প্রসঙ্গ নেই, তাই company global scope এখানে
     * সরানো হয়: নাহলে `company_id = null`-এ ছেঁকে কিছুই আসত না, আর একটা
     * সূচিও কখনো চলত না। runner প্রতিটা সারির নিজের company_id ধরে প্রসঙ্গ
     * বসিয়ে তবেই রিপোর্ট চালায়।
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->withoutGlobalScope('company')
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * প্রাপক ব্যবহারকারীরা — অভ্যন্তরীণ, id ধরে।
     *
     * ── ⛔ এখানে আগে যা লেখা ছিল, ৭ সেপ্টেম্বর ২০২৬ ────────────────────
     * *"company-প্রসঙ্গ বসানো অবস্থায় ডাকা হয়, তাই অন্য কোম্পানির id
     * ভুলেও এলে **global scope-ই ছেঁকে বাদ দেয়**।"*
     *
     * ⚠️ **এমন কোনো scope নেই।** মেপে দেখা হয়েছে: `User extends
     * Authenticatable` — [[BaseEntity]] নয়, নিজের `company_id` কলামও
     * নেই। ⓘ সে কোম্পানিতে ঝোলে `company_user` পিভটে, তাই ছাঁকনিটা
     * **হাতে** বসাতে হয়, আর হাতের কাজ ভুলে যাওয়া যায়।
     *
     * ⛔ বাক্যটার দ্বিতীয় অর্ধেকও ভুল ছিল: [[ScheduledReportRunner]]
     * প্রতিটা সময়সূচির পর `CompanyContext::clear()` করে।
     *
     * ⭐ এটাই এই রিপোর সবচেয়ে ব্যয়বহুল ছাঁচ — **নিয়মটা মন্তব্যে আছে,
     * কোডে নেই** — আর মন্তব্যটা এতই নিশ্চিত ভঙ্গিতে লেখা যে পড়ে কেউ
     * যাচাই করেন না। আজ একই দিনে এটা দুইবার ধরা পড়ল (অন্যটা ধারের
     * সীমায়, নগদ বিক্রি নিয়ে)।
     *
     * ── ⚠️ কেন যাচাই থাকা সত্ত্বেও এই দ্বিতীয় তালাটা লাগে ─────────────
     * যাচাই কেবল **আজকের পরে** সংরক্ষিত সারিগুলোকে ধরে। ⓘ আগে বসানো
     * সময়সূচিগুলো ওই যাচাই কোনোদিন দেখেনি — আর সেগুলো **প্রতি সপ্তাহে
     * নিজে থেকে চলে**, কেউ কিছু না চাপলেও।
     *
     * ── কেন `$this->company_id`, `CompanyContext::id()` নয় ────────────
     * ক্রনে প্রসঙ্গ খালি থাকে। ⛔ প্রসঙ্গ ধরে ছাঁকলে ছাঁকনিটা **সবাইকে**
     * বাদ দিত, রিপোর্ট কারও কাছেই যেত না, আর কেউ কারণটা বুঝতে পারত না।
     * ⓘ সারিটা নিজেই জানে সে কোন কোম্পানির — সেটাই এখানে সত্য।
     *
     * @return Collection<int, User>
     */
    public function recipientUsers(): Collection
    {
        $ids = array_values(array_filter((array) $this->recipients));

        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->whereHas('companies', fn ($q) => $q->whereKey($this->company_id))
            ->get();
    }
}
