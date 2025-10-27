<?php

namespace App\Services\Vouchers;

use App\Contracts\Vouchers\GroupingStrategy;
use App\Models\Order;
use App\Services\Vouchers\Strategies\SingleOrderGrouping;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Single Voucher PDF Generator
 *
 * Generates individual vouchers (one per order)
 * Extends VoucherGenerator using Template Method pattern
 */
class VoucherPdfGenerator extends VoucherGenerator
{
    /**
     * Constructor - uses SingleOrderGrouping strategy by default
     */
    public function __construct(?GroupingStrategy $groupingStrategy = null)
    {
        parent::__construct($groupingStrategy ?? new SingleOrderGrouping());
    }

    /**
     * Backward compatibility method - delegates to parent's generate()
     *
     * @param Collection $orders
     * @return string PDF binary content
     */
    public function generateMultiVoucherPdf(Collection $orders): string
    {
        return $this->generate($orders);
    }

    /**
     * Generate HTML for a group of orders
     * In this case, each group contains a single order
     *
     * @param array $orderGroup Array containing a single order
     * @return string HTML content
     */
    protected function generateHtmlForGroup(array $orderGroup): string
    {
        if (empty($orderGroup)) {
            return '<div class="voucher-container"><p>No order data</p></div>';
        }

        // For single voucher generator, group should contain exactly one order
        $order = $orderGroup[0];

        return $this->generateSingleVoucherHtml($order);
    }

    /**
     * Generate HTML for a single voucher
     *
     * @param Order $order
     * @return string
     */
    private function generateSingleVoucherHtml(Order $order): string
    {
        $dispatchDate = Carbon::parse($order->dispatch_date)->format('d/m/Y');

        $neto = $order->total / 100;
        $dispatchCost = $order->dispatch_cost / 100;
        $taxAmount = $order->tax_amount / 100;
        $total = $order->grand_total / 100;

        $company = $order->user->company;
        $branch = $order->user->branch;
        $user = $order->user;

        $clientName = $company->name ?? 'N/A';
        $clientRut = $company->tax_id ?? 'N/A';
        $branchFantasyName = $branch->fantasy_name ?? 'N/A';
        $userName = $user->name ?? 'N/A';
        $branchWithUser = "{$branchFantasyName} / {$userName}";
        $address = $branch->shipping_address ?? $branch->address ?? 'N/A';

        $formattedNeto = number_format($neto, 0, ',', '.');
        $formattedIva = number_format($taxAmount, 0, ',', '.');
        $formattedDispatchCost = number_format($dispatchCost, 0, ',', '.');
        $formattedTotal = number_format($total, 0, ',', '.');

        // Check if user role is Admin or Café to show subtotal
        $userRole = $order->user->roles->first()?->name;
        $showSubtotal = in_array($userRole, [\App\Enums\RoleName::ADMIN->value, \App\Enums\RoleName::CAFE->value]);

        $productRowsHtml = '';
        foreach ($order->orderLines as $line) {
            $product = $line->product;
            $subtotalLine = $line->total_price / 100;
            $productName = $product->name ?? 'N/A';
            $formattedSubtotal = number_format($subtotalLine, 0, ',', '.');

            $subtotalCell = $showSubtotal ? "<td class='right'>$ {$formattedSubtotal}</td>" : '';

            $productRowsHtml .= "
                <tr>
                    <td>{$productName}</td>
                    <td class='center'>{$line->quantity} UN</td>
                    {$subtotalCell}
                </tr>";
        }

        $subtotalHeader = $showSubtotal ? "<th class='col-subtotal'>Subtotal</th>" : '';

        // Show totals section only for Admin and Café roles
        $totalsHtml = $showSubtotal ? "
            <div class='totals'>
                <div><strong>Neto: $ {$formattedNeto}</strong></div>
                <div><strong>IVA: $ {$formattedIva}</strong></div>
                <div><strong>Transporte: $ {$formattedDispatchCost}</strong></div>
                <div><strong>TOTAL: $ {$formattedTotal}</strong></div>
            </div>" : '';

        $html = "
        <div class='voucher-container'>
            <div class='header'>
                <h1>Pedido N° {$order->id}</h1>
            </div>

            <table class='info-table'>
                <tr>
                    <td class='label'>Cliente</td>
                    <td>{$clientName}</td>
                </tr>
                <tr>
                    <td class='label'>RUT</td>
                    <td>{$clientRut}</td>
                </tr>
                <tr>
                    <td class='label'>Sucursal</td>
                    <td>{$branchWithUser}</td>
                </tr>
                <tr>
                    <td class='label'>Despacho</td>
                    <td>{$address}</td>
                </tr>
                <tr>
                    <td class='label'>Fecha despacho</td>
                    <td>{$dispatchDate}</td>
                </tr>
            </table>

            <table class='products-table'>
                <thead>
                    <tr>
                        <th class='col-product'>Producto</th>
                        <th class='col-qty'>Cant.</th>
                        {$subtotalHeader}
                    </tr>
                </thead>
                <tbody>
                    {$productRowsHtml}
                </tbody>
            </table>

            {$totalsHtml}

        </div>";

        return $html;
    }
}
