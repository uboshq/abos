<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ডেবিট ও ক্রেডিট নোট — মাল না নড়িয়ে টাকার সংশোধন (মানচিত্র §৭)।
 *
 * ── ⚠️ ফেরতের কাগজের সাথে সীমানাটা কোথায় ───────────────────────────
 * ফেরত মানে **মাল নড়ে** — স্টক ফিরে আসে, স্তরের দাম হিসাব হয়। ⛔ নোটে
 * মাল নড়ে না, আর সেটাই এর সংজ্ঞা। ⓘ দাম কমানোর কথা হলো, মাল নষ্ট বলে
 * ছাড় দেওয়া হলো, সরবরাহকারী বেশি দাম বসিয়েছে — এই সবগুলোতে গুদামে
 * কিছুই বদলায় না, কেবল টাকার অঙ্কটা বদলায়।
 *
 * ⚠️ এই পার্থক্যটা না থাকলে ফেরতের কাগজ কেটে ভুল শোধরাতে হত, আর তখন
 * স্টক মিথ্যা বলত: গুদামে যে মাল নেই সেটা ফিরে এসেছে বলে দেখাত।
 */
class Note extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** গ্রাহককে দেওয়া — আমরা যা পাই, কমে */
    public const CREDIT = 'credit';

    /** সরবরাহকারীকে দেওয়া — আমরা যা দেব, কমে */
    public const DEBIT = 'debit';

    /** @var list<string> */
    public const DIRECTIONS = [self::CREDIT, self::DEBIT];

    /**
     * কেন নোটটা কাটা হলো।
     *
     * ── ⓘ কেন বাছাই তালিকা, মুক্ত লেখা নয় ──────────────────────────
     * কারণটা পরে গোনা হয়: "কত টাকার মাল নষ্ট হয়ে ছাড় দিতে হলো" প্রশ্নের
     * উত্তর মুক্ত লেখায় কোনোদিন পাওয়া যেত না। ⚠️ তবু নিচে মানুষের নিজের
     * ভাষার ঘরটাও আছে — তালিকা কখনোই সব কারণ ধরতে পারে না।
     *
     * @var list<string>
     */
    public const REASONS = [
        'price_correction',   // দাম ভুল বসেছিল
        'damaged_goods',      // মাল নষ্ট, কিন্তু ফেরত যাচ্ছে না
        'short_delivery',     // গুনতিতে কম, কাগজে পুরো
        'agreed_discount',    // পরে ছাড়ের কথা হলো
        'other',
    ];

    protected $table = 'acc_notes';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'trx_date', 'direction',
        'party_type', 'party_id', 'against_type', 'against_id', 'against_no',
        'amount', 'tax_amount', 'total', 'reason', 'narration',
        'status', 'confirmed_by', 'confirmed_at',
        'cancel_reason', 'cancelled_by', 'cancelled_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'confirmed_at' => 'datetime',

            /* ⚠️ টাকার ঘরে float ঢুকলে ভুলটা নীরব — তাই `decimal:4` */
            'amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'cancelled_at' => 'datetime',
        ];
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === DocumentStatus::CANCELLED;
    }

    public function isCredit(): bool
    {
        return $this->direction === self::CREDIT;
    }

    public function scopeOfDirection(Builder $query, string $direction): Builder
    {
        return $query->where('direction', $direction);
    }

    /**
     * ⭐ একটাই নাম, দুই দিকের জন্য — আর প্রথমে ভুল সিদ্ধান্ত নিয়েছিলাম।
     *
     * ── ⚠️ যা করতে গিয়েছিলাম, আর কেন সেটা চলল না ───────────────────
     * প্রথমে দুইটা আলাদা নাম রেখেছিলাম (`credit_note`, `debit_note`),
     * যুক্তি ছিল "রিপোর্টে যেন দুই দিক না মেশে"। ⛔ কিন্তু মানচিত্রের
     * একটা নাম একটাই ক্লাস দাবি করতে পারে, আর দুই নামে এক ক্লাস বসালে
     * খতিয়ানের ঘর থেকে **লিংকটা কোনোদিন তৈরিই হত না, আর কোনো ত্রুটিও
     * আসত না** ([[Tests\Feature\Architecture\DrillSourcesTest]] ধরেছে)।
     *
     * ⓘ আর আসল কথা: যুক্তিটাই দুর্বল ছিল। দুই দিক এমনিতেই আলাদা — একটা
     * বসে প্রাপ্যে (১১১০), অন্যটা প্রদেয়তে (২১১১)। খাতটাই পার্থক্যটা
     * বলে, নামের দরকার ছিল না।
     */
    public static function drillSourceType(): string
    {
        return 'note';
    }

    /** ⓘ দাখিলা বসানোর সময় এই নামটাই যায় — [[NoteService::confirm()]] */
    public function sourceType(): string
    {
        return self::drillSourceType();
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->document_no;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['accounts.note.show', ['note' => $this->id]];
    }
}
