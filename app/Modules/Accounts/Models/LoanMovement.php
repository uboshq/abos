<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ঋণের একটা নড়াচড়া — এক দিনে এক ঘটনা।
 *
 * খতিয়ানে ঋণ নিজে বসে না, এই সারিগুলো বসে। কারণটা মাইগ্রেশনে লেখা:
 * ঋণ একটা চুক্তি, ঘটনা নয়।
 */
class LoanMovement extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** টাকা তোলা — CC-তে যতবার খুশি, টার্ম লোনে একবার। */
    public const DRAW = 'draw';

    /** জমা — কেবল দায় কমে। */
    public const REPAY = 'repay';

    /** সুদ বসানো — টাকা নড়ে না, ধার বাড়ে। */
    public const INTEREST = 'interest';

    protected $table = 'acc_loan_movements';

    protected $fillable = [
        'company_id', 'branch_id', 'loan_id', 'kind', 'document_no',
        'trx_date', 'amount', 'counter_account_id', 'narration', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::DRAW => __('accounts::action.draw_down'),
            self::REPAY => __('accounts::action.repay'),
            default => __('accounts::action.charge_interest'),
        };
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'loan_movement';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->label().' — '.$this->document_no;
    }

    /** নড়াচড়ার নিজের পর্দা নেই; ঋণের পাতাতেই সবটা দেখা যায়। */
    public function drillRoute(): array
    {
        return ['accounts.loan.show', ['loan' => $this->loan_id]];
    }
}
