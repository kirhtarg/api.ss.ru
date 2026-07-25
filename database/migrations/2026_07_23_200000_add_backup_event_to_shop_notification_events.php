<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE shop_notification_events MODIFY event_type ENUM('order_created', 'cancellation_request', 'order_cancelled', 'preorder_created', 'site_message', 'backup') NOT NULL");
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::table('shop_notification_events')->where('event_type', 'backup')->delete();
        DB::statement("ALTER TABLE shop_notification_events MODIFY event_type ENUM('order_created', 'cancellation_request', 'order_cancelled', 'preorder_created', 'site_message') NOT NULL");
    }
};