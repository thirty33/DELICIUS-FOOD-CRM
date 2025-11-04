<?php

namespace App\Console\Commands;

use App\Exports\AdvanceOrderReportExport;
use App\Models\AdvanceOrder;
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
                            {--hide-total-elaborado : Hide total elaborado column}
                            {--hide-sobrantes : Hide sobrantes column}';

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
        $hideTotalElaborado = $this->option('hide-total-elaborado');
        $hideSobrantes = $this->option('hide-sobrantes');

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
        if ($hideTotalElaborado) {
            $this->info("Total elaborado column will be hidden");
        }
        if ($hideSobrantes) {
            $this->info("Sobrantes column will be hidden");
        }

        try {
            // Create the export instance with all options
            $export = new AdvanceOrderReportExport(
                $advanceOrderIds,
                !$hideCompanies,
                !$onlyInitialAdelanto,
                !$hideTotalElaborado,
                !$hideSobrantes
            );

            // Create filename
            $fileName = 'advance-orders/reports/advance-orders-' . $idsString . '-' . now()->format('Y-m-d-His') . '.xlsx';

            // Store the file directly to S3
            $stored = Excel::store($export, $fileName, 's3');

            if (!$stored) {
                throw new \Exception('Error storing file to S3');
            }

            // Get the signed temporary URL (valid for 24 hours)
            $signedUrl = Storage::disk('s3')->temporaryUrl($fileName, now()->addHours(24));

            $this->info('Report generated successfully!');
            $this->line('');
            $this->line('S3 Path: ' . $fileName);
            $this->line('Signed URL (valid for 24 hours):');
            $this->line($signedUrl);
            $this->line('');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error generating report: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
