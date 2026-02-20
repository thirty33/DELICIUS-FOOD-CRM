<?php

namespace App\Actions\Sellers;

use App\Models\UserPortfolio;
use Carbon\Carbon;

final class RecordFirstOrderAction
{
    public static function execute(array $data = []): void
    {
        $userId = data_get($data, 'user_id');
        $orderDate = data_get($data, 'order_date'); // Carbon instance

        $monthClosedAt = $orderDate->day === 1
            ? $orderDate->copy()->endOfMonth()
            : $orderDate->copy()->addMonthNoOverflow()->endOfMonth();

        // Atomic: whereNull ensures the update only fires on the first order.
        // If two jobs run simultaneously only one will find first_order_at IS NULL.
        UserPortfolio::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereNull('first_order_at')
            ->update([
                'first_order_at' => $orderDate->toDateString(),
                'month_closed_at' => $monthClosedAt->toDateString(),
            ]);
    }
}
