public function up(): void
{
    // Сначала исправим проблему с дублированием внешнего ключа в promocode_usage
    try {
        DB::statement('ALTER TABLE promocode_usage DROP FOREIGN KEY promocode_usage_order_id_foreign');
    } catch (Exception $e) {
        // Игнорируем ошибку, если ключ не существует
    }

    // Создаем таблицу shop_favorites только если она не существует
    if (!Schema::hasTable('shop_favorites')) {
        Schema::create('shop_favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('good_id');
            $table->timestamps();
            
            // Индексы и внешние ключи
            $table->unique(['user_id', 'good_id'], 'shop_favorites_user_id_good_id_unique');
            $table->index('user_id', 'shop_favorites_user_id_foreign');
            $table->index('good_id', 'shop_favorites_good_id_foreign');
        });
    }
}