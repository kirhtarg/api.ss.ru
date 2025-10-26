-- Создание таблиц статусов
CREATE TABLE IF NOT EXISTS `shop_payment_statuses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `color` varchar(7) DEFAULT '#6B7280',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `description` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_payment_statuses_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_delivery_statuses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `color` varchar(7) DEFAULT '#6B7280',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `description` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_delivery_statuses_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Добавление колонок в shop_orders если их нет
ALTER TABLE `shop_orders` 
ADD COLUMN IF NOT EXISTS `payment_status_id` bigint unsigned DEFAULT 1 AFTER `status_id`,
ADD COLUMN IF NOT EXISTS `delivery_status_id` bigint unsigned DEFAULT 1 AFTER `payment_status_id`;

-- Добавление индексов
ALTER TABLE `shop_orders` 
ADD INDEX IF NOT EXISTS `shop_orders_payment_status_id_index` (`payment_status_id`),
ADD INDEX IF NOT EXISTS `shop_orders_delivery_status_id_index` (`delivery_status_id`);

-- Обновление существующих записей
UPDATE `shop_orders` SET `payment_status_id` = 1 WHERE `payment_status_id` IS NULL;
UPDATE `shop_orders` SET `delivery_status_id` = 1 WHERE `delivery_status_id` IS NULL;

-- Вставка данных статусов
INSERT IGNORE INTO `shop_payment_statuses` (`id`, `name`, `display_name`, `color`, `is_active`, `sort_order`, `description`, `created_at`, `updated_at`) VALUES
(1, 'pending', 'Ожидает оплаты', '#F59E0B', 1, 10, 'Заказ ожидает оплаты', NOW(), NOW()),
(2, 'paid', 'Оплачен', '#10B981', 1, 20, 'Заказ оплачен', NOW(), NOW());

INSERT IGNORE INTO `shop_delivery_statuses` (`id`, `name`, `display_name`, `color`, `is_active`, `sort_order`, `description`, `created_at`, `updated_at`) VALUES
(1, 'created', 'Создан', '#6B7280', 1, 10, 'Заказ создан', NOW(), NOW()),
(2, 'transferred_to_courier', 'Передан в ТК', '#3B82F6', 1, 20, 'Заказ передан в транспортную компанию', NOW(), NOW()),
(3, 'cancelled', 'Отменен', '#EF4444', 1, 30, 'Доставка отменена', NOW(), NOW()),
(4, 'in_transit', 'В пути', '#8B5CF6', 1, 40, 'Заказ в пути', NOW(), NOW()),
(5, 'at_pickup_point', 'В месте выдачи', '#F59E0B', 1, 50, 'Заказ прибыл в место выдачи', NOW(), NOW()),
(6, 'delivered', 'Выдан', '#10B981', 1, 60, 'Заказ выдан получателю', NOW(), NOW());

-- Добавление внешних ключей
ALTER TABLE `shop_orders` 
ADD CONSTRAINT `shop_orders_payment_status_id_foreign` 
FOREIGN KEY (`payment_status_id`) REFERENCES `shop_payment_statuses` (`id`) ON DELETE RESTRICT;

ALTER TABLE `shop_orders` 
ADD CONSTRAINT `shop_orders_delivery_status_id_foreign` 
FOREIGN KEY (`delivery_status_id`) REFERENCES `shop_delivery_statuses` (`id`) ON DELETE RESTRICT;
