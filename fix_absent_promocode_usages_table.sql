-- Исправление структуры таблицы absent_promocode_usages
-- ВНИМАНИЕ: Выполняйте только если миграция не сработала корректно
-- Сначала сделайте резервную копию базы данных!

-- 1. Удаляем foreign key на user_id (если существует)
SET @constraint_name = (
    SELECT CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'absent_promocode_usages'
    AND COLUMN_NAME = 'user_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);

SET @sql = IF(@constraint_name IS NOT NULL, 
    CONCAT('ALTER TABLE absent_promocode_usages DROP FOREIGN KEY ', @constraint_name),
    'SELECT "Foreign key не найден" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Удаляем уникальный индекс (если существует)
ALTER TABLE absent_promocode_usages DROP INDEX IF EXISTS absent_promocode_usages_user_id_good_id_unique;

-- 3. Делаем user_id nullable (если еще не nullable)
ALTER TABLE absent_promocode_usages MODIFY COLUMN user_id BIGINT UNSIGNED NULL;

-- 4. Добавляем поле ip_address (если еще не существует)
ALTER TABLE absent_promocode_usages 
ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL AFTER user_id;

-- 5. Восстанавливаем foreign key на user_id (только для не-null значений)
ALTER TABLE absent_promocode_usages 
ADD CONSTRAINT absent_promocode_usages_user_id_foreign 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- 6. Добавляем индексы
ALTER TABLE absent_promocode_usages 
ADD INDEX IF NOT EXISTS absent_promocode_usages_user_id_good_id_index (user_id, good_id);

ALTER TABLE absent_promocode_usages 
ADD INDEX IF NOT EXISTS absent_promocode_usages_ip_address_good_id_index (ip_address, good_id);

-- 7. Проверка результата
DESCRIBE absent_promocode_usages;










