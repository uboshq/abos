<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ পার্টিকে পাঠানোর খাতা — কে, কবে, কোন কাগজ, কোন নম্বরে, কোন ভাষায়, ঠিক কোন লেখা।
 *
 * ⚠️ একটা সারি মানে "WhatsApp খোলা হয়েছে, লেখা ও নম্বর বসানো" — শেষে পাঠান চাপা হয়েছে কি না ব্রাউজার জানে না,
 * তাই খাতাও তা দাবি করে না।
 */
class PartyMessage extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    // ⓘ খাতাও শাখার দেয়ালের ভিতরে — নাগালের বাইরের শাখার পার্টিকে কী গেল, তা দেখা যায় না
    use ScopedToUserBranch;

    // ── ⭐ state, step 3 (owner "ok" 23 Nov 2026, sample HUXqpfhxng8823eEFrvH54) ─────────────────────────
    /** ⓘ a wa.me button was pressed — the browser opened WhatsApp; whether "send" was tapped there is unknowable */
    public const OPENED = 'opened';

    public const WAITING = 'waiting';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    /** ⓘ only the Cloud API reports reading */
    public const READ = 'read';

    public const FAILED = 'failed';

    public const STATUSES = [self::OPENED, self::WAITING, self::SENT, self::DELIVERED, self::READ, self::FAILED];

    /** routes: the wa.me button, the Meta Cloud API, the office phone's SIM */
    public const WAME = 'wame';

    public const CLOUD = 'cloud';

    public const PHONE = 'phone';

    public const BUTTON = 'button';

    public const AUTO = 'auto';

    /** automatic rules */
    public const DUE_REMINDER = 'due_reminder';

    public const PAYMENT_RECEIVED = 'payment_received';

    /** ⓘ a due reminder is about the party's account, not one paper — its document_type */
    public const PARTY_ACCOUNT = 'party_account';

    protected $fillable = [
        'company_id', 'branch_id', 'channel', 'document_type', 'document_id', 'document_no',
        'party_type', 'party_id', 'party_name', 'phone', 'locale', 'template_id', 'body', 'share_id', 'sent_by',
        'status', 'route', 'via', 'rule', 'error', 'sent_at', 'delivered_at', 'read_at', 'provider_ref',
        'sms_outbox_id', 'fallback_of_id',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'document_id' => 'integer',
            'party_id' => 'integer',
            'sms_outbox_id' => 'integer',
            'fallback_of_id' => 'integer',
        ];
    }

    /** ⓘ the provider's ticks are not a person's change — the row's own columns are their history */
    public function auditIgnores(): array
    {
        return ['status', 'error', 'sent_at', 'delivered_at', 'read_at', 'provider_ref', 'sms_outbox_id'];
    }

    /**
     * ⭐ The one place a state moves — forward only: a late "delivered" never overwrites "read", and "failed" stays.
     */
    public function moveTo(string $status, ?string $error = null): void
    {
        $rank = [self::OPENED => 0, self::WAITING => 1, self::SENT => 2, self::DELIVERED => 3, self::READ => 4];

        if ($this->status === self::FAILED
            || ($status !== self::FAILED && ($rank[$status] ?? 0) <= ($rank[$this->status] ?? 0))) {
            return;
        }

        $now = now();
        $change = ['status' => $status];

        if ($status === self::FAILED) {
            $change['error'] = mb_substr((string) ($error ?: 'failed'), 0, 255);
        }
        if (in_array($status, [self::SENT, self::DELIVERED, self::READ], true)) {
            $change['sent_at'] = $this->sent_at ?? $now;
        }
        if (in_array($status, [self::DELIVERED, self::READ], true)) {
            $change['delivered_at'] = $this->delivered_at ?? $now;
        }
        if ($status === self::READ) {
            $change['read_at'] = $now;
        }

        $this->forceFill($change)->save();
    }

    /** @return BelongsTo<self, $this> */
    public function fallbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_of_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return BelongsTo<PartyMessageTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PartyMessageTemplate::class, 'template_id');
    }
}
