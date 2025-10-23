<div class="p-6">
    {{-- Order Header Information --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        {{-- Dates Section --}}
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
            <h3 class="text-lg font-semibold mb-3 text-gray-900 dark:text-gray-100">Fechas</h3>
            <dl class="space-y-2">
                <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Fecha de pedido:</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100">{{ $order->created_at->format('d/m/Y H:i') }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Fecha de despacho:</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100">{{ \Carbon\Carbon::parse($order->dispatch_date)->format('d/m/Y') }}</dd>
                </div>
            </dl>
        </div>

        {{-- ID and Status Section --}}
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
            <h3 class="text-lg font-semibold mb-3 text-gray-900 dark:text-gray-100">Identificación</h3>
            <dl class="space-y-2">
                <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">ID:</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100">{{ $order->id }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Número de orden:</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100">{{ $order->order_number ?? 'Sin número' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Estado:</dt>
                    <dd class="text-sm">
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium
                            @if($order->status === 'pending') bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                            @elseif($order->status === 'processed') bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200
                            @elseif($order->status === 'delivered') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                            @else bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200
                            @endif">
                            {{ \App\Enums\OrderStatus::from($order->status)->getLabel() }}
                        </span>
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Client and Company Information --}}
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 mb-6">
        <h3 class="text-lg font-semibold mb-3 text-gray-900 dark:text-gray-100">Cliente y Empresa</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <dl class="space-y-2">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Cliente:</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100">{{ $order->user->name }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Email / Usuario:</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100">{{ $order->user->email ?: $order->user->nickname }}</dd>
                </div>
            </dl>
            <dl class="space-y-2">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Empresa:</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100">{{ $order->user->company?->fantasy_name ?? 'Sin empresa' }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Sucursal:</dt>
                    <dd class="text-sm text-gray-900 dark:text-gray-100">{{ $order->user->branch?->fantasy_name ?? 'Sin sucursal' }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Order Lines Table --}}
    <div class="mb-6">
        <h3 class="text-lg font-semibold mb-3 text-gray-900 dark:text-gray-100">Líneas de Pedido</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Producto</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Categoría</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cantidad</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Precio Unit.</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($order->orderLines as $line)
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                            {{ $line->product->name }}
                            @if($line->product->code)
                                <span class="text-xs text-gray-500 dark:text-gray-400">({{ $line->product->code }})</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ $line->product->category->name }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900 dark:text-gray-100">{{ $line->quantity }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900 dark:text-gray-100">${{ number_format($line->unit_price / 100, 2, ',', '.') }}</td>
                        <td class="px-4 py-3 text-sm text-right font-medium text-gray-900 dark:text-gray-100">${{ number_format($line->total_price / 100, 2, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Totals Section --}}
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
        <h3 class="text-lg font-semibold mb-3 text-gray-900 dark:text-gray-100">Totales</h3>
        <dl class="space-y-2">
            <div class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Neto (sin IVA):</dt>
                <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">${{ number_format($order->total / 100, 2, ',', '.') }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Costo de despacho:</dt>
                <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">${{ number_format($order->dispatch_cost / 100, 2, ',', '.') }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">IVA:</dt>
                <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">${{ number_format($order->tax_amount / 100, 2, ',', '.') }}</dd>
            </div>
            <div class="flex justify-between pt-2 border-t border-gray-200 dark:border-gray-700">
                <dt class="text-base font-bold text-gray-900 dark:text-gray-100">Total Final:</dt>
                <dd class="text-base font-bold text-gray-900 dark:text-gray-100">${{ number_format($order->grand_total / 100, 2, ',', '.') }}</dd>
            </div>
        </dl>
    </div>

    @if($order->user_comment)
    {{-- Comments Section --}}
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 mt-6">
        <h3 class="text-lg font-semibold mb-2 text-gray-900 dark:text-gray-100">Comentarios</h3>
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $order->user_comment }}</p>
    </div>
    @endif
</div>