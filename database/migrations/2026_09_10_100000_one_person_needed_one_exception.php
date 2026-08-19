<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একজনের জন্য একটা ব্যতিক্রম।
 *
 * ── কী করা যেত না ────────────────────────────────────────────────────
 * অনুমতি বসে রোল ধরে। কিন্তু বাস্তবে প্রায়ই একজনের জন্য একটা ব্যতিক্রম
 * লাগে: হিসাবরক্ষকদের মধ্যে **একজনকে** পুরনো বিল বসানোর দায়িত্ব দেওয়া
 * হয়েছে, নয়তো একজন বিক্রয়কর্মীর কাছ থেকে ছাড় দেওয়ার ক্ষমতা কিছুদিনের
 * জন্য তুলে নিতে হবে।
 *
 * **দেওয়াটা** Spatie আগে থেকেই পারে — ব্যবহারকারীর গায়ে সরাসরি অনুমতি
 * বসানো যায়। **কেড়ে নেওয়াটা পারে না**: রোল যেটা দিয়েছে সেটা একজনের
 * কাছ থেকে তুলে নেওয়ার কোনো উপায় নেই।
 *
 * ফলে আজ একজনের একটা ক্ষমতা কাড়তে হলে **তাঁর জন্য আস্ত একটা নতুন রোল**
 * বানাতে হয়। তিনজনের তিনটা ব্যতিক্রম মানে তিনটা রোল, আর ছয় মাস পরে
 * কেউ বলতে পারে না কোন রোলটা কেন আছে।
 *
 * ── কেন রোলের অনুমতির সাথে মিশিয়ে দেওয়া হয় না ───────────────────────
 * ব্যতিক্রমটা আলাদা রাখলে রোল বদলালেও সেটা মুছে যায় না, আর পর্দা
 * স্পষ্ট করে বলতে পারে **এই মানুষটা একটা ব্যতিক্রম** — কোনটা রোলের আর
 * কোনটা তাঁর নিজের, দুইটা আলাদা করে দেখা যায়।
 *
 * ── কেন "না" জেতে ────────────────────────────────────────────────────
 * `granted = false` রোলের দেওয়া অনুমতিকেও হারায়। উল্টোটা হলে কেড়ে
 * নেওয়া যেতই না — আর তখন এই টেবিলটার অর্ধেক কাজই থাকত না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('permission', 128);
            $table->boolean('granted');

            /*
             * কারণটা বাধ্যতামূলক নয়, কিন্তু চাওয়া হয়।
             *
             * ছয় মাস পরে "এই মানুষটার এই ব্যতিক্রমটা কেন" প্রশ্নের উত্তর
             * কেবল এখানেই থাকে — অডিটে কে বসিয়েছেন তা লেখা থাকে, কেন
             * তা নয়।
             */
            $table->string('reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * একজনের একটা অনুমতির জন্য একটাই সারি।
             *
             * দুইটা থাকলে একটা "দাও" আর একটা "নিও না" হতে পারত, আর তখন
             * কোনটা মানা হবে সেটা সারির ক্রমের উপর নির্ভর করত — অর্থাৎ
             * উত্তরটা নির্ভরযোগ্য থাকত না।
             */
            $table->unique(['company_id', 'user_id', 'permission'], 'user_permission_override_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
