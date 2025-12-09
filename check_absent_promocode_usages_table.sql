-- Проверка структуры таблицы absent_promocode_usages
-- Выполните этот SQL запрос на сервере, чтобы проверить структуру таблицы

-- 1. Проверка структуры таблицы
DESCRIBE absent_promocode_usages;

-- 2. Проверка индексов
SHOW INDEXES FROM absent_promocode_usages;

-- 3. Проверка foreign keys
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM 
    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE 
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'absent_promocode_usages'
    AND REFERENCED_TABLE_NAME IS NOT NULL;

-- 4. Проверка, есть ли поле ip_address
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM 
    INFORMATION_SCHEMA.COLUMNS
WHERE 
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'absent_promocode_usages'
    AND COLUMN_NAME = 'ip_address';

-- 5. Проверка, может ли user_id быть NULL
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM 
    INFORMATION_SCHEMA.COLUMNS
WHERE 
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'absent_promocode_usages'
    AND COLUMN_NAME = 'user_id';










