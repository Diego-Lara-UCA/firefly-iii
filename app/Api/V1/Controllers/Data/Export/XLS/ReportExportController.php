<?php

namespace FireflyIII\Api\V1\Controllers\Data\Export\XLS;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Data\Export\DefaultFinancialXLSExportRequest;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title as ChartTitle;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ReportExportController extends Controller
{
    private \FireflyIII\Support\Export\ExportDataGenerator $exporter;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(
            function ($request, $next) {
                $this->exporter = app(\FireflyIII\Support\Export\ExportDataGenerator::class);
                $this->exporter->setUser(auth()->user());
                return $next($request);
            }
        );
    }

    public function DefaultReport(DefaultFinancialXLSExportRequest $request): JsonResponse
    {
        try {
            $spreadsheet = new Spreadsheet();
            
            // --- Configuración de la Hoja Principal para Tablas ---
            $mainSheet = $spreadsheet->getActiveSheet(); // Hoja 0 por defecto
            $mainSheetName = 'default_report';
            $mainSheet->setTitle($mainSheetName);
            $currentRowOnMainSheet = 1;

            // --- Configuración de Datos y Gráfico (en la misma hoja principal para esta prueba) ---
            // Usaremos datos fijos para asegurar que el gráfico tenga algo que mostrar
            $chartDateLabelsSource = [
                ['Date'],
                ['Ene'], ['Feb'], ['Mar'], ['Abr'], ['May'] // Etiquetas de texto simples
            ];
            $chartBalanceValuesSource = [
                ['Balance'], // Cabecera simple
                [100], [150], [120], [180], [160] // Valores variados
            ];
            
            $dataSheetForChart = $mainSheet; // El gráfico y sus datos estarán en la hoja principal
            $dataSourceHeaderRow = $currentRowOnMainSheet; // Fila 1 para cabeceras de datos del gráfico

            // Escribir datos fuente para el gráfico en la hoja principal
            $colIndexForDates = 1; // Columna A
            $colLetterForDates = Coordinate::stringFromColumnIndex($colIndexForDates);
            $dataSheetForChart->setCellValue($colLetterForDates . $dataSourceHeaderRow, $chartDateLabelsSource[0][0]);
            
            $colIndexForBalances = 2; // Columna B
            $colLetterForBalances = Coordinate::stringFromColumnIndex($colIndexForBalances);
            $dataSheetForChart->setCellValue($colLetterForBalances . $dataSourceHeaderRow, $chartBalanceValuesSource[0][0]);

            $num_actual_data_points = count($chartDateLabelsSource) - 1;
            $dsv_point_count = $num_actual_data_points;
            $data_for_chart_start_row = $dataSourceHeaderRow + 1; // Datos reales empiezan en fila 2
            $data_for_chart_end_row = $dataSourceHeaderRow + $num_actual_data_points; // Termina en fila 1 + 5 = 6

            for ($i = 0; $i < $num_actual_data_points; $i++) {
                $dataSheetForChart->setCellValue($colLetterForDates . ($data_for_chart_start_row + $i), $chartDateLabelsSource[$i + 1][0]);
                $dataSheetForChart->setCellValue($colLetterForBalances . ($data_for_chart_start_row + $i), $chartBalanceValuesSource[$i + 1][0]);
            }
            
            // Definiciones para DataSeriesValues
            $seriesLegendLabels = [ // Etiqueta para la leyenda
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $mainSheetName . "'!$" . $colLetterForBalances . "$" . $dataSourceHeaderRow, null, 1),
            ];
            $xAxisCategories = [ // Categorías del Eje X
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $mainSheetName . "'!$" . $colLetterForDates . "$" . $data_for_chart_start_row . ":$" . $colLetterForDates . "$" . $data_for_chart_end_row, null, $dsv_point_count),
            ];
            $yAxisValues = [ // Valores del Eje Y
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $mainSheetName . "'!$" . $colLetterForBalances . "$" . $data_for_chart_start_row . ":$" . $colLetterForBalances . "$" . $data_for_chart_end_row, null, $dsv_point_count),
            ];

            // Creación de la Serie: Tipo GRÁFICO DE LÍNEAS
            $series = new DataSeries(
                DataSeries::TYPE_LINECHART,       // <--- TIPO DE GRÁFICO DE LÍNEAS
                DataSeries::GROUPING_STANDARD,    
                range(0, count($yAxisValues) - 1), 
                $seriesLegendLabels,                             
                $xAxisCategories,                             
                $yAxisValues                              
            );

            $plotArea = new PlotArea(null, [$series]);
            // Intentaremos sin título ni leyenda explícitos en el objeto Chart por ahora para máxima simplicidad
            // $legend = new Legend(Legend::POSITION_RIGHT, null, false);
            // $chartTitle = new ChartTitle('Simple Line Chart');

            $chart = new Chart(
                'simpleLineChart2D', // Nombre del gráfico
                null, // Sin título de gráfico
                null, // Sin leyenda
                $plotArea
            );
            $chart->setPlotVisibleOnly(false); // Forzar que no solo se grafiquen celdas visibles

            // Posicionar el gráfico después de los datos fuente
            $chartDisplayStartRow = $data_for_chart_end_row + 2; // Ej: Fila 6 + 2 = Fila 8
            $chart->setTopLeftPosition('A' . $chartDisplayStartRow); 
            $chart->setBottomRightPosition('J' . ($chartDisplayStartRow + 20)); // Hacerlo grande
            $mainSheet->addChart($chart); 
            
            // Actualizar $currentRowOnMainSheet para el inicio de las tablas
            $currentRowOnMainSheet = $chartDisplayStartRow + 20 + 2;

            // --- Tablas (en la misma hoja principal) ---
            // $validatedData ya se obtuvo al inicio del try
            $this->createTable($mainSheet, $currentRowOnMainSheet, "Account balances", ["Name", "Balance at start of period", "Balance at end of period", "Difference"], $validatedData['accountBalancesTableData'] ?? []);
            $this->createTable($mainSheet, $currentRowOnMainSheet, "Income vs Expenses", ["Currency", "In", "Out", "Difference"], $validatedData['incomeVsExpensesTableData'] ?? []);
            // ... (resto de llamadas a createTable) ...
            $this->createTable($mainSheet, $currentRowOnMainSheet, "Revenue/Income", ["Name", "Total", "Average"], $validatedData['revenueIncomeTableData'] ?? []);
            $this->createTable($mainSheet, $currentRowOnMainSheet, "Expenses", ["Name", "Total", "Average"], $validatedData['expensesTableData'] ?? []);
            $this->createTable($mainSheet, $currentRowOnMainSheet, "Budgets", ["Budget", "Date", "Budgeted", "pct (%)", "Spent", "pct (%)", "Left", "Overspent"], $validatedData['budgetsTableData'] ?? []);
            $this->createTable($mainSheet, $currentRowOnMainSheet, "Categories", ["Category", "Spent", "Earned", "Sum"], $validatedData['categoriesTableData'] ?? []);
            $this->createTable($mainSheet, $currentRowOnMainSheet, "Budget (split by account)", ["Budget", "Sum"], $validatedData['budgetSplitAccountTableData'] ?? [], true);
            $this->createTable($mainSheet, $currentRowOnMainSheet, "Subscriptions", ["Name", "Minimum amount", "Maximum amount", "Expected on", "Paid"], $validatedData['subscriptionsTableData'] ?? []);


            // Autoajustar ancho de columnas para la hoja principal
            $highestColumnMain = $mainSheet->getHighestDataColumn();
            if ($highestColumnMain) {
                foreach (range('A', $highestColumnMain) as $col) {
                    $mainSheet->getColumnDimension($col)->setAutoSize(true);
                }
            }
            
            // --- Guardar el archivo Excel localmente ---
            $writer = new Xlsx($spreadsheet);
            $filename = 'default_report_LINE_CHART_TEST_' . Carbon::now()->format('Ymd_His') . '.xlsx';
            $storageDir = storage_path('app/reports');
            if (!is_dir($storageDir)) { mkdir($storageDir, 0755, true); }
            $filePath = $storageDir . '/' . $filename;
            $writer->save($filePath);

            return response()->json([
                'message' => 'File successfully saved with simple 2D LINE chart test!',
                'filename' => $filename,
                'path' => $filePath
            ], 200);

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

    // ... (tu método createTable con estilos aquí, el cual confirmaste que funciona bien)
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
}