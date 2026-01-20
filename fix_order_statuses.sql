-- Добавление статусов заказов
INSERT INTO shop_order_statuses (id, name, display_name, color, is_active, is_finished, is_cancelled, sort_order, description, created_at, updated_at) VALUES
(1, 'pending', 'Обрабатывается', '#F59E0B', 1, 0, 0, 1, 'Заказ принят и находится в обработке', NOW(), NOW()),
(2, 'confirmed', 'Подтвержден', '#3B82F6', 1, 0, 0, 2, 'Заказ подтвержден менеджером', NOW(), NOW()),
(3, 'paid', 'Оплачен', '#10B981', 1, 0, 0, 3, 'Заказ оплачен', NOW(), NOW()),
(4, 'shipped', 'Отправлен', '#8B5CF6', 1, 0, 0, 4, 'Заказ отправлен покупателю', NOW(), NOW()),
(5, 'delivered', 'Доставлен', '#059669', 0, 1, 0, 5, 'Заказ доставлен покупателю', NOW(), NOW()),
(6, 'cancelled', 'Отменен', '#EF4444', 0, 1, 1, 6, 'Заказ отменен', NOW(), NOW()),
(7, 'refunded', 'Возвращен', '#F97316', 0, 1, 0, 7, 'Средства возвращены покупателю', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    display_name = VALUES(display_name),
    color = VALUES(color),
    is_active = VALUES(is_active),
    is_finished = VALUES(is_finished),
    is_cancelled = VALUES(is_cancelled),
    sort_order = VALUES(sort_order),
    description = VALUES(description),
    updated_at = NOW();

-- Добавление статусов доставки
INSERT INTO shop_delivery_statuses (id, name, display_name, color, is_active, sort_order, description, created_at, updated_at) VALUES
(1, 'created', 'Создан', '#6B7280', 1, 10, 'Заказ создан', NOW(), NOW()),
(2, 'transferred_to_courier', 'Передан в ТК', '#3B82F6', 1, 20, 'Заказ передан в транспортную компанию', NOW(), NOW()),
(3, 'cancelled', 'Отменен', '#EF4444', 1, 30, 'Доставка отменена', NOW(), NOW()),
(4, 'in_transit', 'В пути', '#8B5CF6', 1, 40, 'Заказ в пути', NOW(), NOW()),
(5, 'at_pickup_point', 'В месте выдачи', '#F59E0B', 1, 50, 'Заказ прибыл в место выдачи', NOW(), NOW()),
(6, 'delivered', 'Выдан', '#10B981', 1, 60, 'Заказ выдан получателю', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    display_name = VALUES(display_name),
    color = VALUES(color),
    is_active = VALUES(is_active),
    sort_order = VALUES(sort_order),
    description = VALUES(description),
    updated_at = NOW();