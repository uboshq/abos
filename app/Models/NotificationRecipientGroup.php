<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ নাম দেওয়া প্রাপক-দল — মানুষ, রোল, শাখা, বিভাগ (মালিকের স্পেক §৪ "Recipient Management"; ধাপ ৩)।
 * ⓘ সদস্য গোনা হয় পাঠানোর সময় ([[RecipientResolver]]) — আজ যিনি রোলে আছেন তিনিই পান।
 */
class NotificationRecipientGroup extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** @var list<string> সদস্যের ধরন */
    public const KINDS = ['users', 'roles', 'branches', 'departments'];

    protected $fillable = ['company_id', 'name', 'members', 'is_active'];

    protected function casts(): array
    {
        return [
            'members' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return list<int> এক ধরনের সদস্যের আইডি */
    public function ids(string $kind): array
    {
        return array_values(array_map('intval', (array) (($this->members ?? [])[$kind] ?? [])));
    }
}
