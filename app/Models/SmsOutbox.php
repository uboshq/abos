<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ SMS-এর সারি — পাঠানোর অপেক্ষায় থাকা প্রতিটা SMS, আর তার পরে কী হলো (নিল, গেছে, পৌঁছেছে, যায়নি)।
 *
 * ⓘ অবস্থা: waiting (ফোনের অপেক্ষায়) → taken (ফোন নিয়েছে) → sent (সিম থেকে বেরিয়েছে) বা failed (যায়নি)।
 *   পৌঁছানো আলাদা ঘর (`delivered_at`) — অপারেটর খবর দিলে তবেই।
 * ⓘ নেওয়ার পর [[TAKEN_MINUTES]]-এ খবর না এলে আবার waiting — ফোন মাঝপথে বন্ধ হলে SMS হারায় না।
 */
class SmsOutbox extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const WAITING = 'waiting';

    public const TAKEN = 'taken';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const STATUSES = [self::WAITING, self::TAKEN, self::SENT, self::FAILED];

    public const NOTIFICATION = 'notification';

    public const PARTY = 'party';

    /** ⓘ ফোন নিয়ে এর মধ্যে খবর না দিলে আবার সারিতে */
    public const TAKEN_MINUTES = 10;

    /** ⓘ এতবার নিয়েও খবর না এলে আর নয় — "যায়নি" */
    public const MAX_ATTEMPTS = 3;

    /** ⓘ এর বেশি অপেক্ষায় থাকলে মালিককে খবর (নমুনা: "২ ঘণ্টার বেশি হলে মালিককে খবর") */
    public const WARN_MINUTES = 120;

    protected $table = 'sms_outbox';

    protected $fillable = [
        'company_id', 'phone', 'body', 'source', 'source_id', 'status', 'attempts', 'gateway_phone_id',
        'taken_at', 'sent_at', 'delivered_at', 'error', 'warned_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'taken_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'warned_at' => 'datetime',
        ];
    }

    public function gatewayPhone(): BelongsTo
    {
        return $this->belongsTo(SmsGatewayPhone::class, 'gateway_phone_id');
    }

    /** ⓘ লেখার কয়টা SMS ভাগ — বাংলা (ইউনিকোড) 70/67, ইংরেজি 160/153 অক্ষরে */
    public static function parts(string $text): int
    {
        $unicode = preg_match('/[^\x00-\x7F]/u', $text) === 1;
        $length = mb_strlen($text);
        [$single, $multi] = $unicode ? [70, 67] : [160, 153];

        return $length <= $single ? 1 : (int) ceil($length / $multi);
    }
}
