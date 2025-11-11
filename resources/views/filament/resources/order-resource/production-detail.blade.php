<div class="p-6 space-y-6">
    <!-- Production Status Badge -->
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold">Estado de Producción</h3>
        <span class="{{ \App\Enums\OrderProductionStatus::from($detail['production_status'])->badgeClasses() }}">
            {{ \App\Enums\OrderProductionStatus::from($detail['production_status'])->label() }}
        </span>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
            <div class="text-sm text-gray-500 dark:text-gray-400">Total de Productos</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {{ $detail['summary']['total_products'] }}
            </div>
        </div>

        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
            <div class="text-sm text-green-600 dark:text-green-400">Completamente Producidos</div>
            <div class="text-2xl font-bold text-green-700 dark:text-green-300">
                {{ $detail['summary']['fully_produced_count'] }}
            </div>
        </div>

        <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-4">
            <div class="text-sm text-yellow-600 dark:text-yellow-400">Parcialmente Producidos</div>
            <div class="text-2xl font-bold text-yellow-700 dark:text-yellow-300">
                {{ $detail['summary']['partially_produced_count'] }}
            </div>
        </div>

        <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
            <div class="text-sm text-red-600 dark:text-red-400">No Producidos</div>
            <div class="text-2xl font-bold text-red-700 dark:text-red-300">
                {{ $detail['summary']['not_produced_count'] }}
            </div>
        </div>
    </div>

    <!-- Coverage Percentage -->
    <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-blue-700 dark:text-blue-300">Porcentaje de Cobertura Total</span>
            <span class="text-lg font-bold text-blue-800 dark:text-blue-200">
                {{ number_format($detail['summary']['total_coverage_percentage'], 2) }}%
            </span>
        </div>
        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
            <div class="bg-blue-600 h-2.5 rounded-full transition-all duration-300"
                 style="width: {{ $detail['summary']['total_coverage_percentage'] }}%">
            </div>
        </div>
    </div>

    <!-- Products Detail Table -->
    <div class="mt-6">
        <h4 class="text-md font-semibold mb-4">Detalle por Producto</h4>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs uppercase bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3">Producto</th>
                        <th class="px-4 py-3 text-center">Cantidad Requerida</th>
                        <th class="px-4 py-3 text-center">Cantidad Producida</th>
                        <th class="px-4 py-3 text-center">Cobertura</th>
                        <th class="px-4 py-3 text-center">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($detail['products'] as $productDetail)
                        <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">
                            <td class="px-4 py-3 font-medium">
                                <div>{{ $productDetail['product_name'] }}</div>
                                <div class="text-xs text-gray-500">{{ $productDetail['product_code'] }}</div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                {{ $productDetail['required_quantity'] }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                {{ $productDetail['produced_quantity'] }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <span class="font-semibold">{{ number_format($productDetail['coverage_percentage'], 2) }}%</span>
                                    <div class="w-20 bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                        <div class="h-1.5 rounded-full transition-all duration-300
                                            @if($productDetail['coverage_percentage'] >= 100) bg-green-600
                                            @elseif($productDetail['coverage_percentage'] > 0) bg-yellow-500
                                            @else bg-red-600
                                            @endif"
                                             style="width: {{ min($productDetail['coverage_percentage'], 100) }}%">
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($productDetail['coverage_percentage'] >= 100)
                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                        Completo
                                    </span>
                                @elseif($productDetail['coverage_percentage'] > 0)
                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300">
                                        Parcial
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
                                        Pendiente
                                    </span>
                                @endif
                            </td>
                        </tr>

                        @if(!empty($productDetail['advance_orders']))
                            <tr class="bg-gray-50 dark:bg-gray-900">
                                <td colspan="5" class="px-4 py-2">
                                    <div class="ml-8 text-xs">
                                        <div class="font-semibold mb-2 text-gray-700 dark:text-gray-300">
                                            Órdenes de Producción que cubren este producto:
                                        </div>
                                        <div class="space-y-1">
                                            @foreach($productDetail['advance_orders'] as $ao)
                                                <div class="flex items-center gap-4 text-gray-600 dark:text-gray-400">
                                                    <span class="font-medium">OP #{{ $ao['advance_order_id'] }}</span>
                                                    <span>{{ $ao['advance_order_code'] }}</span>
                                                    <span>Cantidad cubierta: <strong>{{ $ao['quantity_covered'] }}</strong></span>
                                                    <span class="text-xs">({{ \Carbon\Carbon::parse($ao['initial_dispatch_date'])->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($ao['final_dispatch_date'])->format('d/m/Y') }})</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                No hay productos en esta orden
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($detail['summary']['total_products'] > 0)
        <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
            <h5 class="text-sm font-semibold text-blue-800 dark:text-blue-200 mb-2">Cómo se calcula el estado de producción:</h5>
            <ul class="text-xs text-blue-700 dark:text-blue-300 space-y-1 list-disc list-inside">
                <li><strong>Completamente Producido:</strong> Todos los productos tienen 100% de cobertura</li>
                <li><strong>Parcialmente Producido:</strong> Al menos un producto tiene cobertura mayor a 0% pero no todos están al 100%</li>
                <li><strong>No Producido:</strong> Ningún producto tiene cobertura de producción</li>
            </ul>
        </div>
    @endif
</div>
