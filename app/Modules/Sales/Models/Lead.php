<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * লিড — এখনো গ্রাহক নন, কিন্তু হতে পারেন।
 *
 * ── কেন গ্রাহকের তালিকায় নয় ────────────────────────────────────────────
 * গ্রাহকের সারি মানে খাতায় প্রাপ্যের হিসাব, বাকির সীমা, কোড। ⚠️ প্রতিটা
 * "একবার কথা হয়েছিল" দোকানকে গ্রাহক বানালে গ্রাহক তালিকা অর্ধেক ভূত
 * হত, আর বকেয়া-তালিকা ও ডিলার-গোনা ভুল বলত। ⓘ তাই লিড আলাদা, আর
 * গ্রাহক হয় কেবল একবার, [[LeadService::convert()]] দিয়ে।
 */
class Lead extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_leads';

    public const NEW = 'new';

    public const CONTACTED = 'contacted';

    public const QUALIFIED = 'qualified';

    public const LOST = 'lost';

    public const CONVERTED = 'converted';

    /**
     * হাতে বসানো যায় যে অবস্থাগুলো।
     *
     * ⛔ `converted` এখানে নেই, ইচ্ছাকৃত: ওটা বসে কেবল গ্রাহক তৈরির
     * সাথে। হাতে বসানো গেলে লিডটা "গ্রাহক হয়েছে" বলত অথচ কোনো গ্রাহক
     * নেই — আর রূপান্তরের দরজাটাও বন্ধ হয়ে যেত।
     *
     * @var list<string>
     */
    public const SETTABLE = [self::NEW, self::CONTACTED, self::QUALIFIED, self::LOST];

    /** @var list<string> */
    public const STATUSES = [self::NEW, self::CONTACTED, self::QUALIFIED, self::LOST, self::CONVERTED];

    /** @var list<string> */
    public const SOURCES = ['field_visit', 'referral', 'phone_call', 'walk_in', 'other'];

    protected $fillable = [
        'company_id', 'document_no', 'name', 'contact_person', 'phone', 'address',
        'location_id', 'source', 'owner_user_id', 'status', 'lost_reason', 'notes',
        'customer_id', 'converted_at', 'converted_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function isConverted(): bool
    {
        return $this->status === self::CONVERTED || $this->customer_id !== null;
    }

    /**
     * ⭐ দেখার দেয়াল — মালিকের নিয়ম, ২৬ সেপ্টেম্বর ২০২৬।
     *
     * *বিক্রয়কর্মী কেবল নিজেরটা দেখেন।* ⓘ সবার-দেখার চাবি
     * (`sales.lead.manage`) থাকলে দেয়াল নেই। ⚠️ তালিকা, পাতা, সম্পাদনা,
     * রূপান্তর, সুযোগের লিড-বাছাই — সব দরজা এই একটা স্কোপ দিয়েই যায়,
     * যাতে কোনো দরজা নিজের আলাদা নিয়ম না বানায়।
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('sales.lead.manage')) {
            return $query;
        }

        return $query->where('owner_user_id', $user->id);
    }
}
