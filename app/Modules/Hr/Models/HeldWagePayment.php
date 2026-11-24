<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ আটকে থাকা মজুরি কোন ব্যাংক ফাইলে "বকেয়া মজুরি" হয়ে গেল — স্লিপ প্রতি একটাই সারি (unique), তাই দুইবার কখনো নয় (fe, ২১ অক্টোবর
 * ২০২৬; শ্রম আইন ধারা ১২৩; [[PayrollService::arrearsFor()]])।
 *
 * ⓘ আটকে থাকা স্লিপ যে রানের, সেটা বদলায় না (পোস্ট-হওয়া রান অটল); এই সারি কেবল বলে কোন পরের রানের ফাইল এটা বহন করল।
 */
class HeldWagePayment extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use SharedAcrossCompaniesWhenAsked;

    protected $table = 'hr_held_wage_payments';

    protected $fillable = ['company_id', 'payslip_id', 'payroll_run_id', 'paid_in_run_id', 'voucher_id', 'channel', 'amount', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function paidIn(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'paid_in_run_id');
    }

    /** ⓘ বকেয়ার নিজের কাগজে দেওয়া হলে তার খসড়া পরিশোধ ভাউচার ([[HeldWages::pay()]]) */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Accounts\Models\Voucher::class);
    }
}
