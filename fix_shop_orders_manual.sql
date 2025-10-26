-- Добавляем поля с значениями по умолчанию
ALTER TABLE `shop_orders` 
ADD COLUMN IF NOT EXISTS `payment_status_id` bigint unsigned DEFAULT 1 AFTER `status_id`,
ADD COLUMN IF NOT EXISTS `delivery_status_id` bigint unsigned DEFAULT 1 AFTER `payment_status_id`;

-- Обновляем существующие записи
UPDATE `shop_orders` SET `payment_status_id` = 1 WHERE `payment_status_id` IS NULL;
UPDATE `shop_orders` SET `delivery_status_id` = 1 WHERE `delivery_status_id` IS NULL;
