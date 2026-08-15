<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * কে কোন তালিকা নামিয়ে নিয়ে গেছে।
 *
 * ── কেন এটা মুছে ফেলা যায় না ────────────────────────────────────────
 * `audit_trails`-এর মতোই: সংশোধনের কোনো পথ নেই, `updated_at` নেই, আর
 * নিজে কোনো অডিট লেখে না। যে খাতা বদলানো যায়, সেটা খাতা নয়।
 */
class ExportLog extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $table = 'export_log';

    /** একটা রপ্তানি সম্পাদনা হয় না — কেবল ঘটার সময়টা। */
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'branch_id', 'user_id', 'user_name',
        'route', 'title', 'filters', 'row_count',
        'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * যিনি নামিয়েছিলেন — নাম, ব্যবহারকারী মুছে ফেলা হলেও।
     *
     * সম্পর্কটা আগে দেখা হয়, কারণ কেউ নাম বদলালে বর্তমান নামটাই ঠিক।
     * জমানো নামটা কেবল তখনই লাগে যখন সম্পর্কটা আর নেই।
     */
    public function who(): string
    {
        return $this->user?->name ?? ($this->user_name ?: __('core.export.someone_gone'));
    }

    /**
     * ছাঁকনিগুলো এক লাইনে — "from: ২০২৬-০১-০১ · to: ২০২৬-১২-৩১"।
     *
     * ── কেন খালি ঘরগুলো বাদ যায় ─────────────────────────────────────
     * প্রতিটা পর্দা তার সব ছাঁকনির নাম পাঠায়, বেশিরভাগ খালি। সব
     * দেখালে সারিটা এত লম্বা হত যে আসল দুইটা তারিখ খুঁজে পাওয়া যেত না।
     */
    public function filterSummary(): string
    {
        $parts = [];

        foreach ((array) $this->filters as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $parts[] = $key.': '.(is_scalar($value) ? (string) $value : json_encode($value));
        }

        return implode(' · ', $parts);
    }

    /** সাম্প্রতিকতমটা আগে — কেউ এই পর্দায় আসে সাম্প্রতিক ঘটনা দেখতেই। */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
