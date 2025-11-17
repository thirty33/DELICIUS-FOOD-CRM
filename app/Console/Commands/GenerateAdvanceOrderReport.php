<?php

namespace App\Console\Commands;

use App\Exports\AdvanceOrderReportExport;
use App\Models\AdvanceOrder;
use App\Services\ImageSignerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class GenerateAdvanceOrderReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'advance-order:generate-report
                            {advanceOrderIds* : The IDs of the advance orders (space separated)}
                            {--hide-companies : Hide excluded companies columns in the report}
                            {--only-initial-adelanto : Show only initial adelanto column (hide other adelanto columns)}
                            {--hide-adelanto-inicial : Hide adelanto inicial column}
                            {--hide-total-elaborado : Hide total elaborado column}
                            {--hide-sobrantes : Hide sobrantes column}
                            {--hide-total-pedidos : Hide total pedidos column}
                            {--production-areas=* : Filter by production area IDs (space separated)}
                            {--disk=s3 : Storage disk to use (s3 or local)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Excel report for one or multiple advance orders';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $advanceOrderIds = $this->argument('advanceOrderIds');
        $hideCompanies = $this->option('hide-companies');
        $onlyInitialAdelanto = $this->option('only-initial-adelanto');
        $hideAdelantoInicial = $this->option('hide-adelanto-inicial');
        $hideTotalElaborado = $this->option('hide-total-elaborado');
        $hideSobrantes = $this->option('hide-sobrantes');
        $hideTotalPedidos = $this->option('hide-total-pedidos');
        $productionAreaIds = $this->option('production-areas') ?: [];
        $disk = $this->option('disk');

        // Validate that all advance orders exist
        $advanceOrders = AdvanceOrder::whereIn('id', $advanceOrderIds)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($advanceOrders->count() !== count($advanceOrderIds)) {
            $this->error("Some advance orders were not found.");
            $foundIds = $advanceOrders->pluck('id')->toArray();
            $notFound = array_diff($advanceOrderIds, $foundIds);
            $this->error("Not found IDs: " . implode(', ', $notFound));
            return Command::FAILURE;
        }

        $idsString = implode('-', $advanceOrderIds);
        $this->info("Generating report for Advance Orders: " . implode(', ', $advanceOrderIds));

        if ($hideCompanies) {
            $this->info("Companies columns will be hidden");
        }
        if ($onlyInitialAdelanto) {
            $this->info("Only initial adelanto column will be shown");
        }
        if ($hideAdelantoInicial) {
            $this->info("Adelanto inicial column will be hidden");
        }
        if ($hideTotalElaborado) {
            $this->info("Total elaborado column will be hidden");
        }
        if ($hideSobrantes) {
            $this->info("Sobrantes column will be hidden");
        }
        if ($hideTotalPedidos) {
            $this->info("Total pedidos column will be hidden");
        }
        if (!empty($productionAreaIds)) {
            $this->info("Filtering by production areas: " . implode(', ', $productionAreaIds));
        }

        try {
            // Create filename based on disk
            if ($disk === 'local') {
                $fileName = 'test-exports/advance-orders-' . $idsString . '-' . now()->format('Y-m-d-His') . '.xlsx';
            } else {
                $fileName = 'exports/advance-orders/advance-orders-' . $idsString . '-' . now()->format('Y-m-d-His') . '.xlsx';
            }

            // Create the export instance with all options (exportProcessId = 0 for console commands)
            $export = new AdvanceOrderReportExport(
                $advanceOrderIds,
                0, // exportProcessId (not tracked for console commands)
                !$hideCompanies,        // showExcludedCompanies
                !$onlyInitialAdelanto,  // showAllAdelantos
                !$hideTotalElaborado,   // showTotalElaborado
                !$hideSobrantes,        // showSobrantes
                !$hideAdelantoInicial,  // showAdelantoInicial
                !$hideTotalPedidos,     // showTotalPedidos
                $productionAreaIds      // productionAreaIds
            );

            // Store the file to specified disk with explicit format
            // Use download() to force synchronous execution, then manually upload to S3
            $fileContent = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

            Storage::disk($disk)->put($fileName, $fileContent);

            $this->info('Report generated successfully!');
            $this->line('');

            if ($disk === 's3') {
                // Get S3 temporary signed URL (valid for 24 hours)
                $signedUrl = Storage::disk('s3')->temporaryUrl($fileName, now()->addHours(24));

                $this->line('S3 Path: ' . $fileName);
                $this->line('S3 Signed URL (valid for 24 hours):');
                $this->line($signedUrl);
            } else {
                $this->line('Local Path: ' . storage_path('app/' . $fileName));
            }
            $this->line('');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error generating report: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
