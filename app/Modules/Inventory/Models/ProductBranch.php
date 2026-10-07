<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * পণ্যটা কোন শাখায় বিক্রি হয় — [[Product::branches()]]-এর এক সারি (৬ অক্টোবর ২০২৬)।
 *
 * ⓘ আলাদা মডেল কেবল একটা কারণে: প্রতিটা সারির বাইরের নাম (`public_id`) জন্মের মুহূর্তেই বসে ([[HasPublicId]])।
 * ⛔ `sync()`-এ নিজের হাতে uuid পাঠালে আগের সারির নামও প্রতিবার বদলে যেত — `sync()` থাকা সারির ঘরও নতুন করে লেখে।
 * কাস্টম পিভটে `attach()` মডেল দিয়ে বসায়, তাই `creating` কেবল নতুন সারিতে চলে।
 */
class ProductBranch extends Pivot
{
    use BelongsToCompany;
    use HasPublicId;
    // ⓘ কোন পণ্য কোন শাখায় বিক্রি হয় — কে কবে বদলাল, অডিটে থাকে (EveryChangeableRowRemembersWhoChangedItTest)
    use IsAudited;

    protected $table = 'inv_product_branches';

    public $incrementing = true;
}
