<?php

namespace App\Services\Vouchers;

use App\Contracts\Vouchers\GroupingStrategy;
use App\Models\Order;
use App\Services\Vouchers\Strategies\CompanyDispatchDateGrouping;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Consolidated Voucher PDF Generator
 *
 * Generates consolidated vouchers (multiple orders grouped by company + dispatch date)
 * Extends VoucherGenerator using Template Method pattern
 */
class ConsolidatedVoucherGenerator extends VoucherGenerator
{
    /**
     * Constructor - uses CompanyDispatchDateGrouping strategy by default
     */
    public function __construct(?GroupingStrategy $groupingStrategy = null)
    {
        parent::__construct($groupingStrategy ?? new CompanyDispatchDateGrouping());
    }

    /**
     * Generate HTML for a group of orders
     * Consolidates multiple orders from same company + dispatch date
     *
     * @param array $orderGroup Array of orders with same company_id and dispatch_date
     * @return string HTML content
     */
    protected function generateHtmlForGroup(array $orderGroup): string
    {
        if (empty($orderGroup)) {
            return '<div class="voucher-container"><p>No order data</p></div>';
        }

        return $this->generateConsolidatedVoucherHtml($orderGroup);
    }

    /**
     * Generate HTML for a consolidated voucher
     *
     * @param array $orderGroup Array of orders to consolidate
     * @return string
     */
    private function generateConsolidatedVoucherHtml(array $orderGroup): string
    {
        // Get title and date range from strategy
        $title = $this->groupingStrategy->getGroupTitle($orderGroup);
        $dispatchDateRange = $this->groupingStrategy->getDispatchDateRange($orderGroup);

        // Use first order for company/branch info (all orders in group have same company)
        $firstOrder = $orderGroup[0];
        $company = $firstOrder->user->company;
        $branch = $firstOrder->user->branch;
        $user = $firstOrder->user;

        $clientName = $company->name ?? 'N/A';
        $clientRut = $company->tax_id ?? 'N/A';
        $branchFantasyName = $branch->fantasy_name ?? 'N/A';
        $userName = $user->name ?? 'N/A';
        $branchWithUser = "{$branchFantasyName} / {$userName}";
        $address = $branch->shipping_address ?? $branch->address ?? 'N/A';

        // Calculate totals from all orders in the group
        $totalNeto = 0;
        $totalDispatchCost = 0;
        $totalTaxAmount = 0;
        $grandTotal = 0;

        // Consolidate all order lines from all orders
        $consolidatedLines = [];

        foreach ($orderGroup as $order) {
            $totalNeto += $order->total / 100;
            $totalDispatchCost += $order->dispatch_cost / 100;
            $totalTaxAmount += $order->tax_amount / 100;
            $grandTotal += $order->grand_total / 100;

            foreach ($order->orderLines as $line) {
                $productId = $line->product_id;
                $productName = $line->product->name ?? 'N/A';

                // Consolidate quantities for same product
                if (isset($consolidatedLines[$productId])) {
                    $consolidatedLines[$productId]['quantity'] += $line->quantity;
                    $consolidatedLines[$productId]['total_price'] += $line->total_price / 100;
                } else {
                    $consolidatedLines[$productId] = [
                        'product_name' => $productName,
                        'quantity' => $line->quantity,
                        'total_price' => $line->total_price / 100,
                    ];
                }
            }
        }

        // Format totals
        $formattedNeto = number_format($totalNeto, 0, ',', '.');
        $formattedIva = number_format($totalTaxAmount, 0, ',', '.');
        $formattedDispatchCost = number_format($totalDispatchCost, 0, ',', '.');
        $formattedTotal = number_format($grandTotal, 0, ',', '.');

        // Check if user role is Admin or Café to show subtotal
        $userRole = $firstOrder->user->roles->first()?->name;
        $showSubtotal = in_array($userRole, [\App\Enums\RoleName::ADMIN->value, \App\Enums\RoleName::CAFE->value]);

        // Generate product rows HTML
        $productRowsHtml = '';
        foreach ($consolidatedLines as $line) {
            $formattedSubtotal = number_format($line['total_price'], 0, ',', '.');
            $subtotalCell = $showSubtotal ? "<td class='right'>$ {$formattedSubtotal}</td>" : '';

            $productRowsHtml .= "
                <tr>
                    <td>{$line['product_name']}</td>
                    <td class='center'>{$line['quantity']} UN</td>
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
                <h1>{$title}</h1>
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
                    <td>{$dispatchDateRange}</td>
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
