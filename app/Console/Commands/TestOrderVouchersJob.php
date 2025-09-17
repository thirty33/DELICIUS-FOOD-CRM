<?php

namespace App\Console\Commands;

use App\Jobs\GenerateOrderVouchersJob;
use App\Models\Order;
use Illuminate\Console\Command;

class TestOrderVouchersJob extends Command
{
    protected $signature = 'test:vouchers-job {--orders= : Comma-separated order IDs} {--limit=3 : Number of orders to process if no specific IDs}';

    protected $description = 'Test the GenerateOrderVouchersJob by dispatching it with specific order IDs';

    public function handle()
    {
        try {
            $this->info('🧪 Testing GenerateOrderVouchersJob...');

            $orderIds = [];
            $orderIdsOption = $this->option('orders');

            if ($orderIdsOption) {
                $orderIds = array_map('intval', explode(',', $orderIdsOption));
                $this->info("📋 Using specified order IDs: " . implode(', ', $orderIds));
            } else {
                $limit = (int) $this->option('limit');
                $orders = Order::limit($limit)->pluck('id')->toArray();

                if (empty($orders)) {
                    $this->error('❌ No orders found in database');
                    return 1;
                }

                $orderIds = $orders;
                $this->info("📋 Using first {$limit} orders from database: " . implode(', ', $orderIds));
            }

            $validOrderIds = Order::whereIn('id', $orderIds)->pluck('id')->toArray();

            if (empty($validOrderIds)) {
                $this->error('❌ None of the specified order IDs exist in database');
                return 1;
            }

            if (count($validOrderIds) < count($orderIds)) {
                $invalidIds = array_diff($orderIds, $validOrderIds);
                $this->warn("⚠️  Invalid order IDs (not found): " . implode(', ', $invalidIds));
            }

            $this->info("✅ Valid order IDs to process: " . implode(', ', $validOrderIds));

            $this->newLine();
            $this->info('🚀 Dispatching GenerateOrderVouchersJob...');

            GenerateOrderVouchersJob::dispatch($validOrderIds);

            $this->info('✅ Job dispatched successfully!');
            $this->line('📊 Job Details:');
            $this->line("   🧾 Order IDs: " . implode(', ', $validOrderIds));
            $this->line("   📦 Job Class: GenerateOrderVouchersJob");
            $this->line("   ⏰ Status: Queued for processing");

            $this->newLine();
            $this->info('💡 Tips:');
            $this->line('   • Run `php artisan queue:work` to process the job');
            $this->line('   • Check logs for job execution details');
            $this->line('   • Monitor ExportProcess table for status updates');

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error testing GenerateOrderVouchersJob: ' . $e->getMessage());
            $this->error('📍 File: ' . $e->getFile() . ':' . $e->getLine());
            return 1;
        }
    }
}