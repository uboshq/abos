<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * হাঁড়ি সকালে চড়ে, সারাদিন ওখান থেকে প্লেট যায়।
 *
 * ── কেন আলাদা একটা কাগজ লাগে ─────────────────────────────────────────
 * `to_order` রেসিপিতে বিক্রি আর রান্না একই মুহূর্ত, তাই বিলটাই যথেষ্ট।
 * হাঁড়ির বেলায় দুইটা আলাদা ঘটনা:
 *
 *   সকাল ৭টা — ১০ কেজি চাল আর ৪ কেজি মাংস হাঁড়িতে গেল, ৫০ প্লেট হলো
 *   সারাদিন — ওই ৫০ প্লেট থেকে একটা একটা করে বিক্রি
 *
 * প্রথমটার কোনো বিল নেই, কোনো গ্রাহক নেই। ওটা লেখার জায়গা না থাকলে
 * উপকরণ কমত না, আর তৈরি খাবারটাও গুদামে ঢুকত না।
 *
 * ── কেন এটা খতিয়ানে যায় না ───────────────────────────────────────────
 * চাল আর রান্না বিরিয়ানি দুইটাই মজুদ (১১২০)। এক হাত থেকে আরেক হাতে
 * টাকাটা যাচ্ছে, কিন্তু **একই খাতের ভেতরে** — মোট মজুদের মান বদলায় না।
 *
 * দাখিলা লিখলে ওটা হত Dr ১১২০ / Cr ১১২০, অর্থাৎ শূন্য — খতিয়ানে
 * অর্থহীন সারি, আর ট্রায়াল ব্যালান্সে কিছুই যোগ হত না।
 *
 * নষ্ট হলে গল্পটা আলাদা: তখন মজুদ কমে আর খরচ বাড়ে। কিন্তু ওটা এই
 * কাগজের কাজ নয় — ওটা মজুদ সমন্বয় (`StockAdjustmentService`), আর ওখানে
 * কারণ-কোড ও খতিয়ানের ব্যবস্থা আগে থেকেই আছে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_productions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()->constrained()->nullOnDelete();

            $table->string('document_no', 40);

            /*
             * কোন রেসিপি ধরে রান্না।
             *
             * ── কেন রেসিপির আইডি রাখা হয়, কেবল পণ্যের নয় ────────────
             * রেসিপি বদলায় — আজ ৫০ প্লেটে ১০ কেজি চাল, ছয় মাস পরে ৯।
             * কোন কাগজটা কোন নিয়মে রান্না হয়েছিল, সেটা পরে জানতে হলে
             * রেসিপিটাই ধরে রাখতে হয়।
             *
             * রেসিপি নিষ্ক্রিয় হলেও পুরনো কাগজ তার দিকেই দেখায়, আর সে
             * জন্যই রেসিপি মোছা হয় না, নিষ্ক্রিয় হয়।
             */
            $table->foreignId('recipe_id')->constrained('inv_recipes');

            // যা তৈরি হলো — রেসিপি থেকেই আসে, তবু এখানে রাখা: রিপোর্ট
            // ও তালিকা এটাকেই দেখায়, আর প্রতিবার রেসিপি ছুঁতে হয় না
            $table->foreignId('product_id')->constrained('inv_products');
            $table->foreignId('warehouse_id')->constrained('inv_warehouses');

            $table->date('trx_date');

            /*
             * কয়টা হলো — **ফলন নয়, বাস্তব**।
             *
             * রেসিপিতে লেখা ৫০, কিন্তু আজ হয়েছে ৪৭। তখন উপকরণও ৪৭/৫০
             * অনুপাতে কমবে। বাস্তবের ঘরটা না রাখলে খাতা রোজ ৫০ ধরত,
             * আর তিনটা প্লেটের হিসাব প্রতিদিন হারাত।
             */
            $table->decimal('qty', 18, 4);

            /*
             * উপকরণে মোট কত টাকার মাল গেল — FIFO স্তর থেকে।
             *
             * এটাই তৈরি খাবারের স্তরের দর হবে (÷ পরিমাণ)। আলাদা করে
             * হিসাব করা হয় না; নিশ্চিত করার সময় যা সত্যিই বেরিয়েছে,
             * সেটাই এখানে বসে।
             */
            $table->decimal('cost_total', 18, 4)->default('0');

            $table->string('status', 16)->default('draft');
            $table->text('narration')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no'], 'production_no_unique');
            $table->index(['company_id', 'trx_date'], 'production_by_date');
        });

        Schema::create('inv_production_lines', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('production_id')->constrained('inv_productions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products');

            /*
             * সত্যিই যতটা বেরিয়েছে, আর তার দর।
             *
             * ── কেন লেখা হয়, রেসিপি থেকে বারবার হিসাব করা হয় না ─────
             * রেসিপি কাল বদলাতে পারে। কাগজটা যদি প্রতিবার রেসিপি ধরে
             * হিসাব করত, তবে পুরনো রান্নার খরচ আজকের নিয়মে বদলে যেত —
             * অর্থাৎ **ইতিহাস পিছিয়ে গিয়ে বদলাত**।
             *
             * একই কারণে দরটাও লেখা: FIFO স্তর ফুরিয়ে যায়, আর তখন
             * "ওই দিন কত দরে গিয়েছিল" আর বের করা যেত না।
             */
            $table->decimal('qty', 18, 4);
            $table->decimal('cost', 18, 4)->default('0');

            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_production_lines');
        Schema::dropIfExists('inv_productions');
    }
};
