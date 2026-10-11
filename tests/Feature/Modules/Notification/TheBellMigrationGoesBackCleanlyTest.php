<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ⛔ খবরের মাইগ্রেশন ফেরত যেতে গিয়ে মাঝপথে থামত (fe-র Mac রান, ad1ac9f3, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ `notify_recipient_once` (event_id, user_id)-কেই MySQL event_id-র বিদেশি চাবির সূচক ধরে; down() আগে অনন্য-চাবি মুছতে গেলে
 * "1553 … needed in a foreign key constraint"। ⚠️ যে পরীক্ষা `DatabaseMigrations` চালায়, তার শেষের rollback থামত — পরীক্ষার DB
 * আধা-মোছা থাকত, আর পরের শত শত পরীক্ষা "notification_rules doesn't exist"-এ লাল।
 *
 * ⭐ দাবি: পরিষ্কার DB-তে up → down → up — দুই দিকেই শেষ পর্যন্ত যায়, আর ফিরে এসে খবরের ঘরগুলো আবার আছে।
 */
final class TheBellMigrationGoesBackCleanlyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_the_whole_schema_goes_down_and_up_again_without_stopping(): void
    {
        $this->assertTrue(Schema::hasColumn('notifications', 'event_id'));
        $this->assertTrue(Schema::hasTable('notification_rules'));

        $this->artisan('migrate:rollback')->assertSuccessful();
        $this->assertFalse(Schema::hasTable('notification_events'), '⛔ ফেরত যাওয়া মাঝপথে থামল — খবরের ঘটনা-টেবিল রয়ে গেল।');
        $this->assertFalse(Schema::hasTable('notifications'), '⛔ ফেরত যাওয়া শেষ পর্যন্ত গেল না।');

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('notifications', 'event_id'), '⛔ আবার উঠে খবরের ঘটনা-ঘর এল না।');
        $this->assertTrue(Schema::hasColumn('notifications', 'on_behalf_of'));
        $this->assertTrue(Schema::hasTable('notification_rules'));
    }
}
