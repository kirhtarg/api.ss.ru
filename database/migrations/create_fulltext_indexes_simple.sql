-- Простая версия SQL скрипта для создания FULLTEXT индексов
-- Выполняйте команды по одной, если возникают ошибки

-- 1. FULLTEXT индекс для shop_goods
CREATE FULLTEXT INDEX name_description_short_description_fulltext ON shop_goods(name, description, short_description);

-- 2. FULLTEXT индекс для shop_categories
CREATE FULLTEXT INDEX shop_categories_name_description_fulltext ON shop_categories(name, description);

-- 3. FULLTEXT индекс для shop_brands
CREATE FULLTEXT INDEX shop_brands_name_description_fulltext ON shop_brands(name, description);

