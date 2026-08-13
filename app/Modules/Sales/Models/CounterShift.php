<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা শিফট — এই ড্রয়ারে এই মানুষটা, এই সময়টুকু।
 *
 * ── অবস্থা দুইটাই, DocumentStatus নয় ─────────────────────────────────
 * শিফট কোনো ডকুমেন্ট নয়: খাতায় কিছু বসে না, বাতিল হয় না, অনুমোদন
 * লাগে না। খোলা বা বন্ধ — এর বেশি অবস্থা নেই, আর ডকুমেন্টের ছয়টা
 * অবস্থার ছাঁচে ঢোকালে বাকি চারটার মানে কী তা কেউ বলতে পারত না।
 */
class CounterShift extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'sal_counter_shifts';

    protected $fillable = [
        'company_id', 'branch_id', 'cash_till_id', 'user_id',
        'opened_at', 'opening_counted', 'closed_at', 'closing_counted',
        'opening_ledger_id', 'closing_ledger_id',
        'status', 'narration', 'created_by',

        /*
         * খোলা কিনা তার চিহ্ন — ডাটাবেজের ইউনিক ইনডেক্সের জন্য।
         *
         * খোলা শিফটে ১, বন্ধ শিফটে NULL। MySQL-এ NULL সংঘর্ষ করে না,
         * তাই এক টিলে একটার বেশি খোলা শিফট ডাটাবেজেই আটকায়।
         */
        'open_marker',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_counted' => 'decimal:4',
            'closing_counted' => 'decimal:4',
        ];
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(CashTill::class, 'cash_till_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }
}
