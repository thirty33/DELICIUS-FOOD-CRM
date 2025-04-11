@extends('layouts.email')

@section('content')
<p>Estimado(a) {{ $order->user->name }},</p>

<p>Le confirmamos que hemos recibido su pedido con el número <strong>{{ $order->order_number }}</strong>.</p>

<p><strong>Fecha de creación:</strong> {{ \Carbon\Carbon::parse($order->created_at)->format('d/m/Y H:i:s') }}</p>

@php
    $status = \App\Enums\OrderStatus::from($order->status);
    $statusColor = match($status->value) {
        \App\Enums\OrderStatus::PENDING->value => '#F59E0B', // warning - color amarillo/naranja
        \App\Enums\OrderStatus::PARTIALLY_SCHEDULED->value => '#0EA5E9', // info - color azul
        \App\Enums\OrderStatus::PROCESSED->value => '#10B981', // success - color verde
        \App\Enums\OrderStatus::CANCELED->value => '#EF4444', // danger - color rojo
    };
    
    // Verificar si el usuario tiene rol Admin o Café
    $showPricesAndSchedule = \App\Classes\UserPermissions::IsAdmin($order->user) || 
                              \App\Classes\UserPermissions::IsCafe($order->user);
@endphp

<div style="margin: 20px 0; padding: 10px 15px; border-radius: 5px; background-color: {{ $statusColor }}; color: white; font-weight: bold; font-size: 16px; text-align: center;">
    Estado del pedido: {{ $status->getLabel() }}
</div>

<div class="order-details">
    <h3>Detalles del pedido:</h3>
    
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;">
        <thead>
            <tr style="background-color: #f5f5f5;">
                <th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Producto</th>
                <th style="padding: 8px; text-align: center; border: 1px solid #ddd;">Cantidad</th>
                @if($showPricesAndSchedule)
                <th style="padding: 8px; text-align: right; border: 1px solid #ddd;">Precio Neto</th>
                <th style="padding: 8px; text-align: right; border: 1px solid #ddd;">Precio con impuesto</th>
                <th style="padding: 8px; text-align: right; border: 1px solid #ddd;">Precio total sin impuesto</th>
                <th style="padding: 8px; text-align: right; border: 1px solid #ddd;">Precio total con impuesto</th>
                <th style="padding: 8px; text-align: center; border: 1px solid #ddd;">Parcialmente agendado</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($order->orderLines as $line)
            <tr>
                <td style="padding: 8px; text-align: left; border: 1px solid #ddd;">{{ $line->product->name }}</td>
                <td style="padding: 8px; text-align: center; border: 1px solid #ddd;">{{ $line->quantity }}</td>
                @if($showPricesAndSchedule)
                <td style="padding: 8px; text-align: right; border: 1px solid #ddd;">{{ \App\Classes\PriceFormatter::format($line->unit_price) }}</td>
                <td style="padding: 8px; text-align: right; border: 1px solid #ddd;">{{ \App\Classes\PriceFormatter::format($line->unit_price_with_tax) }}</td>
                <td style="padding: 8px; text-align: right; border: 1px solid #ddd;">{{ \App\Classes\PriceFormatter::format($line->quantity * $line->unit_price) }}</td>
                <td style="padding: 8px; text-align: right; border: 1px solid #ddd;">{{ \App\Classes\PriceFormatter::format($line->quantity * $line->unit_price_with_tax) }}</td>
                <td style="padding: 8px; text-align: center; border: 1px solid #ddd; color: {{ $line->partially_scheduled ? 'green' : 'red' }};">
                    {{ $line->partially_scheduled ? '✓' : 'X' }}
                </td>
                @endif
            </tr>
            @endforeach
        </tbody>
        @if($showPricesAndSchedule)
        <tfoot>
            <tr style="background-color: #f9f9f9;">
                <td colspan="4" style="padding: 8px; text-align: right; border: 1px solid #ddd;"><strong>Subtotal:</strong></td>
                <td style="padding: 8px; text-align: right; border: 1px solid #ddd; font-weight: bold;">{{ \App\Classes\PriceFormatter::format($order->total) }}</td>
                <td style="padding: 8px; text-align: right; border: 1px solid #ddd; font-weight: bold;">{{ \App\Classes\PriceFormatter::format($order->total_with_tax) }}</td>
                <td style="padding: 8px; border: 1px solid #ddd;"></td>
            </tr>
        </tfoot>
        @endif
    </table>
</div>

<div class="shipping-info">
    <h3>Información de envío:</h3>
    
    @php
        $shippingAddress = 'No especificada';
        
        if ($order->branch_id && $order->user && $order->user->company) {
            $branch = $order->user->company->branches->where('id', $order->branch_id)->first();
            if ($branch) {
                $shippingAddress = $branch->shipping_address;
            }
        } elseif ($order->alternative_address) {
            $shippingAddress = $order->alternative_address;
        }
    @endphp
    
    <p><strong>Dirección de entrega:</strong> {{ $shippingAddress }}</p>
    
    @if($order->dispatch_date)
    <p><strong>Fecha de despacho estimada:</strong> {{ \Carbon\Carbon::parse($order->dispatch_date)->format('d/m/Y') }}</p>
    @endif
</div>

<p>Gracias por su pedido. Si tiene alguna pregunta o necesita modificar su pedido, por favor contáctenos.</p>

<p>Saludos cordiales,<br>
El equipo de Delicius Food</p>
@endsection