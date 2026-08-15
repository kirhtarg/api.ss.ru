<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_carrier_delivery_settings', function (Blueprint $table) {
            $table->string('oauth_client_id')->nullable()->after('client_secret');
            $table->text('oauth_client_secret')->nullable()->after('oauth_client_id');
            $table->text('oauth_access_token')->nullable()->after('oauth_client_secret');
            $table->timestamp('oauth_expires_at')->nullable()->after('oauth_access_token');
            $table->string('seller_id')->nullable()->after('oauth_expires_at');
            $table->string('store_domain')->nullable()->after('seller_id');
            $table->string('logistics_schema', 20)->nullable()->after('store_domain');
        });
    }

    public function down(): void
    {
        Schema::table('shop_carrier_delivery_settings', function (Blueprint $table) {
            $table->dropColumn([
                'oauth_client_id',
                'oauth_client_secret',
                'oauth_access_token',
                'oauth_expires_at',
                'seller_id',
                'store_domain',
                'logistics_schema',
            ]);
        });
    }
};
