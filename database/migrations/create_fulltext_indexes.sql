-- SQL скрипт для создания FULLTEXT индексов для улучшения поиска
-- Выполните этот скрипт на удаленном сервере для активации полнотекстового поиска MySQL
-- 
-- ВАЖНО: Перед выполнением убедитесь, что:
-- 1. Таблицы используют движок InnoDB (MySQL 5.6+) или MyISAM
-- 2. Для InnoDB требуется MySQL 5.6 или выше
-- 3. Если индекс уже существует, команда выдаст ошибку - это нормально, просто пропустите её
-- 4. Выполняйте команды по одной, если возникают ошибки

-- Удаляем индексы если они существуют (опционально, раскомментируйте если нужно пересоздать)
-- DROP INDEX name_description_short_description_fulltext ON shop_goods;
-- DROP INDEX name_description_fulltext ON shop_categories;
-- DROP INDEX name_description_fulltext ON shop_brands;

-- FULLTEXT индекс для таблицы shop_goods (название, описание, краткое описание)
-- Если индекс уже существует, вы получите ошибку - это нормально, просто пропустите её
CREATE FULLTEXT INDEX name_description_short_description_fulltext ON shop_goods(name, description, short_description);

-- FULLTEXT индекс для таблицы shop_categories (название, описание)
-- ВАЖНО: Если поле description может быть NULL, FULLTEXT индекс всё равно создастся, но NULL значения не будут индексироваться
CREATE FULLTEXT INDEX shop_categories_name_description_fulltext ON shop_categories(name, description);

-- FULLTEXT индекс для таблицы shop_brands (название, описание)
CREATE FULLTEXT INDEX shop_brands_name_description_fulltext ON shop_brands(name, description);

-- Проверка созданных индексов
SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLUMNS
FROM information_schema.statistics
WHERE table_schema = DATABASE()
AND INDEX_TYPE = 'FULLTEXT'
AND TABLE_NAME IN ('shop_goods', 'shop_categories', 'shop_brands')
GROUP BY TABLE_NAME, INDEX_NAME;

