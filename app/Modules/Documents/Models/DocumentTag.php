<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * প্রশাসনের ঠিক করা একটা ট্যাগ — তোলার ফর্মে পরামর্শ হিসেবে আসে (§২০ Tags)।
 *
 * ⓘ কাগজের ট্যাগ আগের মতোই কাগজের সারিতে কমা দিয়ে লেখা; এই তালিকা কেবল বানান এক রাখে,
 * যাতে "ভাড়া" আর "ভাড়া " দুই ট্যাগ না হয়।
 */
class DocumentTag extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'dms_tags';

    protected $fillable = ['company_id', 'name', 'created_by', 'updated_by'];

    /** ⛔ ফাঁকা নতুন সারিতেও ঘরটা থাকে — নাহলে `->name` পড়তে Eloquent `name()`-কে সম্পর্ক ভাবত */
    protected $attributes = ['name' => null];

    public function auditIgnores(): array
    {
        return ['updated_by'];
    }

    public function name(): string
    {
        return (string) ($this->attributes['name'] ?? '');
    }
}
