<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ ব্যাংকের বিবরণীর জের — অর্থ-মডিউলের পরিকল্পনা ৩.৬, ৬ অক্টোবর ২০২৬: "ঋণ হিসাবের স্টেটমেন্ট বনাম খাতা"।
 *
 * ⓘ ব্যাংক একটা তারিখে যা বলে, কেবল সেটাই রাখা হয়; খাতার জের রাখা হয় না — প্রতিবার খাতা থেকে পড়া
 * ([[BankFacilityService::statementGaps()]]), যাতে দুই সংখ্যা কখনো আলাদা না হয়। টাকা নড়ে না।
 */
class FacilityStatement extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'fin_facility_statements';

    protected $fillable = [
        'company_id', 'branch_id', 'bank_facility_id', 'statement_on', 'bank_balance', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'statement_on' => 'date',
            'bank_balance' => 'decimal:4',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(BankFacility::class, 'bank_facility_id');
    }
}
