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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * কারণ কোড — ফেরত, সমন্বয়, বাতিল।
 *
 * মুক্ত লেখায় নিলে দুইশো রকম বানান জমত, আর "কোন কারণে সবচেয়ে বেশি
 * ফেরত আসে" প্রশ্নের উত্তর বের করা যেত না। তালিকা থেকে বাছলে গোনা যায়।
 */
class ReasonCode extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_reason_codes';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'context', 'account_id', 'returns_to_stock', 'needs_approval',
        'visible_to_customer',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'returns_to_stock' => 'boolean',
            'visible_to_customer' => 'boolean',
            'needs_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public const SALES_RETURN = 'sales_return';

    public const PURCHASE_RETURN = 'purchase_return';

    public const STOCK_ADJUSTMENT = 'stock_adjustment';

    public const CANCELLATION = 'cancellation';

    public const DISCOUNT = 'discount';

    /**
     * মাল আটকে রাখার কারণ।
     *
     * তিন রকমের, আর তৃতীয়টাই এটাকে আলাদা করে রাখার কারণ:
     *
     *   ১. ক্ষতিগ্রস্ত বা সন্দেহজনক — তাকে আছে, বিক্রি করা যাবে না
     *   ২. ফেরত আসা মাল, যাচাইয়ের আগে
     *   ৩. **দাম বাড়ার অপেক্ষায় ধরে রাখা** — মাল ঠিকই আছে, মালিক
     *      এখন বেচতে চান না
     *
     * তৃতীয়টা ত্রুটি নয়, বাণিজ্যিক সিদ্ধান্ত। রিপোর্টে দুইটা একসাথে
     * গুনলে মালিককে বলা হত তার মালে সমস্যা আছে, অথচ ওটা তার কৌশল।
     */
    public const HOLD = 'hold';

    /**
     * বিক্রি ছাড়া মাল বেরিয়ে যাওয়া।
     *
     * অফিসে আপ্যায়ন, কাউকে উপহার, মালিকের নিজের ব্যবহার, নমুনা বিলানো।
     * সমন্বয় (adjustment) থেকে আলাদা রাখা হয়েছে ইচ্ছাকৃতভাবে: সমন্বয়
     * মানে "খাতা আর তাক মেলেনি", অর্থাৎ একটা ভুল ধরা পড়েছে। এটা ভুল
     * নয় — মালটা জেনেশুনে দেওয়া হয়েছে, আর সেটা খরচ বা উত্তোলন।
     *
     * এক তালিকায় রাখলে "মজুদ ঘাটতি" রিপোর্টে আপ্যায়নের বিস্কুটও
     * ঘাটতি হিসেবে দেখাত, আর কেউ ভাবত গুদামে চুরি হচ্ছে।
     */
    public const STOCK_ISSUE = 'stock_issue';

    /** @var list<string> */
    public const CONTEXTS = [
        self::SALES_RETURN, self::PURCHASE_RETURN,
        self::STOCK_ADJUSTMENT, self::STOCK_ISSUE,
        self::CANCELLATION, self::DISCOUNT,
        self::HOLD,
    ];

    /**
     * এই কারণে মাল বেরোলে টাকাটা কোন খাতে বসবে।
     *
     * না বললে null — তখন মজুদ ঘাটতি ও উদ্বৃত্তে যায়, যা গণনার
     * পার্থক্যের জন্য ঠিক। কিন্তু আপ্যায়ন বা মালিকের ব্যবহারের জন্য নয়,
     * আর সেখানেই এই ঘরটা লাগে।
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeInContext(Builder $query, string $context): Builder
    {
        return $query->where('context', $context);
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'reason_code';
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
        return ['master_data.reason.show', ['reason' => $this->id]];
    }
}
