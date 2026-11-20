<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ যৌথ রোল — কয়েকটা রোল মিলিয়ে একটা (মালিকের অনুমোদিত রোলের পাতার নমুনা, ২০ নভেম্বর ২০২৬ "ok")।
 *
 * ⓘ একটা সারি = "এই রোলের ভেতরে ঐ রোল"। ডিপো ইনচার্জ = হিসাবরক্ষক + ম্যানেজার + নিরীক্ষক — আলাদা করে ষাটটা টিক নয়, তিনটা রোল।
 * ⓘ যৌথ রোলের অনুমতি ভেতরের রোলগুলোর অনুমতির যোগফল, আর সেটা যৌথ রোলেই বসানো থাকে ([[CompositeRoles::refresh()]]) — তাই
 *   অনুমতির প্রতিটা যাচাই (spatie, মেনু, নীতি) আগের মতোই চলে, এক লাইনও বদলায় না। ভেতরের রোল বদলালে যৌথটাও সাথে সাথে বদলায়।
 * ⛔ চক্র নিষেধ (ক-এর ভেতরে খ, খ-এর ভেতরে ক) — সংরক্ষণের আগে সেবা দেখে; আর মালিকের রোল কারও ভেতরে বসে না, ওটা যৌথও হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sys_role_includes')) {
            return;
        }

        Schema::create('sys_role_includes', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('included_role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'included_role_id'], 'sys_role_includes_once');
            $table->index(['company_id', 'included_role_id'], 'sys_role_includes_inner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_role_includes');
    }
};
