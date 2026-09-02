<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * এক হাঁড়ির রান্নার কাগজ।
 *
 * ── কেন যাচাইটা সংক্ষিপ্ত ────────────────────────────────────────────
 * ভারী কাজটা `ProductionService` করে — রেসিপি রান্নাযোগ্য কি না, গুদামে
 * উপকরণ আছে কি না। এখানে কেবল আকারটা দেখা হয়: সংখ্যা কি সংখ্যা, আইডি
 * কি এই কোম্পানির।
 *
 * দুই জায়গায় একই নিয়ম লিখলে একদিন একটা বদলাত আর অন্যটা থেকে যেত, আর
 * তখন পর্দা এক কথা বলত সেবা আরেক।
 */
class ProductionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->user()->current_company_id;

        return [
            'recipe_id' => [
                'required', 'integer',
                Rule::exists('inv_recipes', 'id')
                    ->where('company_id', $company)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],

            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $company),
            ],

            'trx_date' => ['required', 'date', 'before_or_equal:today'],

            /*
             * কয় প্লেট হলো — শূন্যের বেশি হতেই হবে।
             *
             * শূন্য হলে কোনো উপকরণও কমত না আর কোনো খাবারও ঢুকত না;
             * কাগজটা তখন কেবল একটা খালি সারি, যা পরে কেউ দেখে ভাবতেন
             * রান্না হয়েছিল।
             */
            'qty' => ['required', 'numeric', 'gt:0'],

            'narration' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
