<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * জমার একটা চলাচল — খোলা, কিস্তি, মুনাফা তোলা, ভাঙা।
 *
 * ── কেন হাতধারের মতো কেবল চিহ্ন নয় ───────────────────────────────────
 * হাতধারে "ধার" আর "ফেরত" একই সম্পর্কের দুই দিক, তাই সেখানে চিহ্নই
 * যথেষ্ট। এখানে ঘটনাগুলো সত্যিই আলাদা: কিস্তি জমা দিলে মূলধন বাড়ে,
 * মুনাফা তুললে বাড়ে না — টাকাটা আয়। দুইটার খাতা-দাখিলাও আলাদা।
 *
 * চিহ্ন দিয়ে চালালে "এ পর্যন্ত কত জমেছে" প্রশ্নের উত্তরে মুনাফাও
 * যোগ হয়ে যেত, আর মেয়াদান্তে ব্যাংকের কাগজের সাথে মিলত না।
 */
class DepositMovement extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    /** টাকা রাখা হলো — প্রথম বার */
    public const OPENED = 'opened';

    /** মাসের কিস্তি — মূলধন বাড়ে */
    public const INSTALMENT = 'instalment';

    /** মুনাফা তোলা — মূলধন বাড়ে না, আয় হয় */
    public const PAYOUT = 'payout';

    /** ভাঙা বা মেয়াদপূর্তি — টাকা ফেরত */
    public const CLOSED = 'closed';

    /** @var list<string> */
    public const KINDS = [self::OPENED, self::INSTALMENT, self::PAYOUT, self::CLOSED];

    protected $table = 'fin_deposit_movements';

    protected $fillable = [
        'company_id', 'deposit_id', 'kind', 'amount', 'moved_on',
        'money_account_id', 'voucher_id', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'moved_on' => 'date',
        ];
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    /**
     * যে দাখিলাটা হলো — প্রতিটা সংখ্যা ওখানেই খোলে (নিয়ম ১)।
     *
     * বাতিল দাখিলা মুছে গেলে জোড়াটা null হয়, আর সারিটা তবু থাকে:
     * ঘটনাটা সত্যিই ঘটেছিল।
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** মূলধন বাড়ায় কি না — মুনাফা বাড়ায় না। */
    public function addsToPrincipal(): bool
    {
        return in_array($this->kind, [self::OPENED, self::INSTALMENT], true);
    }
}
