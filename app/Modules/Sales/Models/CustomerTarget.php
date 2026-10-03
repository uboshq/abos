<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একজন ডিলারের এক মাসের আদায়ের লক্ষ্য — [[CustomerTargetService]] লেখে আর পড়ে।
 *
 * ⓘ অর্জন এখানে থাকে না; প্রতিবার খাতা থেকে গোনা হয়।
 */
final class CustomerTarget extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_customer_targets';

    protected $fillable = ['company_id', 'customer_id', 'month', 'amount', 'closes_on', 'created_by'];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'amount' => 'decimal:4',
            'closes_on' => 'date',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
