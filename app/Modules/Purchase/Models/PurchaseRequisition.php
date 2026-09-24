<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা চাহিদা — কে চাইল, কী চাইল, আর কেন।
 *
 * ── ⛔ এর আগে চাওয়াটা কোথাও থাকত না ─────────────────────────────────
 * ক্রয়াদেশ সরাসরি লেখা হত, তাই *"কে চেয়েছিল"* প্রশ্নের উত্তর ছিল কারও
 * স্মৃতি। ⚠️ আর চাওয়া ও কেনা এক হয়ে থাকায় **থামার কোনো জায়গা ছিল
 * না**: যে মুহূর্তে কাগজটা লেখা হত, সেটাই প্রতিশ্রুতি।
 *
 * ── ⓘ অবস্থাগুলো বিদ্যমান শব্দভাণ্ডারেই ──────────────────────────────
 *     draft     — লেখা হচ্ছে, কেউ দেখেনি
 *     confirmed — অনুমোদিত; এখন এ থেকে আদেশ বানানো যায়
 *     closed    — আদেশে রূপান্তরিত, কাজ শেষ
 *     cancelled — বাতিল
 *
 * ⚠️ স্পেকের `CONVERTED` এখানে `closed` — ⓘ নতুন একটা শব্দ বানালে
 * প্রতিটা তালিকা, ছাঁকনি আর ব্যাজকে সেটা শেখাতে হত, অথচ মানেটা ঠিক
 * একই: কাগজটার আর কিছু করার নেই।
 */
class PurchaseRequisition extends Model
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'pur_requisitions';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'trx_date', 'needed_by',
        'requested_by', 'department', 'purpose', 'narration',
        'status', 'purchase_order_id', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'needed_by' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class)->orderBy('line_no');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * এ থেকে আদেশ বানানো যায় কি না।
     *
     * ── ⚠️ দুইটা শর্ত, আর দুইটাই আলাদা কারণে ─────────────────────────
     * ⓘ অনুমোদিত হতে হবে — নাহলে খসড়া চাহিদা থেকেই কেনা হয়ে যেত, আর
     * অনুমোদনের ধাপটা এড়ানো যেত। ⛔ আর আগে রূপান্তরিত হয়ে থাকলে নয়:
     * একই চাহিদা থেকে দুইটা আদেশ মানে **দুইবার কেনা**, আর ভুলটা ধরা
     * পড়ত মাল এসে গুদামে জায়গা না পাওয়ার দিনে।
     */
    public function canBecomeAnOrder(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED
            && $this->purchase_order_id === null;
    }

    /**
     * আন্দাজি মোট — অনুমোদনের ছক এটাই দেখে।
     *
     * ⚠️ দর না বসানো সারিগুলো শূন্য ধরা হয়, ⓘ কারণ *"জানি না"* আর
     * *"শূন্য"* এখানে একই সিদ্ধান্তে পৌঁছায়: ঐ সারিটা মোটে কিছু যোগ
     * করে না। ⛔ ধরে-নেওয়া কোনো দর বসানো হয় না — তাতে অনুমোদনের
     * সীমাটাই মিথ্যা হয়ে যেত।
     */
    public function estimatedTotal(): string
    {
        return $this->lines->reduce(
            fn (string $sum, PurchaseRequisitionLine $line) => bcadd(
                $sum,
                bcmul((string) $line->qty, (string) ($line->estimated_rate ?? '0'), 4),
                4,
            ),
            '0',
        );
    }
}
