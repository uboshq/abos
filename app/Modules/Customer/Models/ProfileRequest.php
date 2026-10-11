<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserDealers;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ পোর্টাল থেকে গ্রাহকের একটা অনুরোধ — তথ্য বদল, বা একটা কাগজ (গ্রাহক পোর্টাল, ধাপ ১, ১১ অক্টোবর ২০২৬)।
 *
 * ⛔ অনুরোধ নিজে গ্রাহকের রেকর্ড বদলায় না — মালিকের নিয়ম। অফিস দেখে নিজের পর্দায় বসায়, তারপর "হয়েছে" বা
 * "হলো না" (কারণসহ) চিহ্ন দেয় ([[ProfileRequestService::decide()]])।
 */
class ProfileRequest extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    // ⓘ বিক্রয়কর্মী কেবল নিজের বাঁধা গ্রাহকের অনুরোধ দেখেন — গ্রাহকের পাতার সেই একই দেয়াল ([[DealerScope]])
    use ScopedToUserDealers;

    public const CONTACT = 'contact';

    public const PAPER = 'paper';

    public const SUBMITTED = 'submitted';

    public const DONE = 'done';

    public const REFUSED = 'refused';

    /** @var list<string> গ্রাহক যে তথ্যগুলো বদলাতে চাইতে পারেন — নাম আর কোড নয়, ওগুলো অফিসের */
    public const CONTACT_FIELDS = ['owner_name', 'phone', 'email', 'address_bn', 'address_en'];

    /** @var list<string> কাগজের ধরন */
    public const PAPER_KINDS = ['trade_licence', 'nid', 'bin'];

    protected $table = 'customer_profile_requests';

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'kind', 'paper_kind',
        'asked', 'was', 'note', 'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'asked' => 'array',
            'was' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::SUBMITTED);
    }

    public function isOpen(): bool
    {
        return $this->status === self::SUBMITTED;
    }
}
