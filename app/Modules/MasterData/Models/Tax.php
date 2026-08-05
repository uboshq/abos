<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা কর — ভ্যাট, এআইটি, ভিডিএস।
 *
 * হার এখানে সংরক্ষিত, কিন্তু লেনদেনে বসার সময় হারটা কপি হয়ে যায়:
 * হার বদলায়, আর ৭.৫% থেকে ১০% হলে পুরনো বিলগুলোর কর বদলে যাওয়া চলবে না।
 */
class Tax extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_taxes';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'rate', 'kind', 'is_inclusive', 'account_id',
        'is_default', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_inclusive' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @var list<string> */
    public const KINDS = ['vat', 'ait', 'vds', 'sd'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * একটা অঙ্কের উপর করের পরিমাণ।
     *
     * দামের ভেতরে থাকলে হিসাবটা উল্টো: ১১৫ টাকায় ১৫% ভ্যাট থাকলে কর
     * ১১৫ × ০.১৫ নয়, ১১৫ − (১১৫ ÷ ১.১৫) = ১৫। বাইরের নিয়মে কষলে
     * প্রতিটা খুচরা বিলে কর বেশি বসত।
     */
    /**
     * নাম on() নয়: Eloquent-এর Model::on() static, আর একই নামে
     * অ-static পদ্ধতি লিখলে PHP থেমে যায়। PHPUnit-এর run()/count()/post()
     * -এর মতোই ফাঁদ, শুধু অন্য ক্লাসে।
     */
    public function amountOn(string $amount): string
    {
        $rate = bcdiv((string) $this->rate, '100', 6);

        if ($this->is_inclusive) {
            $net = bcdiv($amount, bcadd('1', $rate, 6), 4);

            return bcsub($amount, $net, 4);
        }

        return bcmul($amount, $rate, 4);
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'tax';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['master_data.tax.show', ['tax' => $this->id]];
    }
}
