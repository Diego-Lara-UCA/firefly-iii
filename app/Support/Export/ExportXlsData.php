<?php

declare(strict_types=1);

namespace FireflyIII\Support\Export;

use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Api\V1\Requests\Data\Export\DefaultReportExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TransactionHistoryExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\BudgetExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\CategoryReportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TagReportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\ExpenseRevenueReportRequest;

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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Symfony\Component\HttpFoundation\Response;

class ExportXlsData {
    public function __construct() {}

    private function outputExcelFile(Spreadsheet $spreadsheet, string $filename): Response
    {
        // Guardar el archivo en un buffer de memoria
        $tempMemory = fopen('php://memory', 'r+');
        $writer = new Xlsx($spreadsheet);
        $writer->setIncludeCharts(true);
        $writer->save($tempMemory);
        rewind($tempMemory);
        $excelOutput = stream_get_contents($tempMemory);
        fclose($tempMemory);

        // Enviar la respuesta con headers explícitos
        return response($excelOutput, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    private function addSimplePieChart(
        Worksheet $sheet,
        string $chartNameInternal,
        array $dataLabels,
        array $dataValues,
        string $topLeftPosition,
        string $bottomRightPosition,
        ?string $chartTitleText = null
    ): void {
        $series = new DataSeries(
            DataSeries::TYPE_PIECHART,
            null,
            range(0, count($dataValues) - 1),
            $dataLabels,
            $dataLabels,
            $dataValues
        );

        $plotArea = new PlotArea(null, [$series]);
        $chartTitleObj = $chartTitleText ? new ChartMainTitle($chartTitleText) : null;
        $legendObj = new ChartLegend(ChartLegend::POSITION_RIGHT, null, false);

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
        array $seriesLegendLabels,
        array $xAxisCategories,
        array $seriesValues,
        string $topLeftPosition,
        string $bottomRightPosition,
        ?string $chartTitleText = null,
        ?string $yAxisTitleText = null
    ): void {
        $dataSeries = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($seriesValues) - 1),
            $seriesLegendLabels,
            $xAxisCategories,
            $seriesValues
        );

        $plotArea = new PlotArea(null, [$dataSeries]);
        $chartTitleObj = $chartTitleText ? new ChartMainTitle($chartTitleText) : null;
        $yAxisTitleObj = $yAxisTitleText ? new ChartMainTitle($yAxisTitleText) : null;
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

    private function addMultiSeriesBarChart(
    Worksheet $sheet,
    string $chartNameInternal,
    array $seriesLegendLabels,
    array $xAxisCategories,
    array $multiSeriesValues,
    string $topLeftPosition,
    string $bottomRightPosition,
    ?string $chartTitleText = null
    ): void {
        // 1. Crear la Serie de Datos
        $dataSeries = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,         // Agrupación: CLUSTERED para barras una al lado de la otra
            range(0, count($multiSeriesValues) - 1),// Orden de las series (ej. [0, 1] para dos series)
            $seriesLegendLabels,                    // Nombres para la leyenda (ej. "Ingresos", "Gastos")
            $xAxisCategories,                       // Etiquetas del eje X (ej. "Ene", "Feb", "Mar")
            $multiSeriesValues                      // Valores para cada serie
        );

        // Opcional: Para hacer las barras horizontales en lugar de columnas verticales, descomenta la siguiente línea:
        // $dataSeries->setPlotDirection(DataSeries::DIRECTION_BAR);

        // 2. Configurar el Área de Trazado, Leyenda y Título
        $plotArea = new PlotArea(null, [$dataSeries]);
        $chartTitleObj = $chartTitleText ? new ChartMainTitle($chartTitleText) : null;
        $legendObj = new ChartLegend(ChartLegend::POSITION_TOPRIGHT, null, false);

        // 3. Crear el Objeto Chart
        $chart = new Chart(
            $chartNameInternal,
            $chartTitleObj,
            $legendObj,
            $plotArea
        );
        $chart->setPlotVisibleOnly(false);

        // 4. Posicionar y Añadir el Gráfico a la Hoja
        $chart->setTopLeftPosition($topLeftPosition);
        $chart->setBottomRightPosition($bottomRightPosition);
        $sheet->addChart($chart);
    }

    private function writeAndCreatePieChart(
        Worksheet $mainSheet, Worksheet $dataSheet, string $dataSheetName, int &$dataSheetRow,
        array $chartData, array &$pieChartPositions, int &$pieChartIndex, string $title
    ): int {
        $dataSourceHeaderRow = $dataSheetRow;

        // --- CORRECCIÓN ---
        // Generar letras de columna explícitamente en lugar de usar literales 'A' y 'B'.
        $labelColLetter = Coordinate::stringFromColumnIndex(1); // 'A'
        $valueColLetter = Coordinate::stringFromColumnIndex(2); // 'B'

        $dataSheet->setCellValue($labelColLetter . $dataSourceHeaderRow, $chartData[0][0] ?? 'Label');
        $dataSheet->setCellValue($valueColLetter . $dataSourceHeaderRow, $chartData[0][1] ?? 'Value');
        
        $num_points = 0;
        for ($i = 1; $i < count($chartData); $i++) {
            $sheetRow = $dataSourceHeaderRow + $i;
            $dataSheet->setCellValue($labelColLetter . $sheetRow, $chartData[$i][0] ?? 'N/A');
            $dataSheet->setCellValue($valueColLetter . $sheetRow, (float)($chartData[$i][1] ?? 0));
            $num_points++;
        }

        if ($num_points > 0) {
            $data_start_row = $dataSourceHeaderRow + 1;
            $data_end_row = $dataSourceHeaderRow + $num_points;

            // Usar las variables para construir las referencias de celda
            $pieLabels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $dataSheetName . "'!$" . $labelColLetter . "$" . $data_start_row . ":$" . $labelColLetter . "$" . $data_end_row, null, $num_points)];
            $pieValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $dataSheetName . "'!$" . $valueColLetter . "$" . $data_start_row . ":$" . $valueColLetter . "$" . $data_end_row, null, $num_points)];
            
            $pos = $pieChartPositions[$pieChartIndex++];
            $endCol = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($pos['col']) + 7);
            
            $this->addSimplePieChart($mainSheet, 'pieChart' . $pieChartIndex, $pieLabels, $pieValues, $pos['col'] . $pos['row'], $endCol . ($pos['row'] + 15), $title);
            
            return $data_end_row + 2;
        }

        return $dataSourceHeaderRow + 2;
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

    public function GenerateDefaultReport (DefaultReportExportRequest $request): Response {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheetName = 'default_report';
            $sheet->setTitle($sheetName);
            $currentRow = 1;

            // --- Chart: "Account Balances" ---
            $chartDateLabelsSource = $validatedData['chartDateLabels'];
            $chartBalanceValuesSource = $validatedData['chartBalanceValues'];
            
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
            $filename = 'default_report_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            return $this->outputExcelFile($spreadsheet, $filename);
        } catch (\Throwable $e) {
            Log::error("Exception in GenerateDefaultReport: " . $e->getMessage());
            abort(500, 'Error generando el archivo Excel.');
        }
    }

    /**
     * Generate transaction history report export
     *
     * @throws FireflyException
     */
    
    public function GenerateTransactionReport(TransactionHistoryExportRequest $request): Response {
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
            $writer->setIncludeCharts(true);

            $filename = 'transaction_history_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            return $this->outputExcelFile($spreadsheet, $filename);
        } catch (\Throwable $e) {
            Log::error("Exception in GenerateTransactionReport: " . $e->getMessage());
            abort(500, 'Error generando el archivo Excel.');
        }
    }

    /**
     * Generate budget report export
     *
     * @throws FireflyException
     */
    
    public function GenerateBudgetReport(BudgetExportRequest $request): Response {
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
            return $this->outputExcelFile($spreadsheet, $filename);
        } catch (\Throwable $e) {
            Log::error("Exception in GenerateBudgetReport: " . $e->getMessage());
            abort(500, 'Error generando el archivo Excel.');
        }
    }

    public function GenerateCategoryReport(CategoryReportRequest $request): Response
    {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();

            $mainSheet = $spreadsheet->getActiveSheet();
            $mainSheetName = 'CategoryReport';
            $mainSheet->setTitle($mainSheetName);
            $currentRow = 1;

            $dataSheet = $spreadsheet->createSheet();
            $dataSheetName = 'ChartDataSource';
            $dataSheet->setTitle($dataSheetName);
            $dataSheetRow = 1;

            $this->createTable($mainSheet, $currentRow, "Accounts", ["Name", "Spent", "Earned", "Sum"], $validatedData['accountsTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Categories", ["Name", "Spent", "Earned", "Sum"], $validatedData['categoriesTableData'] ?? []);
            $accountPerCategoryHeaders = $validatedData['accountPerCategoryTableHeaders'] ?? ['Name'];
            $this->createTable($mainSheet, $currentRow, "Account per category", $accountPerCategoryHeaders, $validatedData['accountPerCategoryTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Average expense per destination account", ["Account", "Spent (average)", "Total", "Transaction count"], $validatedData['avgExpenseDestAccountTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Average earning per source account", ["Account", "Earned (average)", "Total", "Transaction count"], $validatedData['avgEarningSourceAccountTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Expenses (top 10)", ["Description", "Date", "Account", "Category", "Amount"], $validatedData['topExpensesTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Revenue / income (top 10)", ["Description", "Date", "Account", "Category", "Amount"], $validatedData['topRevenueTableData'] ?? []);
            
            $chartsStartRow = $currentRow + 1;
            
            $pieChartIndex = 0;
            $pieChartPositions = [
                ['row' => $chartsStartRow, 'col' => 'A'], ['row' => $chartsStartRow, 'col' => 'I'],
                ['row' => $chartsStartRow + 17, 'col' => 'A'], ['row' => $chartsStartRow + 17, 'col' => 'I'],
                ['row' => $chartsStartRow + 34, 'col' => 'A'], ['row' => $chartsStartRow + 34, 'col' => 'I'],
                ['row' => $chartsStartRow + 51, 'col' => 'A'],
            ];
            $pieChartConfigs = [
                ['dataKey' => 'expensePerCategoryChartData', 'title' => 'Expense per category'],
                ['dataKey' => 'incomePerCategoryChartData', 'title' => 'Income per category'],
                ['dataKey' => 'expensePerBudgetChartData', 'title' => 'Expense per budget'],
                ['dataKey' => 'expensesPerSourceAccountChartData', 'title' => 'Expenses per source account'],
                ['dataKey' => 'incomePerSourceAccountChartData', 'title' => 'Income per source account'],
                ['dataKey' => 'expensesPerDestinationAccountChartData', 'title' => 'Expenses per destination account'],
                ['dataKey' => 'incomePerDestinationAccountChartData', 'title' => 'Income per destination account'],
            ];

            foreach ($pieChartConfigs as $config) {
                $chartData = $validatedData[$config['dataKey']] ?? [];
                if (count($chartData) > 1) {
                    $dataSourceHeaderRow = $dataSheetRow;
                    
                    $labelColLetter = Coordinate::stringFromColumnIndex(1); // 'A'
                    $valueColLetter = Coordinate::stringFromColumnIndex(2); // 'B'

                    $dataSheet->setCellValue($labelColLetter . $dataSourceHeaderRow, $chartData[0][0] ?? 'Label');
                    $dataSheet->setCellValue($valueColLetter . $dataSourceHeaderRow, $chartData[0][1] ?? 'Value');
                    
                    $num_points = 0;
                    for ($i = 1; $i < count($chartData); $i++) {
                        $sheetRow = $dataSourceHeaderRow + $i;
                        $dataSheet->setCellValue($labelColLetter . $sheetRow, $chartData[$i][0] ?? 'N/A');
                        $dataSheet->setCellValue($valueColLetter . $sheetRow, (float)($chartData[$i][1] ?? 0));
                        $num_points++;
                    }
                    if ($num_points > 0) {
                        $data_start_row = $dataSourceHeaderRow + 1;
                        $data_end_row = $dataSourceHeaderRow + $num_points;
                        // Usar las variables para construir la referencia
                        $pieLabels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $dataSheetName . "'!$" . $labelColLetter . "$" . $data_start_row . ":$" . $labelColLetter . "$" . $data_end_row, null, $num_points)];
                        $pieValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $dataSheetName . "'!$" . $valueColLetter . "$" . $data_start_row . ":$" . $valueColLetter . "$" . $data_end_row, null, $num_points)];
                        
                        $pos = $pieChartPositions[$pieChartIndex++];
                        $endCol = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($pos['col']) + 7);
                        $this->addSimplePieChart($mainSheet, 'pieChart' . $pieChartIndex, $pieLabels, $pieValues, $pos['col'] . $pos['row'], $endCol . ($pos['row'] + 15), $config['title']);
                        
                        $dataSheetRow = $data_end_row + 2;
                    } else { $dataSheetRow++; }
                }
            }
            $currentRow = $chartsStartRow + 51 + 17;

            $barChartsCategoryData = $validatedData['barChartsPerCategoryData'] ?? [];
            foreach ($barChartsCategoryData as $index => $chartData) {
                $barChartTitle = $chartData['title'] ?? "Details";
                $categoriesDataSource = $chartData['categories'] ?? [];
                $valuesDataSource = $chartData['values'] ?? [];
                if (count($categoriesDataSource) > 1 && count($valuesDataSource) > 1) {
                    $dataSourceHeaderRow = $dataSheetRow;

                    $categoryColLetter = Coordinate::stringFromColumnIndex(1); // 'A'
                    $valueColLetter = Coordinate::stringFromColumnIndex(2); // 'B'

                    $dataSheet->setCellValue($categoryColLetter . $dataSourceHeaderRow, $categoriesDataSource[0][0] ?? 'Category');
                    $dataSheet->setCellValue($valueColLetter . $dataSourceHeaderRow, $valuesDataSource[0][0] ?? 'Value');
                    
                    $num_bar_points = count($categoriesDataSource) - 1;
                    $data_start_row = $dataSourceHeaderRow + 1;
                    $data_end_row = $dataSourceHeaderRow + $num_bar_points;
                    for ($i = 0; $i < $num_bar_points; $i++) {
                        $dataSheet->setCellValue($categoryColLetter . ($data_start_row + $i), $categoriesDataSource[$i + 1][0] ?? 'N/A');
                        $dataSheet->setCellValue($valueColLetter . ($data_start_row + $i), (float)($valuesDataSource[$i + 1][0] ?? 0));
                    }
                    if ($num_bar_points > 0) {
                        $barLegend = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $dataSheetName . "'!$" . $valueColLetter . "$" . $dataSourceHeaderRow, null, 1)];
                        $barCategories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $dataSheetName . "'!$" . $categoryColLetter . "$" . $data_start_row . ":$" . $categoryColLetter . "$" . $data_end_row, null, $num_bar_points)];
                        $barValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $dataSheetName . "'!$" . $valueColLetter . "$" . $data_start_row . ":$" . $valueColLetter . "$" . $data_end_row, null, $num_bar_points)];
                        
                        $chartDisplayStartRowBar = $currentRow;
                        $this->addSimpleBarChart($mainSheet, 'barChartCategory' . $index, $barLegend, $barCategories, $barValues, 'A' . $chartDisplayStartRowBar, 'H' . ($chartDisplayStartRowBar + 10 + $num_bar_points), $barChartTitle, "Amount");
                        
                        $currentRow = $chartDisplayStartRowBar + (10 + $num_bar_points) + 2;
                        $dataSheetRow = $data_end_row + 2;
                    }
                }
            }

            $dataSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VERYHIDDEN);
            $spreadsheet->setActiveSheetIndex(0);
            
            $highestColumn = $mainSheet->getHighestDataColumn();
            if ($highestColumn && $highestColumn >= 'A') {
                foreach (range('A', $highestColumn) as $col) {
                    $mainSheet->getColumnDimension($col)->setAutoSize(true);
                }
            }

            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);
            
            $filename = 'category_report_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            return $this->outputExcelFile($spreadsheet, $filename);
        } catch (\Throwable $e) {
            Log::error("Exception in GenerateCategoryReport: " . $e->getMessage());
            abort(500, 'Error generando el archivo Excel.');
        }
    }

    public function GenerateTagReport(TagReportRequest $request): Response
    {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();

            $mainSheet = $spreadsheet->getActiveSheet();
            $mainSheetName = 'TagReport';
            $mainSheet->setTitle($mainSheetName);
            $currentRow = 1;

            $dataSheet = $spreadsheet->createSheet();
            $dataSheetName = 'ChartDataSource';
            $dataSheet->setTitle($dataSheetName);
            $dataSheetRow = 1;

            $this->createTable($mainSheet, $currentRow, "Accounts", ["Name", "Spent", "Earned", "Sum"], $validatedData['accountsTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Tags", ["Name", "Spent", "Earned", "Sum"], $validatedData['tagsTableData'] ?? []);
            $accountPerTagHeaders = $validatedData['accountPerTagTableHeaders'] ?? ['Name'];
            $this->createTable($mainSheet, $currentRow, "Account per tag", $accountPerTagHeaders, $validatedData['accountPerTagTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Average expense per destination account", ["Account", "Spent (average)", "Total", "Transaction count"], $validatedData['avgExpenseDestAccountTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Average earning per source account", ["Account", "Earned (average)", "Total", "Transaction count"], $validatedData['avgEarningSourceAccountTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Expenses (top 10)", ["Description", "Date", "Account", "Tag", "Amount"], $validatedData['topExpensesTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Revenue / income (top 10)", ["Description", "Date", "Account", "Tag", "Amount"], $validatedData['topRevenueTableData'] ?? []);
            
            $chartsStartRow = $currentRow + 1;

            $pieChartIndex = 0;
            $pieChartPositions = [
                ['row' => $chartsStartRow, 'col' => 'A'], ['row' => $chartsStartRow, 'col' => 'I'],
                ['row' => $chartsStartRow + 17, 'col' => 'A'], ['row' => $chartsStartRow + 17, 'col' => 'I'],
                ['row' => $chartsStartRow + 34, 'col' => 'A'], ['row' => $chartsStartRow + 34, 'col' => 'I'],
                ['row' => $chartsStartRow + 51, 'col' => 'A'], ['row' => $chartsStartRow + 51, 'col' => 'I'],
            ];
            $pieChartConfigs = [
                ['dataKey' => 'expensePerTagChartData', 'title' => 'Expense per tag'],
                ['dataKey' => 'expensePerCategoryChartData', 'title' => 'Expense per category'],
                ['dataKey' => 'incomePerCategoryChartData', 'title' => 'Income per category'],
                ['dataKey' => 'expensePerBudgetChartData', 'title' => 'Expense per budget'],
                ['dataKey' => 'expensesPerSourceAccountChartData', 'title' => 'Expenses per source account'],
                ['dataKey' => 'incomePerSourceAccountChartData', 'title' => 'Income per source account'],
                ['dataKey' => 'expensesPerDestinationAccountChartData', 'title' => 'Expenses per destination account'],
                ['dataKey' => 'incomePerDestinationAccountChartData', 'title' => 'Income per destination account'],
            ];

            foreach ($pieChartConfigs as $config) {
                $chartData = $validatedData[$config['dataKey']] ?? [];
                if (!empty($chartData) && count($chartData) > 1) {
                    $dataSheetRow = $this->writeAndCreatePieChart($mainSheet, $dataSheet, $dataSheetName, $dataSheetRow, $chartData, $pieChartPositions, $pieChartIndex, $config['title']);
                }
            }
            $currentRow = $chartsStartRow + 68 + 2;

            $barChartsTagData = $validatedData['barChartsPerTagData'] ?? [];
            foreach ($barChartsTagData as $index => $chartData) {
                $barChartTitle = $chartData['title'] ?? 'Income and expenses';
                $categoriesDataSource = $chartData['categories'] ?? [];
                $seriesData = $chartData['series'] ?? [];

                if (count($categoriesDataSource) > 1 && !empty($seriesData)) {
                    $dataSourceHeaderRow = $dataSheetRow;
                    
                    $categoryColLetter = Coordinate::stringFromColumnIndex(1); // 'A'
                    $dataSheet->fromArray($categoriesDataSource, null, $categoryColLetter . $dataSourceHeaderRow);
                    
                    $num_points = count($categoriesDataSource) - 1;
                    $data_start_row = $dataSourceHeaderRow + 1;
                    $data_end_row = $dataSourceHeaderRow + $num_points;
                    
                    $barCategories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $dataSheetName . "'!$" . $categoryColLetter . "$" . $data_start_row . ":$" . $categoryColLetter . "$" . $data_end_row, null, $num_points)];
                    $multiSeriesValues = [];
                    $multiSeriesLegend = [];

                    foreach ($seriesData as $colIdx => $series) {
                        $seriesName = $series[0] ?? 'Series ' . ($colIdx + 1);
                        $seriesValues = $series[1] ?? [];
                        $valueColLetter = Coordinate::stringFromColumnIndex(2 + $colIdx);

                        $dataSheet->setCellValue($valueColLetter . $dataSourceHeaderRow, $seriesName);
                        for ($i = 0; $i < $num_points; $i++) {
                            $dataSheet->setCellValue($valueColLetter . ($data_start_row + $i), (float)($seriesValues[$i] ?? 0));
                        }
                        $multiSeriesLegend[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $dataSheetName . "'!$" . $valueColLetter . "$" . $dataSourceHeaderRow, null, 1);
                        $multiSeriesValues[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $dataSheetName . "'!$" . $valueColLetter . "$" . $data_start_row . ":$" . $valueColLetter . "$" . $data_end_row, null, $num_points);
                    }
                    
                    if (!empty($multiSeriesValues)) {
                        $chartDisplayStartRowBar = $currentRow;
                        $this->addMultiSeriesBarChart($mainSheet, 'barChartTag' . $index, $multiSeriesLegend, $barCategories, $multiSeriesValues, 'A' . $chartDisplayStartRowBar, 'J' . ($chartDisplayStartRowBar + 15), $barChartTitle);
                        $currentRow = $chartDisplayStartRowBar + 15 + 2;
                        $dataSheetRow = $data_end_row + 2;
                    }
                }
            }
            
            $dataSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VERYHIDDEN);
            $spreadsheet->setActiveSheetIndex(0);
            
            $highestColumn = $mainSheet->getHighestDataColumn();
            if ($highestColumn && $highestColumn >= 'A') {
                foreach (range('A', $highestColumn) as $col) {
                    $mainSheet->getColumnDimension($col)->setAutoSize(true);
                }
            }

            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);

            $filename = 'tag_report_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            return $this->outputExcelFile($spreadsheet, $filename);
        } catch (\Throwable $e) {
            Log::error("Exception in GenerateTagReport: " . $e->getMessage());
            abort(500, 'Error generando el archivo Excel.');
        }
    }

    public function GenerateExpenseRevenueReport(ExpenseRevenueReportRequest $request): Response
    {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();

            $mainSheet = $spreadsheet->getActiveSheet();
            $mainSheetName = 'ExpenseRevenueReport'; // Nombre de la hoja actualizado
            $mainSheet->setTitle($mainSheetName);
            $currentRow = 1;

            $dataSheet = $spreadsheet->createSheet();
            $dataSheetName = 'ChartDataSource';
            $dataSheet->setTitle($dataSheetName);
            $dataSheetRow = 1;

            // --- PASO 1: GENERAR TABLAS ---
            $this->createTable($mainSheet, $currentRow, "Accounts", ["Name", "Spent", "Earned", "Sum"], $validatedData['accountsTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Tags", ["Name", "Spent", "Earned", "Sum"], $validatedData['tagsTableData'] ?? []);
            $accountPerTagHeaders = $validatedData['accountPerTagTableHeaders'] ?? ['Name'];
            $this->createTable($mainSheet, $currentRow, "Account per tag", $accountPerTagHeaders, $validatedData['accountPerTagTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Average expense per destination account", ["Account", "Spent (average)", "Total", "Transaction count"], $validatedData['avgExpenseDestAccountTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Average earning per source account", ["Account", "Earned (average)", "Total", "Transaction count"], $validatedData['avgEarningSourceAccountTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Expenses (top 10)", ["Description", "Date", "Account", "Tag", "Amount"], $validatedData['topExpensesTableData'] ?? []);
            $this->createTable($mainSheet, $currentRow, "Revenue / income (top 10)", ["Description", "Date", "Account", "Tag", "Amount"], $validatedData['topRevenueTableData'] ?? []);
            
            $chartsStartRow = $currentRow + 1;

            // --- PASO 2: GENERAR GRÁFICOS DE PASTEL ---
            $pieChartIndex = 0;
            $pieChartPositions = [
                ['row' => $chartsStartRow, 'col' => 'A'], ['row' => $chartsStartRow, 'col' => 'I'],
                ['row' => $chartsStartRow + 17, 'col' => 'A'], ['row' => $chartsStartRow + 17, 'col' => 'I'],
                ['row' => $chartsStartRow + 34, 'col' => 'A'], ['row' => $chartsStartRow + 34, 'col' => 'I'],
                ['row' => $chartsStartRow + 51, 'col' => 'A'], ['row' => $chartsStartRow + 51, 'col' => 'I'],
            ];
            $pieChartConfigs = [
                ['dataKey' => 'expensePerTagChartData', 'title' => 'Expense per tag'],
                ['dataKey' => 'expensePerCategoryChartData', 'title' => 'Expense per category'],
                ['dataKey' => 'incomePerCategoryChartData', 'title' => 'Income per category'],
                ['dataKey' => 'expensePerBudgetChartData', 'title' => 'Expense per budget'],
                ['dataKey' => 'expensesPerSourceAccountChartData', 'title' => 'Expenses per source account'],
                ['dataKey' => 'incomePerSourceAccountChartData', 'title' => 'Income per source account'],
                ['dataKey' => 'expensesPerDestinationAccountChartData', 'title' => 'Expenses per destination account'],
                ['dataKey' => 'incomePerDestinationAccountChartData', 'title' => 'Income per destination account'],
            ];

            foreach ($pieChartConfigs as $config) {
                $chartData = $validatedData[$config['dataKey']] ?? [];
                if (!empty($chartData) && count($chartData) > 1) {
                    $dataSheetRow = $this->writeAndCreatePieChart($mainSheet, $dataSheet, $dataSheetName, $dataSheetRow, $chartData, $pieChartPositions, $pieChartIndex, $config['title']);
                }
            }
            $currentRow = $chartsStartRow + 68 + 2;

            // --- PASO 3: GENERAR GRÁFICOS DE BARRAS ---
            $barChartsTagData = $validatedData['barChartsPerTagData'] ?? [];
            foreach ($barChartsTagData as $index => $chartData) {
                // ... (la lógica interna de este bucle no cambia)
                 $barChartTitle = $chartData['title'] ?? 'Income and expenses';
                $categoriesDataSource = $chartData['categories'] ?? [];
                $seriesData = $chartData['series'] ?? [];

                if (count($categoriesDataSource) > 1 && !empty($seriesData)) {
                    $dataSourceHeaderRow = $dataSheetRow;
                    $categoryColLetter = Coordinate::stringFromColumnIndex(1);
                    $dataSheet->fromArray($categoriesDataSource, null, $categoryColLetter . $dataSourceHeaderRow);
                    
                    $num_points = count($categoriesDataSource) - 1;
                    $data_start_row = $dataSourceHeaderRow + 1;
                    $data_end_row = $dataSourceHeaderRow + $num_points;
                    
                    $barCategories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $dataSheetName . "'!$" . $categoryColLetter . "$" . $data_start_row . ":$" . $categoryColLetter . "$" . $data_end_row, null, $num_points)];
                    $multiSeriesValues = [];
                    $multiSeriesLegend = [];

                    foreach ($seriesData as $colIdx => $series) {
                        $seriesName = $series[0] ?? 'Series ' . ($colIdx + 1);
                        $seriesValues = $series[1] ?? [];
                        $valueColLetter = Coordinate::stringFromColumnIndex(2 + $colIdx);
                        $dataSheet->setCellValue($valueColLetter . $dataSourceHeaderRow, $seriesName);
                        for ($i = 0; $i < $num_points; $i++) {
                            $dataSheet->setCellValue($valueColLetter . ($data_start_row + $i), (float)($seriesValues[$i] ?? 0));
                        }
                        $multiSeriesLegend[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $dataSheetName . "'!$" . $valueColLetter . "$" . $dataSourceHeaderRow, null, 1);
                        $multiSeriesValues[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $dataSheetName . "'!$" . $valueColLetter . "$" . $data_start_row . ":$" . $valueColLetter . "$" . $data_end_row, null, $num_points);
                    }
                    
                    if (!empty($multiSeriesValues)) {
                        $chartDisplayStartRowBar = $currentRow;
                        $this->addMultiSeriesBarChart($mainSheet, 'barChartTag' . $index, $multiSeriesLegend, $barCategories, $multiSeriesValues, 'A' . $chartDisplayStartRowBar, 'J' . ($chartDisplayStartRowBar + 15), $barChartTitle);
                        $currentRow = $chartDisplayStartRowBar + 15 + 2;
                        $dataSheetRow = $data_end_row + 2;
                    }
                }
            }
            
            // --- PASO 4: FINALIZAR Y GUARDAR ---
            $dataSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VERYHIDDEN);
            $spreadsheet->setActiveSheetIndex(0);
            
            $highestColumn = $mainSheet->getHighestDataColumn();
            if ($highestColumn && $highestColumn >= 'A') {
                foreach (range('A', $highestColumn) as $col) {
                    $mainSheet->getColumnDimension($col)->setAutoSize(true);
                }
            }

            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true);

            $filename = 'expense_revenue_report_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            return $this->outputExcelFile($spreadsheet, $filename);
        } catch (\Throwable $e) {
            Log::error("Exception in GenerateExpenseRevenueReport: " . $e->getMessage());
            abort(500, 'Error generando el archivo Excel.');
        }
    }
}