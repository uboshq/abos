<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ সরবরাহকারীর ব্যাংক তথ্যের একটা সারি — চাওয়া, সই-পাওয়া, বা না-হওয়া (নকশা দফা #৬, ২১ অক্টোবর ২০২৬; [[SupplierBank]])।
 *
 * ⓘ চালু তথ্য = `signed` সারি; প্রতি সরবরাহকারীর একটাই। নতুন সারি সই না পাওয়া পর্যন্ত পুরনোটাই চালু থাকে।
 * ⚠️ `account_no` ঢাকা (`encrypted`) — নিরীক্ষার খাতায় মান নয়, কেবল "ঢাকা" লেখা হয়।
 *
 * @property string $account_no
 */
class SupplierBankDetail extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const PENDING = 'pending';

    public const SIGNED = 'signed';

    public const SUPERSEDED = 'superseded';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    protected $table = 'sup_bank_details';

    protected $fillable = [
        'company_id', 'supplier_id', 'bank_name', 'branch_name', 'account_name', 'account_no', 'account_last4', 'routing_no',
        'status', 'requested_by', 'signed_by', 'signed_at', 'decided_by', 'decided_at', 'reason', 'decision_note',
    ];

    protected $hidden = ['account_no'];

    protected function casts(): array
    {
        return [
            'account_no' => 'encrypted',
            'signed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::SIGNED;
    }

    /** ••••4521 — শেষ চার অঙ্ক ছাড়া সব ঢাকা */
    public function maskedAccount(): string
    {
        return '••••'.$this->account_last4;
    }
}
