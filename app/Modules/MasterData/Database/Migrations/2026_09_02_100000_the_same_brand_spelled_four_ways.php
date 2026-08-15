<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * একই ব্র্যান্ড চার বানানে।
 *
 * ── আজ যা ঘটে ────────────────────────────────────────────────────────
 * `inv_products.brand` ও `.category` — দুইটাই ১২০ অক্ষরের মুক্ত লেখা।
 * পণ্যের ফর্মে টাইপ করা হয়, CSV ইমপোর্টেও ওভাবেই আসে। ফলে একই ব্র্যান্ড
 * কয়েক বানানে বসে: "Nestle", "nestle", "Nestlé", "নেসলে"।
 *
 * রোজকার কাজে কেউ টের পায় না — পণ্যের পাতায় লেখাটা ঠিকই দেখায়। টের
 * পাওয়া যেত ঠিক যেদিন "ব্র্যান্ড ধরে বিক্রয়" খোলা হত: এক ব্র্যান্ড চার
 * সারিতে ভাগ, প্রতিটার অঙ্ক আসলের এক-চতুর্থাংশ, আর কোনো সারিই সত্যি নয়।
 * তারপর সেই তালিকা দেখেই কেউ ঠিক করত কোন ব্র্যান্ড রাখা হবে, কোনটা বাদ।
 *
 * তাই রিপোর্টটার আগে এই কাজটা — নাহলে রিপোর্টটাই ভুল সংখ্যা ছাপত, আর
 * ভুল বলে চেনার উপায় থাকত না।
 *
 * ── কেন সারি, enum নয় ────────────────────────────────────────────────
 * প্রতিটা কোম্পানির তালিকা আলাদা, আর নতুন ব্র্যান্ড যোগ করা রোজকার কাজ।
 * কোডে লিখলে প্রতিবার ডেভেলপার লাগত (মালিকের স্থায়ী নিয়ম)।
 *
 * ── পুরনো লেখাগুলো হারায় না ──────────────────────────────────────────
 * যা যা লেখা আছে সবগুলো সারি হয়ে বসে, আর পণ্যগুলো ওই সারিতেই বাঁধা
 * পড়ে। বানানভেদগুলো এখানে **জোড়া লাগানো হয় না**: "Nestle" আর "নেসলে"
 * এক জিনিস কি না সেটা মানুষের সিদ্ধান্ত, মাইগ্রেশনের নয়। সবগুলো সারি
 * হয়ে বসে, আর মালিক সেটিংস থেকে বাড়তিগুলো নিষ্ক্রিয় করে পণ্যগুলো এক
 * সারিতে আনতে পারেন — সেটাই সঠিক ক্রম, কারণ ভুল জোড়া লাগালে ফেরানোর
 * উপায় থাকত না।
 *
 * পুরনো লেখার ঘরদুটো রেখেই দেওয়া হয়েছে (`brand`, `category`) — মুছে
 * ফেললে জোড়া লাগানোর সময় আসল বানানটা আর দেখা যেত না।
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['mdm_brands', 'mdm_product_categories'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();

                $blueprint->string('code', 32);
                $blueprint->string('name_en', 120);
                $blueprint->string('name_bn', 120);

                $blueprint->boolean('is_active')->default(true);
                $blueprint->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

                $blueprint->uuid('public_id')->unique();
                $blueprint->timestamps();
                $blueprint->softDeletes();

                $blueprint->unique(['company_id', 'code']);
            });
        }

        Schema::table('inv_products', function (Blueprint $table): void {
            /*
             * `nullOnDelete` — সারিটা মুছলে পণ্য শ্রেণিহীন হয়, উধাও হয় না।
             *
             * ব্র্যান্ড বা শ্রেণি পণ্যের পরিচয়ের অংশ, তার অস্তিত্বের শর্ত
             * নয়। `restrictOnDelete` হলে ব্যবহৃত একটা ব্র্যান্ড কোনোদিন
             * মোছা যেত না, আর `cascade` হলে ব্র্যান্ড মুছলে পণ্যগুলোই
             * চলে যেত — দ্বিতীয়টা ভয়ংকর।
             */
            $table->foreignId('brand_id')->nullable()->after('brand')
                ->constrained('mdm_brands')->nullOnDelete();

            $table->foreignId('category_id')->nullable()->after('category')
                ->constrained('mdm_product_categories')->nullOnDelete();
        });

        $this->fold('brand', 'mdm_brands', 'brand_id');
        $this->fold('category', 'mdm_product_categories', 'category_id');
    }

    /**
     * পুরনো লেখাগুলো সারি বানিয়ে পণ্যগুলোকে ওখানে বাঁধা।
     *
     * কোম্পানি ধরে ধরে, কারণ একই বানান দুই কোম্পানিতে থাকতেই পারে আর
     * তারা আলাদা সারি — এক কোম্পানির তালিকা সম্পাদনা অন্যের তালিকা
     * বদলাতে পারে না।
     */
    private function fold(string $column, string $table, string $foreignKey): void
    {
        $values = DB::table('inv_products')
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->select(['company_id', $column])
            ->distinct()
            ->get();

        foreach ($values as $row) {
            $name = trim((string) $row->{$column});

            if ($name === '') {
                continue;
            }

            /*
             * কোডটা নামের থেকে তৈরি, আর ধাক্কা লাগলে সংখ্যা বসে।
             *
             * "Nestle" ও "nestle" দুইটাই `NESTLE` হতে চাইত; দ্বিতীয়টা
             * `NESTLE-2` হয়। ইচ্ছাকৃত: এখানে জোড়া লাগানো হয় না, কারণ
             * ওই সিদ্ধান্তটা মানুষের।
             */
            $base = Str::upper(Str::slug($name, '-')) ?: 'X';
            $base = Str::limit($base, 28, '');
            $code = $base;
            $n = 1;

            while (DB::table($table)->where('company_id', $row->company_id)->where('code', $code)->exists()) {
                $code = $base.'-'.(++$n);
            }

            $id = DB::table($table)->insertGetId([
                'company_id' => $row->company_id,
                'code' => $code,
                'name_en' => $name,
                'name_bn' => $name,
                'is_active' => true,
                'public_id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('inv_products')
                ->where('company_id', $row->company_id)
                ->where($column, $row->{$column})
                ->update([$foreignKey => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('inv_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('mdm_brands');
        Schema::dropIfExists('mdm_product_categories');
    }
};
