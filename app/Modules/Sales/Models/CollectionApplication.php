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

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'target_invoice_id');
    }
}
