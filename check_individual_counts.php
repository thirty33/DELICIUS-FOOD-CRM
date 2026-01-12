<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$advanceOrder = \App\Models\AdvanceOrder::with(['associatedOrderLines.orderLine'])->find(243);

$individualProductIds = [1062, 1811, 1402, 1812, 1813, 1848, 1816, 1810, 1814];

$processedOrderLineIds = [];
$individualCounts = [];

foreach ($advanceOrder->associatedOrderLines as $aoOrderLine) {
    if (isset($processedOrderLineIds[$aoOrderLine->order_line_id])) {
        continue;
    }
    $processedOrderLineIds[$aoOrderLine->order_line_id] = true;

    if (!$aoOrderLine->orderLine) {
        continue;
    }

    $productId = $aoOrderLine->orderLine->product_id;

    if (in_array($productId, $individualProductIds)) {
        if (!isset($individualCounts[$productId])) {
            $individualCounts[$productId] = 0;
        }
        $individualCounts[$productId] += $aoOrderLine->orderLine->quantity;
    }
}

echo "Cantidades CORRECTAS (de associatedOrderLines):\n\n";
echo str_pad("ID", 6) . " | " . str_pad("Código", 15) . " | " . str_pad("Nombre", 45) . " | Qty\n";
echo str_repeat("-", 80) . "\n";

$total = 0;
foreach ($individualCounts as $productId => $qty) {
    $prod = \App\Models\Product::find($productId);
    echo str_pad($prod->id, 6) . " | " . str_pad($prod->code, 15) . " | " . str_pad(substr($prod->name, 0, 45), 45) . " | " . $qty . "\n";
    $total += $qty;
}

echo str_repeat("-", 80) . "\n";
echo "TOTAL: $total\n";
