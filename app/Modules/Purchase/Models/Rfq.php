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
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * দরপত্রের অনুরোধ — আমরা কী চেয়েছি, আর কাকে কাকে জিজ্ঞেস করেছি।
 *
 * ── ⛔ এর আগে কিছুই থাকত না ─────────────────────────────────────────
 * দর চাওয়া হত ফোনে, আর জবাবগুলো কারও ইনবক্সে। ⚠️ ক্রয়াদেশে কেবল
 * **জেতা দরটা** বসত; বাকি দুইজন কত চেয়েছিলেন তা কোথাও থাকত না — আর
 * তাই *"সবচেয়ে কম দর নেওয়া হয়েছিল তো?"* প্রশ্নের কোনো প্রমাণ ছিল না।
 *
 * ── ⓘ অবস্থাগুলো বিদ্যমান শব্দভাণ্ডারেই ──────────────────────────────
 *     draft     — লেখা হচ্ছে, কাউকে পাঠানো হয়নি
 *     confirmed — পাঠানো হয়েছে, জবাবের অপেক্ষায়
 *     closed    — সিদ্ধান্ত হয়ে গেছে
 *     cancelled — বাতিল
 */
class Rfq extends Model
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'pur_rfqs';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'trx_date', 'respond_by',
        'warehouse_id', 'purchase_requisition_id', 'terms', 'narration',
        'status', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'respond_by' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RfqLine::class, 'rfq_id')->orderBy('line_no');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'rfq_id');
    }

    /**
     * কাকে কাকে জিজ্ঞেস করা হয়েছে।
     *
     * ⚠️ এই সম্পর্কটাই *"তিনজনের কাছে চেয়েছিলাম"* দাবিটাকে প্রমাণে
     * পরিণত করে। ⛔ ছাড়া কেবল যাঁরা জবাব দিয়েছেন তাঁদের জানা যেত, আর
     * যিনি দেননি তিনি ইতিহাস থেকেই মুছে যেতেন — অথচ *"ওঁরা কেউ জবাব
     * দেননি"* কথাটাও একটা তথ্য।
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'pur_rfq_suppliers', 'rfq_id', 'supplier_id')
            ->withPivot(['company_id', 'sent_on'])
            ->withTimestamps();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * এখনো জবাবের অপেক্ষায় কি না।
     *
     * ⓘ পাঠানো হয়েছে আর সিদ্ধান্ত হয়নি — এই অবস্থাতেই তাগাদা দেওয়ার
     * মানে হয়। ⚠️ খসড়ায় তাগাদা অর্থহীন (কাউকে পাঠানোই হয়নি), আর
     * বন্ধ হয়ে যাওয়ার পরেও।
     */
    public function isWaiting(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED;
    }

    /**
     * কারা এখনো জবাব দেননি।
     *
     * ⛔ এটাই তাগাদার তালিকা, আর এটা ছাড়া RFQ কেবল একটা পাঠানো
     * অনুরোধ — ⚠️ যার কোনো শেষ নেই।
     *
     * @return \Illuminate\Support\Collection<int, Supplier>
     */
    public function silentSuppliers()
    {
        $answered = $this->quotations->pluck('supplier_id')->all();

        return $this->suppliers->reject(
            fn (Supplier $supplier) => in_array($supplier->id, $answered, true),
        );
    }
}
