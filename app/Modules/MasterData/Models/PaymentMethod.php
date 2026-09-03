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
 * কাউন্টারে টাকা নেওয়ার উপায় — "নগদ", "বিকাশ", "কার্ড"।
 *
 * ── কেন এটা একটা মাস্টার, enum নয় ───────────────────────────────────
 * প্রতিটা কোম্পানির তালিকা আলাদা: কারও বিকাশ আছে নগদ নেই, কারও দুইটা
 * কার্ড মেশিন দুই ব্যাংকের। কোডে লিখলে নতুন একটা উপায় যোগ করতে
 * ডেভেলপার লাগত, অথচ এটা সেটিংসের কাজ।
 *
 * ── প্রতিটা সারি একটা খাত বলে ────────────────────────────────────────
 * এটাই কাজটার কেন্দ্র। "বিকাশ" সারিটা বলে দেয় টাকাটা কোন খাতে বসবে,
 * তাই POS-কে আর অনুমান করতে হয় না। আগে সে কেবল নগদের খাত চিনত, আর
 * বিকাশে নেওয়া টাকাও "নগদ" হয়ে বসত — দিনশেষে ড্রয়ারে কম, খাতায় বেশি।
 */
class PaymentMethod extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_payment_methods';

    /**
     * টাকা কীভাবে এল — চার ধরন, Accounts-এর instrument taxonomy ও
     * StandardChart-এর তিন মায়ের সাথেই এক শব্দভাণ্ডার (নগদ ১১০১ · ব্যাংক
     * ১১০২ · MFS ১১০৫; চেক ব্যাংকেই বসে, তাই খাত নয়, কোড থেকে চেনা)।
     *
     * পর্দা এই ধরন দেখেই "যে খাতে জমা" তালিকা ছাঁকে: নগদে নগদের খাত,
     * ব্যাংকে ব্যাংক হিসাব, MFS-এ মোবাইল মানি। সারি নয় — এটা সত্যিই বদ্ধ
     * তালিকা, কারণ প্রতিটা ধরন হিসাবের একটা মায়ের সাথে বাঁধা; নতুন একটা
     * ধরন মানে একটা নতুন মা, যা কোম্পানির সেটিংসের কাজ নয়।
     *
     * @var list<string>
     */
    public const KINDS = ['cash', 'bank', 'mfs', 'cheque'];

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'kind',
        'account_id', 'needs_reference', 'fee_percent',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'needs_reference' => 'boolean',
            'fee_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * টাকাটা ড্রয়ারে যায়, নাকি অন্য কোথাও।
     *
     * নগদ হলে দিনশেষের গণনায় ওই টাকাটা থাকার কথা; বিকাশ বা কার্ড হলে
     * নয়। কাউন্টারের গণনা মেলানোর সময় এই তফাতটাই সব — এটা না জানলে
     * ব্যবস্থা এমন টাকার ঘাটতি দেখাত যেটা কোনোদিন ড্রয়ারেই আসেনি।
     */
    public function isCash(): bool
    {
        return (bool) $this->account?->is_cash;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'payment_method';
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
        return ['master_data.list.index', ['kind' => 'payment-methods']];
    }
}
