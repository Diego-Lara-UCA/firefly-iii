<?php

declare(strict_types=1);

namespace FireflyIII\Support\Export;

use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Api\V1\Requests\Data\Export\DefaultFinancialXLSExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TransactionHistoryXLSExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\BudgetXLSExportRequest;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend as ChartLegend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title as ChartMainTitle;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ExportXlsData {
    public function __construct() {}

    private function addSimplePieChart(
        Worksheet $sheet,
        string $chartNameInternal,
        array $dataLabels,          // Array de DataSeriesValues para las etiquetas/categorías
        array $dataValues,          // Array de DataSeriesValues para los valores
        string $topLeftPosition,
        string $bottomRightPosition,
        ?string $chartTitleText = null
    ): void {
        // Para gráficos de pastel, las etiquetas (dataLabels) se usan a menudo para la leyenda y las categorías.
        // Los valores (dataValues) son los datos numéricos.
        $series = new DataSeries(
            DataSeries::TYPE_PIECHART,      // Tipo de gráfico: Pastel
            null,                           // Agrupación (no aplica o es null para pastel)
            range(0, count($dataValues) - 1),
            $dataLabels,                    // Usado para las leyendas/etiquetas de las porciones
            $dataLabels,                    // Usado también para las categorías/etiquetas de las porciones
            $dataValues
        );

        $plotArea = new PlotArea(null, [$series]);
        $chartTitleObj = $chartTitleText ? new ChartMainTitle($chartTitleText) : null;
        $legendObj = new ChartLegend(ChartLegend::POSITION_RIGHT, null, false); // Mostrar leyenda

        $chart = new Chart(
            $chartNameInternal,
            $chartTitleObj,
            $legendObj,
            $plotArea
        );
        $chart->setPlotVisibleOnly(false);

        $chart->setTopLeftPosition($topLeftPosition);
        $chart->setBottomRightPosition($bottomRightPosition);
        $sheet->addChart($chart);
    }

    private function addSimpleBarChart(
        Worksheet $sheet,
        string $chartNameInternal,
        array $seriesLegendLabels,  // Array de DataSeriesValues para la leyenda de la(s) serie(s)
        array $xAxisCategories,     // Array de DataSeriesValues para las categorías del eje X
        array $seriesValues,        // Array de DataSeriesValues para los valores Y de la(s) serie(s)
        string $topLeftPosition,
        string $bottomRightPosition,
        ?string $chartTitleText = null,
        ?string $yAxisTitleText = null // Nuevo: Título para el eje Y
    ): void {
        $dataSeries = new DataSeries(
            DataSeries::TYPE_BARCHART,      // Tipo de gráfico: Barras
            DataSeries::GROUPING_STANDARD,  // O CLUSTERED si tienes múltiples series por categoría
            range(0, count($seriesValues) - 1),
            $seriesLegendLabels,
            $xAxisCategories,
            $seriesValues
        );
        // $dataSeries->setPlotDirection(DataSeries::DIRECTION_BAR); // Para barras horizontales, opcional

        $plotArea = new PlotArea(null, [$dataSeries]);
        $chartTitleObj = $chartTitleText ? new ChartMainTitle($chartTitleText) : null;
        $yAxisTitleObj = $yAxisTitleText ? new ChartMainTitle($yAxisTitleText) : null; // Reutilizamos ChartMainTitle para ejes
        $legendObj = new ChartLegend(ChartLegend::POSITION_TOPRIGHT, null, false);

        $chart = new Chart(
            $chartNameInternal,
            $chartTitleObj,
            $legendObj,
            $plotArea,
            true, // plotVisibleOnly
            DataSeries::EMPTY_AS_GAP, // displayBlanksAs
            null, // xAxisLabel (se toma de las categorías)
            $yAxisTitleObj // yAxisLabel
        );
        $chart->setPlotVisibleOnly(false);

        $chart->setTopLeftPosition($topLeftPosition);
        $chart->setBottomRightPosition($bottomRightPosition);
        $sheet->addChart($chart);
    }

    /**
     * Create a simple line chart function
    */

    private function addSimpleLineChart(
        Worksheet $sheet,
        string $chartNameInternal,
        array $seriesLegendLabels,
        array $xAxisCategories,
        array $seriesValues,
        string $topLeftPosition,
        string $bottomRightPosition,
        ?string $chartTitleText = null
    ): void {
        $dataSeries = new DataSeries(
            DataSeries::TYPE_LINECHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($seriesValues) - 1),
            $seriesLegendLabels,
            $xAxisCategories,
            $seriesValues
        );

        $plotArea = new PlotArea(null, [$dataSeries]);
        $chartTitleObj = $chartTitleText ? new ChartMainTitle($chartTitleText) : null;

        $chart = new Chart(
            $chartNameInternal,
            $chartTitleObj,
            null,
            $plotArea
        );
        $chart->setPlotVisibleOnly(false);

        $chart->setTopLeftPosition($topLeftPosition);
        $chart->setBottomRightPosition($bottomRightPosition);
        $sheet->addChart($chart);
    }

    /**
     * Create table function
    */

    private function createTable(Worksheet $sheet, int &$masterCurrentRow, string $title, array $headers, array $data, bool $hasTotalRow = false): void
    {
        $tableTitleRow = $masterCurrentRow; 

        $firstColLetter = Coordinate::stringFromColumnIndex(1);
        $sheet->setCellValue($firstColLetter . $tableTitleRow, $title);
        $titleStyle = $sheet->getStyle($firstColLetter . $tableTitleRow);
        $titleStyle->getFont()->setBold(true);
        $titleStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        if (count($headers) > 0) {
             $lastHeaderColLetter = Coordinate::stringFromColumnIndex(count($headers));
             $sheet->mergeCells($firstColLetter . $tableTitleRow . ':' . $lastHeaderColLetter . $tableTitleRow);
        }
        $masterCurrentRow++;
        
        $headerActualRow = $masterCurrentRow; 

        if (!empty($headers)) {
            $currentColIndex = 1;
            foreach ($headers as $header) {
                $colLetter = Coordinate::stringFromColumnIndex($currentColIndex);
                $cellCoordinate = $colLetter . $headerActualRow;
                $sheet->setCellValue($cellCoordinate, $header);
                $sheet->getStyle($cellCoordinate)->getFont()->setBold(true);
                $currentColIndex++;
            }
            $masterCurrentRow++;
        }
        
        $firstDataActualRow = $masterCurrentRow;
        $sumTotal = 0;
        $indexOfSumColumn = count($headers); 

        if (empty($data)) {
            $emptyMsgCellCoordinate = $firstColLetter . $masterCurrentRow;
            $sheet->setCellValue($emptyMsgCellCoordinate, 'No data available for this table.');
            $emptyMsgStyle = $sheet->getStyle($emptyMsgCellCoordinate);
            $emptyMsgStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            if (count($headers) > 0) {
                $lastHeaderColLetter = Coordinate::stringFromColumnIndex(count($headers));
                $sheet->mergeCells($firstColLetter . $masterCurrentRow . ':' . $lastHeaderColLetter . $masterCurrentRow);
            }
            $masterCurrentRow++;
        } else {
            foreach ($data as $rowData) {
                $currentColIndex = 1;
                foreach ($rowData as $cellData) {
                    $colLetter = Coordinate::stringFromColumnIndex($currentColIndex);
                    $cellCoordinate = $colLetter . $masterCurrentRow;
                    if (is_numeric($cellData) && !is_string($cellData)) {
                        $sheet->setCellValueExplicit($cellCoordinate, $cellData, DataType::TYPE_NUMERIC);
                    } else {
                        $sheet->setCellValueExplicit($cellCoordinate, $cellData, DataType::TYPE_STRING);
                    }
                    if ($hasTotalRow && $currentColIndex === $indexOfSumColumn && is_numeric($cellData)) {
                        $sumTotal += $cellData;
                    }
                    $currentColIndex++;
                }
                $masterCurrentRow++;
            }
        }

        if ($hasTotalRow) {
            $totalRowActual = $masterCurrentRow;
            if ($indexOfSumColumn > 0) {
                $totalLabelColLetter = Coordinate::stringFromColumnIndex(max(1, $indexOfSumColumn - 1));
                $totalValueColLetter = Coordinate::stringFromColumnIndex($indexOfSumColumn);
                $totalLabelCellCoordinate = $totalLabelColLetter . $totalRowActual;
                $totalValueCellCoordinate = $totalValueColLetter . $totalRowActual;
                if ($indexOfSumColumn > 1) {
                     $sheet->setCellValue($totalLabelCellCoordinate, "Total");
                     $sheet->getStyle($totalLabelCellCoordinate)->getFont()->setBold(true);
                     $sheet->setCellValueExplicit($totalValueCellCoordinate, $sumTotal, DataType::TYPE_NUMERIC);
                     $sheet->getStyle($totalValueCellCoordinate)->getFont()->setBold(true);
                } else { 
                    $sheet->setCellValue($totalValueCellCoordinate, "Total: " . $sumTotal); 
                    $sheet->getStyle($totalValueCellCoordinate)->getFont()->setBold(true);
                }
            }
            $masterCurrentRow++;
        }

        $lastWrittenRowOfTableContent = $masterCurrentRow - 1;

        if (count($headers) > 0) {
            $startColForStyle = Coordinate::stringFromColumnIndex(1); 
            $endColForStyle = Coordinate::stringFromColumnIndex(count($headers));
            $rangeForBorders = $startColForStyle . $headerActualRow . ':' . $endColForStyle . $lastWrittenRowOfTableContent;
            
            if ($headerActualRow <= $lastWrittenRowOfTableContent) { 
                $styleArrayBorders = [
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]]
                ];
                $sheet->getStyle($rangeForBorders)->applyFromArray($styleArrayBorders);
            }

            $headerRangeForFill = $startColForStyle . $headerActualRow . ':' . $endColForStyle . $headerActualRow;
            $sheet->getStyle($headerRangeForFill)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
        }
        $masterCurrentRow++;
    }

    /**
     * Generate default report export function
     *
     * @throws FireflyException
    */

    public function GenerateDefaultReport (DefaultFinancialXLSExportRequest $request): JsonResponse {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheetName = 'default_report';
            $sheet->setTitle($sheetName);
            $currentRow = 1;

            // --- Chart: "Account Balances" ---
            $chartDateLabelsSource = [['Date'], ['Ene'], ['Feb'], ['Mar'], ['Abr'], ['May']];
            $chartBalanceValuesSource = [['Balance'], [100], [150], [120], [180], [160]];
            
            $dataSourceHeaderRow = $currentRow;
            $colLetterForDates = Coordinate::stringFromColumnIndex(1);
            $sheet->setCellValue($colLetterForDates . $dataSourceHeaderRow, $chartDateLabelsSource[0][0]);
            $colLetterForBalances = Coordinate::stringFromColumnIndex(2);
            $sheet->setCellValue($colLetterForBalances . $dataSourceHeaderRow, $chartBalanceValuesSource[0][0]);

            $num_actual_data_points = count($chartDateLabelsSource) - 1;
            $dsv_point_count = $num_actual_data_points;
            $data_for_chart_start_row = $dataSourceHeaderRow + 1;
            $data_for_chart_end_row = $dataSourceHeaderRow + $num_actual_data_points;

            for ($i = 0; $i < $num_actual_data_points; $i++) {
                $sheet->setCellValue($colLetterForDates . ($data_for_chart_start_row + $i), $chartDateLabelsSource[$i + 1][0]);
                $sheet->setCellValue($colLetterForBalances . ($data_for_chart_start_row + $i), (int)$chartBalanceValuesSource[$i + 1][0]);
            }
            
            $legendDSV = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'!$" . $colLetterForBalances . "$" . $dataSourceHeaderRow, null, 1)];
            $xAxisDSV = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'!$" . $colLetterForDates . "$" . $data_for_chart_start_row . ":$" . $colLetterForDates . "$" . $data_for_chart_end_row, null, $dsv_point_count)];
            $yValuesDSV = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $sheetName . "'!$" . $colLetterForBalances . "$" . $data_for_chart_start_row . ":$" . $colLetterForBalances . "$" . $data_for_chart_end_row, null, $dsv_point_count)];

            $chartDisplayStartRow = $data_for_chart_end_row + 2;
            $chartTopLeft = 'A' . $chartDisplayStartRow;
            $chartBottomRight = 'J' . ($chartDisplayStartRow + 20);

            $this->addSimpleLineChart($sheet, 'lineChartMain', $legendDSV, $xAxisDSV, $yValuesDSV, $chartTopLeft, $chartBottomRight, 'Account Balances');
            
            $currentRow = $chartDisplayStartRow + 20 + 2;

            // --- Generation of tables ---
            $this->createTable($sheet, $currentRow, "Account balances", ["Name", "Balance at start of period", "Balance at end of period", "Difference"], $validatedData['accountBalancesTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Income vs Expenses", ["Currency", "In", "Out", "Difference"], $validatedData['incomeVsExpensesTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Revenue/Income", ["Name", "Total", "Average"], $validatedData['revenueIncomeTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Expenses", ["Name", "Total", "Average"], $validatedData['expensesTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Budgets", ["Budget", "Date", "Budgeted", "pct (%)", "Spent", "pct (%)", "Left", "Overspent"], $validatedData['budgetsTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Categories", ["Category", "Spent", "Earned", "Sum"], $validatedData['categoriesTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Budget (split by account)", ["Budget", "Sum"], $validatedData['budgetSplitAccountTableData'] ?? [], true);
            $this->createTable($sheet, $currentRow, "Subscriptions", ["Name", "Minimum amount", "Maximum amount", "Expected on", "Paid"], $validatedData['subscriptionsTableData'] ?? []);


            $highestColumn = $sheet->getHighestDataColumn();
            if ($highestColumn) {
                foreach (range('A', $highestColumn) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            }

            // File saving
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            
            $filename = 'default_report_INCLUDE_CHARTS_TEST_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            $storageDir = storage_path('app/reports');
            if (!is_dir($storageDir)) { mkdir($storageDir, 0755, true); }
            $filePath = $storageDir . '/' . $filename;
            $writer->save($filePath);

            return response()->json(['message' => 'File saved. Added setIncludeCharts(true).', 'filename' => $filename, 'path' => $filePath], 200);

        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            Log::error("PhpSpreadsheet Exception: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString() . "\nFile: " . $e->getFile() . " Line: " . $e->getLine());
            return response()->json(['error' => 'Error generando el archivo Excel (PhpSpreadsheet).', 'details' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $errorTrace = $e->getTraceAsString();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();
            Log::error("Generic Exception in XLS Export: {$errorMessage}\nTrace: {$errorTrace}\nFile: {$errorFile} Line: {$errorLine}");
            return response()->json(['error' => 'Error generando el archivo Excel.', 'details' => $errorMessage, 'file' => $errorFile, 'line' => $errorLine], 500);
        }
    }

    /**
     * Generate transaction history report export
     *
     * @throws FireflyException
     */
    
    public function GenerateTransactionReport(TransactionHistoryXLSExportRequest $request): JsonResponse {
        try {
            $validatedData = $request->validated();

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheetName = 'TransactionHistory';
            $sheet->setTitle($sheetName);

            $currentRow = 1; // Fila actual para colocar elementos

            // --- Chart 1: "Chart for all transactions for account..." ---
            $creditCardAccountName = $validatedData['creditCardChartAccountName'] ?? 'N/A';
            $creditCardDateRange = $validatedData['creditCardChartDateRange'] ?? 'N/A';
            $creditCardChartTitle = "Chart for all transactions for account {$creditCardAccountName} between {$creditCardDateRange}";
            
            $ccChartDateLabels = $validatedData['creditCardChartDateLabels'] ?? [];
            $ccChartDebtValues = $validatedData['creditCardChartDebtValues'] ?? [];

            if (count($ccChartDateLabels) > 1 && count($ccChartDebtValues) > 1) {
                $dataSourceHeaderRow1 = $currentRow;
                $colLetterForDates1 = Coordinate::stringFromColumnIndex(1); // Col A para fechas
                $colLetterForValues1 = Coordinate::stringFromColumnIndex(2); // Col B para valores

                $sheet->setCellValue($colLetterForDates1 . $dataSourceHeaderRow1, $ccChartDateLabels[0][0] ?? 'Date');
                $sheet->setCellValue($colLetterForValues1 . $dataSourceHeaderRow1, $ccChartDebtValues[0][0] ?? 'Debt');

                $num_data_points1 = count($ccChartDateLabels) - 1;
                $data_start_row1 = $dataSourceHeaderRow1 + 1;
                $data_end_row1 = $dataSourceHeaderRow1 + $num_data_points1;

                for ($i = 0; $i < $num_data_points1; $i++) {
                    $sheet->setCellValue($colLetterForDates1 . ($data_start_row1 + $i), $ccChartDateLabels[$i + 1][0] ?? 'N/A');
                    $sheet->setCellValue($colLetterForValues1 . ($data_start_row1 + $i), (float)($ccChartDebtValues[$i + 1][0] ?? 0));
                }

                $legendDSV1 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'!$" . $colLetterForValues1 . "$" . $dataSourceHeaderRow1, null, 1)];
                $xAxisDSV1 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'!$" . $colLetterForDates1 . "$" . $data_start_row1 . ":$" . $colLetterForDates1 . "$" . $data_end_row1, null, $num_data_points1)];
                $yValuesDSV1 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $sheetName . "'!$" . $colLetterForValues1 . "$" . $data_start_row1 . ":$" . $colLetterForValues1 . "$" . $data_end_row1, null, $num_data_points1)];
                
                $chartDisplayStartRow1 = $data_end_row1 + 2;
                $this->addSimpleLineChart(
                    $sheet, 'creditCardDebtChart', $legendDSV1, $xAxisDSV1, $yValuesDSV1,
                    'A' . $chartDisplayStartRow1, 'J' . ($chartDisplayStartRow1 + 15),
                    $creditCardChartTitle
                );
                $currentRow = $chartDisplayStartRow1 + 15 + 2; // Actualizar currentRow
            }


            // --- Chart 2: "Cash Wallet" ---
            $cashWalletChartTitle = "Cash Wallet";
            $cwChartDateLabels = $validatedData['cashWalletChartDateLabels'] ?? [];
            $cwChartMoneyValues = $validatedData['cashWalletChartMoneyValues'] ?? [];

            if (count($cwChartDateLabels) > 1 && count($cwChartMoneyValues) > 1) {
                $dataSourceHeaderRow2 = $currentRow;
                // Reutilizamos columnas A y B para los datos fuente, pero en filas diferentes
                $colLetterForDates2 = Coordinate::stringFromColumnIndex(1); // Col A
                $colLetterForValues2 = Coordinate::stringFromColumnIndex(2); // Col B

                $sheet->setCellValue($colLetterForDates2 . $dataSourceHeaderRow2, $cwChartDateLabels[0][0] ?? 'Date');
                $sheet->setCellValue($colLetterForValues2 . $dataSourceHeaderRow2, $cwChartMoneyValues[0][0] ?? 'Money');

                $num_data_points2 = count($cwChartDateLabels) - 1;
                $data_start_row2 = $dataSourceHeaderRow2 + 1;
                $data_end_row2 = $dataSourceHeaderRow2 + $num_data_points2;

                for ($i = 0; $i < $num_data_points2; $i++) {
                    $sheet->setCellValue($colLetterForDates2 . ($data_start_row2 + $i), $cwChartDateLabels[$i + 1][0] ?? 'N/A');
                    $sheet->setCellValue($colLetterForValues2 . ($data_start_row2 + $i), (float)($cwChartMoneyValues[$i + 1][0] ?? 0));
                }

                $legendDSV2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'!$" . $colLetterForValues2 . "$" . $dataSourceHeaderRow2, null, 1)];
                $xAxisDSV2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'!$" . $colLetterForDates2 . "$" . $data_start_row2 . ":$" . $colLetterForDates2 . "$" . $data_end_row2, null, $num_data_points2)];
                $yValuesDSV2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $sheetName . "'!$" . $colLetterForValues2 . "$" . $data_start_row2 . ":$" . $colLetterForValues2 . "$" . $data_end_row2, null, $num_data_points2)];

                $chartDisplayStartRow2 = $data_end_row2 + 2;
                $this->addSimpleLineChart(
                    $sheet, 'cashWalletChart', $legendDSV2, $xAxisDSV2, $yValuesDSV2,
                    'A' . $chartDisplayStartRow2, 'J' . ($chartDisplayStartRow2 + 15),
                    $cashWalletChartTitle
                );
                $currentRow = $chartDisplayStartRow2 + 15 + 2; // Actualizar currentRow
            }

            // --- Table "Account balance" ---
            $tableHeaders = [
                "Description", "Balance before", "Amount", "Balance after", "Date", "From", "To", 
                "Budget", "Category", "Subscription", "Created at", "Updated at", "Notes", 
                "Interest date", "Book date", "Processing date", "Due date", "Payment date", "Invoice date"
            ];
            $tableData = $validatedData['accountBalanceTableData'] ?? [];
            $this->createTable($sheet, $currentRow, "Account balance", $tableHeaders, $tableData, false);

            $highestColumn = $sheet->getHighestDataColumn();
            if ($highestColumn) {
                foreach (range('A', $highestColumn) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            }

            // File saving
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true); // Importante para asegurar que los gráficos se guarden
            
            $filename = 'transaction_history_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            $storageDir = storage_path('app/reports'); // Guardar en storage/app/reports
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }
            $filePath = $storageDir . '/' . $filename;
            $writer->save($filePath);

            return response()->json([
                'message' => 'Transaction history report generated and saved successfully.',
                'filename' => $filename,
                'path' => $filePath
            ], 200);

        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            Log::error("PhpSpreadsheet Exception in TransactionHistoryReport: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString() . "\nFile: " . $e->getFile() . " Line: " . $e->getLine());
            return response()->json(['error' => 'Error generating transaction history (PhpSpreadsheet).', 'details' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $errorTrace = $e->getTraceAsString();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();
            Log::error("Generic Exception in TransactionHistoryReport: {$errorMessage}\nTrace: {$errorTrace}\nFile: {$errorFile} Line: {$errorLine}");
            return response()->json(['error' => 'Error generating transaction history.', 'details' => $errorMessage, 'file' => $errorFile, 'line' => $errorLine], 500);
        }
    }

    /**
     * Generate budget report export
     *
     * @throws FireflyException
     */
    
    public function GenerateBudgetReport(BudgetXLSExportRequest $request): JsonResponse {
        try {
            $validatedData = $request->validated();

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheetName = 'BudgetReport';
            $sheet->setTitle($sheetName);

            $currentRow = 1;

            // --- Preparación General de Datos para Gráficos ---
            $chartDataCol1 = Coordinate::stringFromColumnIndex(1); // A
            $chartDataCol2 = Coordinate::stringFromColumnIndex(2); // B

            // 1. Tabla "Accounts"
            $this->createTable($sheet, $currentRow, "Accounts", 
                ["Name", "Spent"], 
                $validatedData['accountsTableData'] ?? []
            );

            // 2. Tabla "Budgets"
            $this->createTable($sheet, $currentRow, "Budgets", 
                ["Name", "Spent", "pct"], 
                $validatedData['budgetsTableData'] ?? []
            );

            // 3. Tabla "Account per budget"
            // Las cabeceras aquí son específicas y deben coincidir con la estructura de tus datos.
            $accountPerBudgetHeaders = ["Name", "Groceries", "Bills", "Car", "Going out"]; 
            $this->createTable($sheet, $currentRow, "Account per budget", 
                $accountPerBudgetHeaders,
                $validatedData['accountPerBudgetTableData'] ?? []
            );

            // --- GRÁFICOS DE PASTEL ---

            // 4. Gráfico de Pastel "Expense per budget"
            $expensePerBudgetChartData = $validatedData['expensePerBudgetChartData'] ?? [];
            if (count($expensePerBudgetChartData) > 1) { // Asume que la primera fila es cabecera de datos fuente si se usa
                $dataSourceHeaderRowPie1 = $currentRow;
                $sheet->setCellValue($chartDataCol1 . $dataSourceHeaderRowPie1, $expensePerBudgetChartData[0][0] ?? 'Budget');    // Cabecera para etiquetas
                $sheet->setCellValue($chartDataCol2 . $dataSourceHeaderRowPie1, $expensePerBudgetChartData[0][1] ?? 'Amount');  // Cabecera para valores
                
                $num_pie_points1 = 0;
                for ($i = 1; $i < count($expensePerBudgetChartData); $i++) {
                    $sheet->setCellValue($chartDataCol1 . ($dataSourceHeaderRowPie1 + $i), $expensePerBudgetChartData[$i][0] ?? 'N/A');
                    $sheet->setCellValue($chartDataCol2 . ($dataSourceHeaderRowPie1 + $i), (float)($expensePerBudgetChartData[$i][1] ?? 0));
                    $num_pie_points1++;
                }
                $data_start_row_pie1 = $dataSourceHeaderRowPie1 + 1;
                $data_end_row_pie1 = $dataSourceHeaderRowPie1 + $num_pie_points1;

                if ($num_pie_points1 > 0) {
                    $pieLabels1 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'!$" . $chartDataCol1 . "$" . $data_start_row_pie1 . ":$" . $chartDataCol1 . "$" . $data_end_row_pie1, null, $num_pie_points1)];
                    $pieValues1 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $sheetName . "'!$" . $chartDataCol2 . "$" . $data_start_row_pie1 . ":$" . $chartDataCol2 . "$" . $data_end_row_pie1, null, $num_pie_points1)];
                    
                    $chartDisplayStartRowPie1 = $data_end_row_pie1 + 2;
                    $this->addSimplePieChart($sheet, 'pieChartBudget', $pieLabels1, $pieValues1,
                        'A' . $chartDisplayStartRowPie1, 'G' . ($chartDisplayStartRowPie1 + 12), "Expense per budget"
                    );
                    $currentRow = $chartDisplayStartRowPie1 + 12 + 2;
                } else { $currentRow = $dataSourceHeaderRowPie1 + 2; }
            }

            // 5. Gráfico de Pastel "Expense per category"
            $expensePerCategoryChartData = $validatedData['expensePerCategoryChartData'] ?? [];
            if (count($expensePerCategoryChartData) > 1) {
                $dataSourceHeaderRowPie2 = $currentRow;
                $sheet->setCellValue($chartDataCol1 . $dataSourceHeaderRowPie2, $expensePerCategoryChartData[0][0] ?? 'Category');
                $sheet->setCellValue($chartDataCol2 . $dataSourceHeaderRowPie2, $expensePerCategoryChartData[0][1] ?? 'Amount');
                $num_pie_points2 = 0;
                for ($i = 1; $i < count($expensePerCategoryChartData); $i++) {
                    $sheet->setCellValue($chartDataCol1 . ($dataSourceHeaderRowPie2 + $i), $expensePerCategoryChartData[$i][0] ?? 'N/A');
                    $sheet->setCellValue($chartDataCol2 . ($dataSourceHeaderRowPie2 + $i), (float)($expensePerCategoryChartData[$i][1] ?? 0));
                    $num_pie_points2++;
                }
                $data_start_row_pie2 = $dataSourceHeaderRowPie2 + 1;
                $data_end_row_pie2 = $dataSourceHeaderRowPie2 + $num_pie_points2;

                if ($num_pie_points2 > 0) {
                    $pieLabels2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'!$" . $chartDataCol1 . "$" . $data_start_row_pie2 . ":$" . $chartDataCol1 . "$" . $data_end_row_pie2, null, $num_pie_points2)];
                    $pieValues2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $sheetName . "'!$" . $chartDataCol2 . "$" . $data_start_row_pie2 . ":$" . $chartDataCol2 . "$" . $data_end_row_pie2, null, $num_pie_points2)];
                    $chartDisplayStartRowPie2 = $data_end_row_pie2 + 2;
                    $this->addSimplePieChart($sheet, 'pieChartCategory', $pieLabels2, $pieValues2,
                        'A' . $chartDisplayStartRowPie2, 'G' . ($chartDisplayStartRowPie2 + 12), "Expense per category"
                    );
                    $currentRow = $chartDisplayStartRowPie2 + 12 + 2;
                } else { $currentRow = $dataSourceHeaderRowPie2 + 2; }
            }

            // 6. Gráfico de Pastel "Expense per source account"
            $expensePerSourceAccountChartData = $validatedData['expensePerSourceAccountChartData'] ?? [];
            if (count($expensePerSourceAccountChartData) > 1) {
                // ... (Lógica similar para escribir datos y llamar a addSimplePieChart)
                Log::info('Datos para gráfico de cuentas origen procesados, currentRow: ' . $currentRow);
                 // TEMPORAL: Solo avanzar currentRow para no solapar
                // $currentRow += 15; // Ajusta este valor según sea necesario
            }

            // 7. Gráfico de Pastel "Expense per destination account"
            $expensePerDestinationAccountChartData = $validatedData['expensePerDestinationAccountChartData'] ?? [];
            if (count($expensePerDestinationAccountChartData) > 1) {
                // ... (Lógica similar para escribir datos y llamar a addSimplePieChart)
                Log::info('Datos para gráfico de cuentas destino procesados, currentRow: ' . $currentRow);
                // TEMPORAL: Solo avanzar currentRow para no solapar
                // $currentRow += 15; // Ajusta este valor según sea necesario
            }


            // 8. Gráficos de Barras por cada Presupuesto
            $barChartsBudgetData = $validatedData['barChartsPerBudgetData'] ?? [];
            foreach ($barChartsBudgetData as $index => $chartData) {
                $budgetName = $chartData['budgetName'] ?? "Budget_" . ($index + 1);
                $barChartTitle = $chartData['title'] ?? "Details for " . $budgetName;
                // Asume que categories/values son [['Header'], ['Data1'], ...]
                $categoriesDataSource = $chartData['categories'] ?? []; 
                $valuesDataSource = $chartData['values'] ?? [];     

                if (count($categoriesDataSource) > 1 && count($valuesDataSource) > 1 && (count($categoriesDataSource) == count($valuesDataSource))) {
                    $dataSourceHeaderRowBar = $currentRow;
                    $sheet->setCellValue($chartDataCol1 . $dataSourceHeaderRowBar, $categoriesDataSource[0][0] ?? 'Category');
                    $sheet->setCellValue($chartDataCol2 . $dataSourceHeaderRowBar, $valuesDataSource[0][0] ?? 'Value');

                    $num_bar_points = count($categoriesDataSource) - 1;
                    $data_start_row_bar = $dataSourceHeaderRowBar + 1;
                    $data_end_row_bar = $dataSourceHeaderRowBar + $num_bar_points;

                    for ($i = 0; $i < $num_bar_points; $i++) {
                        $sheet->setCellValue($chartDataCol1 . ($data_start_row_bar + $i), $categoriesDataSource[$i + 1][0] ?? 'N/A');
                        $sheet->setCellValue($chartDataCol2 . ($data_start_row_bar + $i), (float)($valuesDataSource[$i + 1][0] ?? 0));
                    }

                    if ($num_bar_points > 0) {
                        $barLegend = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'!$" . $chartDataCol2 . "$" . $dataSourceHeaderRowBar, null, 1)];
                        $barCategories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'!$" . $chartDataCol1 . "$" . $data_start_row_bar . ":$" . $chartDataCol1 . "$" . $data_end_row_bar, null, $num_bar_points)];
                        $barValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $sheetName . "'!$" . $chartDataCol2 . "$" . $data_start_row_bar . ":$" . $chartDataCol2 . "$" . $data_end_row_bar, null, $num_bar_points)];

                        $chartDisplayStartRowBar = $data_end_row_bar + 2;
                        // Usar la función addSimpleBarChart que definimos
                        $this->addSimpleBarChart($sheet, 'barChartBudget' . $index, $barLegend, $barCategories, $barValues,
                            'A' . $chartDisplayStartRowBar, 'H' . ($chartDisplayStartRowBar + 10 + $num_bar_points * 1), 
                            $barChartTitle, "Amount"
                        );
                        $currentRow = $chartDisplayStartRowBar + (10 + $num_bar_points * 1) + 2;
                    } else { $currentRow = $dataSourceHeaderRowBar + 2; }
                }
            }

            // 9. Tabla "Expenses (top 10)"
            $topExpensesHeaders = ["Description", "Amount", "Date", "Category"]; // Ejemplo de cabeceras
            $this->createTable($sheet, $currentRow, "Expenses (top 10)", 
                $topExpensesHeaders, 
                $validatedData['topExpensesTableData'] ?? []
            );

            // Autoajustar ancho de columnas para la hoja
            $highestColumn = $sheet->getHighestDataColumn();
            if ($highestColumn && $highestColumn >= 'A') { // Asegurar que highestColumn sea válido
                foreach (range('A', $highestColumn) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            }

            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            
            $filename = 'budget_report_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            $storageDir = storage_path('app/reports');
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }
            $filePath = $storageDir . '/' . $filename;
            $writer->save($filePath);

            return response()->json([
                'message' => 'Budget report generated and saved successfully.',
                'filename' => $filename,
                'path' => $filePath
            ], 200);

        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            Log::error("PhpSpreadsheet Exception in BudgetReport: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString() . "\nFile: " . $e->getFile() . " Line: " . $e->getLine());
            return response()->json(['error' => 'Error generating budget report (PhpSpreadsheet).', 'details' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $errorTrace = $e->getTraceAsString();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();
            Log::error("Generic Exception in BudgetReport: {$errorMessage}\nTrace: {$errorTrace}\nFile: {$errorFile} Line: {$errorLine}");
            return response()->json(['error' => 'Error generating budget report.', 'details' => $errorMessage, 'file' => $errorFile, 'line' => $errorLine], 500);
        }
    }
}