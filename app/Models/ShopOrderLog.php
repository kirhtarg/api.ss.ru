<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopOrderLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_id',
        'action_icon_id',
        'action',
        'action_color',
        'action_bg_color',
        'comment',
        'user_id',
        'user_name',
        'section',
        'info',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'action_icon_id' => 'integer',
        'user_id' => 'integer',
    ];

    // Константы для разделов логгирования
    const SECTION_ORDERS = 'orders';

    const SECTION_PREORDERS = 'preorders';

    const SECTION_CHECKOUT = 'checkout';

    const SECTION_PAYMENT = 'payment';

    const SECTION_DELIVERY = 'delivery';

    const SECTION_USER = 'user';

    // Отключаем updated_at, так как логи не редактируются
    const UPDATED_AT = null;

    public function order()
    {
        return $this->belongsTo(ShopOrder::class, 'entity_id');
    }

    public function preorder()
    {
        return $this->belongsTo(ShopPreorder::class, 'entity_id');
    }

    public function actionIcon()
    {
        return $this->belongsTo(ShopOrderLogIcon::class, 'action_icon_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Создать лог записи (универсальный метод)
     */
    public static function createLog($entityId, $action, $options = [])
    {
        return self::create([
            'entity_id' => $entityId,
            'action' => $action,
            'action_color' => $options['action_color'] ?? null,
            'action_bg_color' => $options['action_bg_color'] ?? null,
            'action_icon_id' => $options['action_icon_id'] ?? null,
            'comment' => $options['comment'] ?? null,
            'user_id' => $options['user_id'] ?? null,
            'user_name' => $options['user_name'] ?? null,
            'section' => $options['section'] ?? self::SECTION_ORDERS,
            'info' => $options['info'] ?? null,
        ]);
    }

    /**
     * Создать лог "Заказ создан"
     */
    public static function logOrderCreated($orderId, $userName = null, $section = null, $orderNumber = null, $comment = null)
    {
        return self::createLog($orderId, 'Заказ создан', [
            'action_color' => '#16A34A', // green-600
            'user_name' => $userName,
            'comment' => $comment,
            'section' => $section ?? self::SECTION_CHECKOUT,
            'info' => $orderNumber ? "Заказ № {$orderNumber}" : null,
        ]);
    }

    /**
     * Создать лог "Заказ оплачен"
     */
    public static function logOrderPaid($orderId, $userName = null, $comment = null, $section = null, $orderNumber = null)
    {
        return self::createLog($orderId, 'Заказ оплачен', [
            'action_color' => '#16A34A', // green-600
            'user_name' => $userName,
            'comment' => $comment,
            'section' => $section ?? self::SECTION_PAYMENT,
            'info' => $orderNumber ? "Заказ № {$orderNumber}" : null,
        ]);
    }

    /**
     * Создать лог смены статуса
     */
    public static function logStatusChange($orderId, $oldStatus, $newStatus, $userId = null, $userName = null, $comment = null, $section = null, $orderNumber = null)
    {
        $action = "Смена статуса: {$oldStatus['name']} → {$newStatus['name']}";

        return self::createLog($orderId, $action, [
            'user_id' => $userId,
            'user_name' => $userName,
            'comment' => $comment,
            'section' => $section ?? self::SECTION_ORDERS,
            'info' => $orderNumber ? "Заказ № {$orderNumber}" : null,
        ]);
    }

    /**
     * Создать лог смены статуса оплаты
     */
    public static function logPaymentStatusChange($orderId, $isPaid, $userId = null, $userName = null, $comment = null, $section = null, $orderNumber = null)
    {
        $action = $isPaid ? 'Оплачено' : 'Не оплачено';

        return self::createLog($orderId, $action, [
            'action_color' => '#FFFFFF',
            'action_bg_color' => $isPaid ? '#16A34A' : '#DC2626', // green-600 or red-600
            'user_id' => $userId,
            'user_name' => $userName,
            'comment' => $comment,
            'section' => $section ?? self::SECTION_ORDERS,
            'info' => $orderNumber ? "Заказ № {$orderNumber}" : null,
        ]);
    }

    /**
     * Создать лог для предзаказа
     */
    public static function logPreorderAction($preorderId, $action, $options = [])
    {
        $options['section'] = self::SECTION_PREORDERS;

        return self::createLog($preorderId, $action, $options);
    }

    /**
     * Создать лог смены статуса предзаказа
     */
    public static function logPreorderStatusChange($preorderId, $oldStatus, $newStatus, $userId = null, $userName = null, $comment = null, $actionIconId = null, $goodName = null)
    {
        $statusLabels = [
            'pending' => 'Ожидает',
            'confirmed' => 'Подтверждён',
            'cancelled' => 'Отменён',
            'fulfilled' => 'Выполнен',
        ];

        $oldStatusLabel = $statusLabels[$oldStatus] ?? $oldStatus;
        $newStatusLabel = $statusLabels[$newStatus] ?? $newStatus;

        $action = "Смена статуса: {$oldStatusLabel} → {$newStatusLabel}";

        return self::logPreorderAction($preorderId, $action, [
            'user_id' => $userId,
            'user_name' => $userName,
            'comment' => $comment,
            'action_icon_id' => $actionIconId,
            'info' => $goodName ? "Предзаказ: {$goodName}" : null,
        ]);
    }

    /**
     * Создать лог добавления в корзину из предзаказа
     */
    public static function logPreorderAddedToCart($preorderId, $quantity, $userId = null, $userName = null, $goodName = null)
    {
        $action = "Добавлено в корзину: {$quantity} шт.";

        return self::logPreorderAction($preorderId, $action, [
            'action_color' => '#2563EB', // blue-600
            'user_id' => $userId,
            'user_name' => $userName,
            'info' => $goodName ? "Товар: {$goodName}" : null,
        ]);
    }

    /**
     * Создать лог добавления в корзину из заказа
     */
    public static function logOrderAddedToCart($orderId, $quantity, $userId = null, $userName = null, $goodName = null, $orderNumber = null)
    {
        $action = "Добавлено в корзину: {$quantity} шт.";

        return self::createLog($orderId, $action, [
            'action_color' => '#2563EB', // blue-600
            'user_id' => $userId,
            'user_name' => $userName,
            'section' => self::SECTION_ORDERS,
            'info' => $goodName ? "Товар: {$goodName}".($orderNumber ? " (Заказ № {$orderNumber})" : '') : null,
        ]);
    }

    /**
     * Создать лог удаления предзаказа
     */
    public static function logPreorderDeleted($preorderId, $userId = null, $userName = null, $goodName = null)
    {
        return self::logPreorderAction($preorderId, 'Предзаказ удалён', [
            'action_color' => '#DC2626', // red-600
            'user_id' => $userId,
            'user_name' => $userName,
            'info' => $goodName ? "Товар: {$goodName}" : null,
        ]);
    }

    /**
     * Создать лог удаления заказа
     */
    public static function logOrderDeleted($orderId, $userId = null, $userName = null, $orderNumber = null)
    {
        return self::createLog($orderId, 'Заказ удалён', [
            'action_color' => '#DC2626', // red-600
            'user_id' => $userId,
            'user_name' => $userName,
            'section' => self::SECTION_ORDERS,
            'info' => $orderNumber ? "Заказ № {$orderNumber}" : null,
        ]);
    }
}
