<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * কোম্পানির নিজের যোগ করা একটা ঘর।
 *
 * চাবিটা স্থির, লেবেলটা নয় — লেবেল বদলালে পুরনো মানগুলো ঠিকই থাকে,
 * কিন্তু চাবি বদলালে ওগুলো কোন ঘরের তা আর বলা যেত না।
 */
class CustomField extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'company_id', 'entity', 'key', 'label_en', 'label_bn',
        'type', 'options', 'is_required', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /** ব্যবহারকারীর ভাষায় ঘরের নাম। */
    public function label(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $locale === 'bn'
            ? ($this->label_bn ?: $this->label_en)
            : ($this->label_en ?: $this->label_bn);
    }

    /**
     * বাছাইয়ের বিকল্পগুলো — সবসময় একটা তালিকা।
     *
     * @return list<string>
     */
    public function optionList(): array
    {
        return array_values(array_filter(
            array_map(
                fn ($option) => is_string($option) ? trim($option) : '',
                $this->options ?? [],
            ),
            fn (string $option) => $option !== '',
        ));
    }
}
