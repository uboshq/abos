<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * নির্ধারিত রিপোর্ট একবার চললে ফাইলটা কোথাও বসে — এই টেবিল সেই জায়গা।
 *
 * প্রতিটা সারি একটা চালানোর ফল: কোন সূচি, কোন ফাইল, কয় সারি, কখন। ফাইলটা
 * private ডিস্কে (URL অনুমান করে অন্য কোম্পানির কেউ যেন না পায়); এই সারির
 * অনুমতি-যাচাই করা download-পথ দিয়েই কেবল নামানো যায়।
 *
 * ইতিহাস রাখা হয় (শুধু শেষ ফাইল নয়): "গত সোমবার কী পাঠানো হয়েছিল" প্রশ্নের
 * উত্তর নাহলে হারিয়ে যেত, আর নির্ধারিত রিপোর্টে ঠিক ওই প্রশ্নটাই আসে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('report_schedule_id')->constrained('report_schedules')->cascadeOnDelete();

            $table->string('format', 8);
            // private ডিস্কের সাপেক্ষে পথ — asset() নয়, download-রুট দিয়ে
            $table->string('file_path', 500)->nullable();

            /*
             * ⚠️ কারা এই ফাইলটা নামাতে পারবেন — রেন্ডারের মুহূর্তের ছবি।
             *
             * সূচির প্রাপক-তালিকা পরে বদলাতে পারে। কেউ নতুন প্রাপক যোগ
             * করলে পুরনো ফাইলটা আগের (হয়তো কম-সীমিত) নিয়মে তৈরি — তাই
             * নতুন প্রাপক ওটা নামালে পর্দায় না-দেখা কলাম পেয়ে যেতেন।
             * ফাইলটা কার জন্য তৈরি হয়েছিল, সেই তালিকাই এখানে জমে; download
             * এই ছবিটা ধরে যাচাই করে, সূচির চলতি তালিকা নয়।
             */
            $table->json('recipients')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            // ok · empty · error
            $table->string('status', 32)->default('ok');
            $table->timestamp('ran_at');

            $table->timestamps();

            $table->index(['company_id', 'report_schedule_id', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
