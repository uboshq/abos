<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Recipe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * একটা রেসিপি সংরক্ষণের নিয়ম।
 *
 * ── কেন যাচাইগুলো এত কড়া ─────────────────────────────────────────────
 * এই ফর্মের একটা ভুল সংখ্যা সরাসরি গুদামের হিসাবে যায়। "৫" আর "৫০"-এর
 * তফাত মাসের শেষে দশ বস্তা চাল, আর ভুলটা নীরব — বিল ছাপে, টাকা আসে,
 * কোথাও লাল দেখায় না।
 *
 * তাই যা ঠেকানো যায় সব এখানেই ঠেকানো হয়, ডাটাবেজে পৌঁছানোর আগে।
 */
class RecipeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $recipe = $this->route('recipe');

        return [
            /*
             * কোন খাবারের রেসিপি — আর এক পণ্যের একটাই।
             *
             * একাধিক রাখলে "কোনটা দিয়ে কমবে" প্রশ্নের উত্তর কোডকে
             * আন্দাজে ঠিক করতে হত। সম্পাদনার সময় নিজেকে বাদ দেওয়া
             * হয়, নাহলে নিজের সাথেই সংঘাত বাধত।
             */
            'product_id' => [
                'required', 'integer',
                Rule::exists('inv_products', 'id')->where('company_id', $this->user()->current_company_id),
                Rule::unique('inv_recipes', 'product_id')
                    ->where('company_id', $this->user()->current_company_id)
                    ->whereNull('deleted_at')
                    ->ignore($recipe?->id),
            ],

            'kind' => ['required', Rule::in(Recipe::KINDS)],

            /*
             * ফলন শূন্যের বেশি হতেই হবে।
             *
             * শূন্য হলে "এক প্লেটে কতটা" ভাগ করা যায় না। মডেল ওই
             * অবস্থায় কিছুই কমায় না — অর্থাৎ পাতা ভাঙে না, কিন্তু
             * রেসিপিটা নীরবে অকেজো হয়ে বসে থাকে। এখানেই ঠেকানো ভালো।
             */
            'yield_qty' => ['required', 'numeric', 'gt:0'],

            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],

            /*
             * অন্তত একটা উপকরণ।
             *
             * উপকরণহীন রেসিপি মানে বিক্রিতে কিছুই কমে না — ঠিক সেই
             * নীরব ভুলটা যেটা ঠেকাতে এই ফিচারটা বানানো। বিক্রির পথেও
             * পাহারা আছে, কিন্তু সেখানে বাধা পাওয়ার চেয়ে এখানে না
             * বানাতে পারাই ভালো।
             */
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('inv_products', 'id')->where('company_id', $this->user()->current_company_id),
                /*
                 * খাবার নিজেই নিজের উপকরণ হতে পারে না।
                 *
                 * হলে বিক্রির সময় বিরিয়ানি কমাতে গিয়ে আবার বিরিয়ানিই
                 * চাওয়া হত — অসীম চক্র, আর স্টক পাগলের মতো নামত।
                 */
                Rule::notIn([$this->input('product_id')]),
            ],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],

            /*
             * অপচয় ০ থেকে ১০০-এর নিচে।
             *
             * ১০০% মানে কিছুই টেকে না — অঙ্কটা তখন শূন্য দিয়ে ভাগ।
             * মডেল ওটা সামলায়, কিন্তু ওখানে পৌঁছানোর আগেই আটকানো ভালো:
             * ১০০ লিখে সংরক্ষণ করা গেলে ব্যবহারকারী ভাবতেন সংখ্যাটা
             * মানা হচ্ছে।
             */
            'lines.*.waste_pct' => ['nullable', 'numeric', 'min:0', 'lt:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => __('inventory::validation.recipe_needs_lines'),
            'lines.min' => __('inventory::validation.recipe_needs_lines'),
            'lines.*.product_id.not_in' => __('inventory::validation.recipe_self_reference'),
            'lines.*.product_id.distinct' => __('inventory::validation.recipe_duplicate_line'),
            'lines.*.waste_pct.lt' => __('inventory::validation.recipe_waste_too_high'),
        ];
    }
}
