<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsMasterRecord;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * বেতনের একটা খাত — মূল বেতন, বাড়িভাড়া, ভবিষ্য তহবিল, অগ্রিম কাটা।
 *
 * খাতগুলো সারি, enum নয়: কোন প্রতিষ্ঠানে কী কী ভাতা আছে তা তাদের নিজের
 * ব্যাপার, আর নতুন একটা যোগ করতে রিলিজ লাগা উচিত নয়।
 */
class SalaryHead extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'hr_salary_heads';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'kind', 'calculation', 'is_basic', 'prorated_by_attendance',
        'account_id', 'sort_order', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_basic' => 'boolean',
            'prorated_by_attendance' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** যোগ হবে না বিয়োগ — এই দুইটাই, আর তৃতীয় কিছু হয় না। */
    public const EARNING = 'earning';

    public const DEDUCTION = 'deduction';

    /** @var list<string> */
    public const KINDS = [self::EARNING, self::DEDUCTION];

    /**
     * অঙ্কটা কীভাবে বসে।
     *
     * fixed — টাকার অঙ্ক যেমন লেখা আছে।
     * percent_of_basic — মূল বেতনের শতাংশ, তাই বেতন বাড়লে ভাতাও বাড়ে।
     *
     * @var list<string>
     */
    public const FIXED = 'fixed';

    public const PERCENT_OF_BASIC = 'percent_of_basic';

    /** @var list<string> */
    public const CALCULATIONS = [self::FIXED, self::PERCENT_OF_BASIC];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeEarnings(Builder $query): Builder
    {
        return $query->where('kind', self::EARNING);
    }

    public function scopeDeductions(Builder $query): Builder
    {
        return $query->where('kind', self::DEDUCTION);
    }

    public function isEarning(): bool
    {
        return $this->kind === self::EARNING;
    }
}
