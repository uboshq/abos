<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\Customer\Support\ConductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * পার্টির একটা আচরণ-নোট — একটা ধরন, কে লিখল, আর কবে।
 *
 * নাম ও গুরুত্ব কলামে জমা নয় — `type` কোড থেকে [[ConductType]] বলে দেয়।
 * তাই তালিকার লেবেল বদলালেও পুরনো সারি ভুল হয় না, আর একই অভ্যাস সব
 * ডিপোতে এক থাকে।
 */
class CustomerConduct extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'customer_conduct_notes';

    protected $fillable = [
        'company_id', 'customer_id', 'type', 'note', 'is_active',
        'recorded_by', 'recorded_at', 'retired_by', 'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'recorded_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function retirer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
    }

    /** চলমান পতাকা — নামানো নয়। */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** ব্যবহারকারীর ভাষায় ধরনের নাম। */
    public function label(?string $locale = null): string
    {
        return ConductType::label($this->type, $locale);
    }

    /** গুরুত্ব — ধরন থেকে গোনা, সংরক্ষিত নয়: good | notice | risk। */
    public function severity(): string
    {
        return ConductType::severityOf($this->type);
    }
}
