<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompanyThroughParent;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ এক টাকা, বহু বিল — কোন বিলে কত বসল (cb-ma-4, ২৫ অক্টোবর ২০২৬; মালিকের নকশার "এই রসিদে কত")।
 *
 * ⓘ সারি লেখা হয় কাগজ লেখার সময় ([[VoucherAllocations::write()]]), আর প্রতিটা আবার মাপা হয় পোস্টের মুহূর্তে, তালাসহ
 * ([[VoucherAllocations::assertFit()]] — অডিট ম১-এর একই পাঁচ প্রশ্ন)। ⓘ বিলের বাকি গোনে কেবল পাকা ভাউচারের সারি।
 * ⓘ নিরীক্ষায়: কোন বিলে টাকা বসল — সরলে চুপচাপ নয় ([[VoucherBillShare]]-এর একই কারণ)।
 */
final class VoucherAllocation extends Model
{
    use BelongsToCompanyThroughParent;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_voucher_allocations';

    protected $fillable = ['voucher_id', 'against_type', 'against_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    /** ⓘ কোম্পানি ভাউচারের মাধ্যমে ([[BelongsToCompanyThroughParent]]) */
    protected function companyParent(): string
    {
        return 'voucher';
    }

    /** ⓘ অডিটের সারি ভাউচারের কোম্পানির খাতায় — প্রসঙ্গের নয় ([[VoucherBillShare::auditCompanyId()]]-এর একই কারণ) */
    public function auditCompanyId(): ?int
    {
        return $this->voucher?->company_id;
    }

    public function auditBranchId(): ?int
    {
        return $this->voucher?->branch_id;
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
