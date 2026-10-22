<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompanyThroughParent;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ আদায়ের একটা প্রয়োগ — খোলা ক্রেডিট বিলে, বা টাকা ডেবিট নোটে (দফা ৩; মালিকের নকশা ১০০, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ `credit_note` আর `credit` সারি কেবল কাটাকাটি: ক্রেডিটটা খাতায় আগেই Cr, তাই কোনো দাখিলা নেই — বিলের বাকি কমে
 * ([[SalesInvoice::collectedAmount()]]) আর ক্রেডিটের খোলা অংশ কমে ([[CollectionOpenItems]])। `debit_note` সারি এই আদায়ের
 * টাকার ভাগ, যা ডেবিট নোট শোধ করল। ⓘ কেবল পাকা আদায়ের সারি গোনা হয়।
 */
class CollectionApplication extends Model
{
    use BelongsToCompanyThroughParent;
    use HasPublicId;
    use IsAudited;

    public const CREDIT_NOTE = 'credit_note';

    public const CREDIT = 'credit';

    public const DEBIT_NOTE = 'debit_note';

    protected $table = 'sal_collection_applications';

    protected $fillable = ['collection_id', 'kind', 'source_id', 'target_invoice_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    protected function companyParent(): string
    {
        return 'collection';
    }

    /**
     * ⭐ অডিটের সারিটা কার খাতায় — আদায়টার কোম্পানি ([[AnAuditedModelMustSayWhoseBooksItBelongsToTest]]; ছাড়ের তালিকায় না বসে)।
     *
     * ⓘ এই টেবিলের নিজের `company_id` নেই; উত্তরটা আদায়ের সারির, প্রসঙ্গের নয়। ⚠️ `acrossBranches()`: আদায় বসে গ্রাহকের
     * শাখায় (দফা ১৪), হেডারে বাছা শাখায় নয় — শাখার দেয়াল থাকলে এক শাখায় বাঁধা মানুষের হাতে আদায়টা "নেই" হত আর
     * [[AuditEngine]] কোম্পানি না পেয়ে নিঃশব্দে কিছুই লিখত না। কোম্পানির দেয়াল থাকে।
     */
    public function auditCompanyId(): ?int
    {
        $companyId = $this->auditPaper()['company_id'] ?? null;

        return $companyId === null ? null : (int) $companyId;
    }

    /** ⓘ আদায়ের শাখা — প্রয়োগটা সেই কাগজেরই সারি; হেডারের শাখা বসালে একই আদায়ের সারি দুই শাখায় দেখাত */
    public function auditBranchId(): ?int
    {
        $branchId = $this->auditPaper()['branch_id'] ?? null;

        return $branchId === null ? null : (int) $branchId;
    }

    /** @return array{company_id?: mixed, branch_id?: mixed} */
    private function auditPaper(): array
    {
        $row = Collection::acrossBranches()->whereKey((int) $this->collection_id)->first(['company_id', 'branch_id']);

        return $row === null ? [] : ['company_id' => $row->company_id, 'branch_id' => $row->branch_id];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'target_invoice_id');
    }
}
