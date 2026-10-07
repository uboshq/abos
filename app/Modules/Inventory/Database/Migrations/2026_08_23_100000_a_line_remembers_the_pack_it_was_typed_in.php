<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * লাইনটা মনে রাখে কোন প্যাকে লেখা হয়েছিল।
 *
 * ── কেন qty বদলায় না ─────────────────────────────────────────────────
 * `qty` আগের মতোই পণ্যের নিজের এককে থাকে, আর মজুদ-দর-লাভ সব ওটাই
 * পড়ে। রূপান্তরটা এন্ট্রির মুখেই হয়ে যায়, তাই নিচের কোনো হিসাব একক
 * নিয়ে ভাবে না — একটা জায়গায় গুণ বাদ পড়লে ১০ পাতা আর ১০ বাক্স এক
 * হয়ে যেত, আর মজুদ নীরবে ৯০ পিস নড়ে বসত।
 *
 * ── তাহলে এই দুইটা ঘর কেন ────────────────────────────────────────────
 * শুধু ছাপা আর পর্দার জন্য। ক্রেতা "২ বাক্স" চেয়েছেন, চালানে "২০০ পিস"
 * ছাপা হলে তিনি মিলিয়ে দেখতে পারতেন না, আর দোকানিও বলতে পারতেন না
 * কোনটা কীভাবে গেছে। কোনো যোগফল, কোনো রিপোর্ট এই দুইটা ঘর ছোঁয় না।
 *
 * NULL মানে "যেভাবে সবসময় হত" — পণ্যের নিজের এককে লেখা। পুরনো সব
 * লাইন তাই যেমন ছিল তেমনই থাকে, আর কোনো পর্দা বদলাতে হয় না।
 */
return new class extends Migration
{
    /**
     * যে কাগজে মানুষ পণ্যের পরিমাণ টাইপ করেন — সবগুলো।
     *
     * টাকার লাইন (আদায়, পরিশোধ) এখানে নেই: ওখানে কোনো পণ্য নেই, তাই
     * কোনো প্যাকও নেই।
     */
    private const TABLES = [
        'sal_order_lines' => 'ordered_qty',
        'sal_challan_lines' => 'delivered_qty',
        'sal_challan_gift_lines' => 'qty',
        'sal_invoice_lines' => 'qty',
        'sal_return_lines' => 'qty',
        'pur_order_lines' => 'ordered_qty',
        'pur_receipt_lines' => 'received_qty',
        'pur_bill_lines' => 'qty',
        'pur_return_lines' => 'qty',
        'inv_transfer_lines' => 'qty',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $qtyColumn) {
            Schema::table($table, function (Blueprint $blueprint) use ($qtyColumn): void {
                /*
                 * qty-র সমান নির্ভুলতা — বেশিও নয়, কমও নয়।
                 *
                 * কম রাখলে ২৫০ গ্রামের মতো ভাঙা প্যাক ছাপার সময় আসল
                 * সংখ্যার সাথে মিলত না; বেশি রাখলে ছাপায় এমন ঘর দেখা
                 * যেত যা মজুদে কোথাও নেই।
                 */
                $blueprint->decimal('entered_qty', 18, 4)->nullable()->after($qtyColumn);

                /*
                 * একক মুছে ফেললে লাইনটা থেকে যায়, কেবল প্যাকের নামটা
                 * হারায়। restrictOnDelete দিলে পুরনো একটা লাইনের জন্য
                 * সেটিংসের সারি আর কখনো সরানো যেত না।
                 */
                $blueprint->foreignId('entered_unit_id')->nullable()->after('entered_qty')
                    ->constrained('mdm_units')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('entered_unit_id');
                $blueprint->dropColumn('entered_qty');
            });
        }
    }
};
