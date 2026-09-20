<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\IsAudited;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা ব্যাংক/MFS খাত কোন প্রতিষ্ঠানের — জোড়াটা অর্থের, ছকের নয়।
 *
 * ⓘ কেন এখানে, `accounts`-এ নয়: মাইগ্রেশনের মাথায়
 * (`the_bank_account_did_not_know_its_bank`)।
 */
class InstitutionAccount extends Model
{
    use BelongsToCompany;
    use IsAudited;

    protected $table = 'fin_institution_accounts';

    protected $fillable = ['company_id', 'institution_id', 'account_id', 'created_by'];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
