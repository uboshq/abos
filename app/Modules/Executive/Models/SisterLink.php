<?php

declare(strict_types=1);

namespace App\Modules\Executive\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "এই কোম্পানির এই পক্ষ = ঐ ভাই-কোম্পানি" — গ্রুপের মোট থেকে নিজেদের লেনদেন বাদ দেওয়ার একমাত্র উৎস।
 *
 * ⓘ অডিট হয়: একটা জোড়া বসানো বা তোলা গ্রুপের বিক্রির সংখ্যা বদলায়, তাই কে কখন করলেন জানা চাই।
 */
class SisterLink extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const CUSTOMER = 'customer';

    public const SUPPLIER = 'supplier';

    /** @var list<string> */
    public const PARTY_TYPES = [self::CUSTOMER, self::SUPPLIER];

    protected $table = 'executive_sister_links';

    protected $fillable = ['company_id', 'party_type', 'party_id', 'sister_company_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'party_id' => 'integer',
            'sister_company_id' => 'integer',
        ];
    }

    public function sister(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'sister_company_id');
    }
}
