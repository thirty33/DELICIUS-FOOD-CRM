<?php

namespace App\Models;

use App\Enums\WarehouseTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class WarehouseTransaction extends Model
{
    protected $fillable = [
        'warehouse_id',
        'user_id',
        'transaction_code',
        'status',
        'reason',
        'executed_at',
        'executed_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => WarehouseTransactionStatus::class,
        'executed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WarehouseTransactionLine::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', WarehouseTransactionStatus::PENDING);
    }

    public function scopeExecuted($query)
    {
        return $query->where('status', WarehouseTransactionStatus::EXECUTED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', WarehouseTransactionStatus::CANCELLED);
    }

    public function canExecute(): bool
    {
        return $this->status === WarehouseTransactionStatus::PENDING
            || $this->status === WarehouseTransactionStatus::CANCELLED;
    }

    public function canCancel(): bool
    {
        return $this->status === WarehouseTransactionStatus::EXECUTED;
    }

    public function execute(int $userId): bool
    {
        if (!$this->canExecute()) {
            return false;
        }

        DB::beginTransaction();
        try {
            foreach ($this->lines as $line) {
                DB::table('warehouse_product')
                    ->where('warehouse_id', $this->warehouse_id)
                    ->where('product_id', $line->product_id)
                    ->update([
                        'stock' => $line->stock_after,
                        'updated_at' => now(),
                    ]);
            }

            $this->update([
                'status' => WarehouseTransactionStatus::EXECUTED,
                'executed_at' => now(),
                'executed_by' => $userId,
            ]);

            DB::commit();
            return true;
        } catch (\Exception) {
            DB::rollBack();
            return false;
        }
    }

    public function cancel(int $userId, string $reason): bool
    {
        if (!$this->canCancel()) {
            return false;
        }

        DB::beginTransaction();
        try {
            foreach ($this->lines as $line) {
                DB::table('warehouse_product')
                    ->where('warehouse_id', $this->warehouse_id)
                    ->where('product_id', $line->product_id)
                    ->update([
                        'stock' => $line->stock_before,
                        'updated_at' => now(),
                    ]);
            }

            $this->update([
                'status' => WarehouseTransactionStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            DB::commit();
            return true;
        } catch (\Exception) {
            DB::rollBack();
            return false;
        }
    }

    public static function generateTransactionCode(): string
    {
        $year = date('Y');
        $lastTransaction = static::where('transaction_code', 'like', "TRX-{$year}-%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastTransaction) {
            $lastNumber = (int) substr($lastTransaction->transaction_code, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf('TRX-%s-%04d', $year, $newNumber);
    }
}
