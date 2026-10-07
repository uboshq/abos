<?php

declare(strict_types=1);

use App\Modules\MasterData\Models\PartyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * গ্রাহকের ধরন মুক্ত লেখা থেকে মাস্টার তালিকায়।
 *
 * customer_type ছিল একটা সাধারণ string — কেউ "খুচরা", কেউ "খুচরা ", কেউ
 * "Retail" লিখত, আর "কোন ধরনের গ্রাহক সবচেয়ে বেশি" প্রশ্নের উত্তর
 * কখনো বের করা যেত না।
 *
 * Master Data আসার পর PartyType আছে, তাই এখন সেটাই। পুরনো লেখাগুলো
 * নাম মিলিয়ে জোড়া হয় — না মিললে ধরনটা খালি হয়ে যায় বলে হারায় না:
 * পুরনো কলামটা রেখে দেওয়া হয়, যাতে কেউ পরে হাতে মিলিয়ে দিতে পারে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('party_type_id')->nullable()->after('customer_type')
                ->constrained('mdm_party_types')->nullOnDelete();
        });

        $this->matchExistingTypes();
    }

    /**
     * পুরনো লেখাগুলো নাম মিলিয়ে জোড়া দেওয়া।
     *
     * কোম্পানি ধরে ধরে, কারণ দুই কোম্পানির "খুচরা" দুইটা আলাদা সারি।
     * মিল খোঁজা হয় বাংলা ও ইংরেজি দুই নামেই, আর বড়-ছোট হরফ উপেক্ষা
     * করে — কেউ "retail" লিখলে "Retail"-এর সাথেই মেলা উচিত।
     */
    private function matchExistingTypes(): void
    {
        if (! Schema::hasTable('mdm_party_types')) {
            return;
        }

        $types = DB::table('mdm_party_types')
            ->select('id', 'company_id', 'name_en', 'name_bn')
            ->get();

        foreach ($types as $type) {
            DB::table('customers')
                ->where('company_id', $type->company_id)
                ->whereNull('party_type_id')
                ->whereNotNull('customer_type')
                ->where(function ($q) use ($type) {
                    $q->whereRaw('LOWER(TRIM(customer_type)) = ?', [mb_strtolower(trim((string) $type->name_en))]);

                    if (filled($type->name_bn)) {
                        $q->orWhereRaw('TRIM(customer_type) = ?', [trim((string) $type->name_bn)]);
                    }
                })
                ->update(['party_type_id' => $type->id]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('party_type_id');
        });
    }
};
