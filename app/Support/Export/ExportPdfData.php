<?php

declare(strict_types=1);

namespace FireflyIII\Support\Export;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf;
use FireflyIII\Api\V1\Requests\Data\Export\BudgetExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\DefaultReportExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TransactionHistoryExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\CategoryReportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TagReportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\ExpenseRevenueReportRequest;
use CpChart\Data as pData;
use CpChart\Image as pImage;
use CpChart\Chart\Pie as pPie;

if (!defined('CHART_TEMP_DIR_CPCHART')) {
    $chartTempBasePath = function_exists('storage_path') ? storage_path('app' . DIRECTORY_SEPARATOR . 'temp_charts_cpchart') : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'temp_charts_cpchart';
    define('CHART_TEMP_DIR_CPCHART', $chartTempBasePath);
}

if (!defined('CPCHART_FONT_PATH_FOLDER')) {
    $projectRootGuess = realpath(__DIR__ . '/../../../../');
    $fontBasePath = function_exists('storage_path') ? storage_path('fonts') : ($projectRootGuess ? $projectRootGuess . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'fonts' : '');
    define('CPCHART_FONT_PATH_FOLDER', $fontBasePath);
}

class ExportPdfData
{
    private string $defaultFontFileAbsPath = '';
    private bool $pChartPrerequisitesMet = false;
    private const COLOR_PRIMARY_BLUE = 'FF4A86E8';
    private const COLOR_HEADER_FILL = 'FFD9E2F3';
    private const COLOR_TABLE_HEADER_TEXT = 'FF274060';
    private const COLOR_MAIN_TITLE_TEXT = 'FF274060';
    private const COLOR_SUBTITLE_TEXT = 'FF666666';
    private const COLOR_ROW_ALT_FILL = 'FFF3F6F9';

    public function __construct()
    {
        if (!is_dir(CHART_TEMP_DIR_CPCHART) && !@mkdir(CHART_TEMP_DIR_CPCHART, 0775, true) && !is_dir(CHART_TEMP_DIR_CPCHART)) {
            Log::critical('CRITICAL c-pchart: Failed to create temporary directory: ' . CHART_TEMP_DIR_CPCHART);
            return;
        }
        if (!is_writable(CHART_TEMP_DIR_CPCHART)) {
            Log::critical('CRITICAL c-pchart: Temporary directory ' . CHART_TEMP_DIR_CPCHART . ' IS NOT WRITABLE.');
            return;
        }

        if (!class_exists('CpChart\Image')) {
            Log::critical('CRITICAL c-pchart: Class CpChart\Image not found.');
            return;
        }

        $this->defaultFontFileAbsPath = $this->getFontPath("DejaVuSans.ttf");
        if (!file_exists($this->defaultFontFileAbsPath) || !is_readable($this->defaultFontFileAbsPath)) {
            $this->defaultFontFileAbsPath = $this->getFontPath("Verdana.ttf");
            if (!file_exists($this->defaultFontFileAbsPath) || !is_readable($this->defaultFontFileAbsPath)) {
                Log::critical("CRITICAL c-pchart: Default font (DejaVuSans.ttf or Verdana.ttf) could NOT be resolved to a valid, readable file.");
                return;
            }
        }
        
        if (!function_exists('gd_info')) {
            Log::critical('CRITICAL c-pchart: PHP GD extension is not installed or enabled.');
            return;
        }
        $gdInfo = gd_info();
        if (empty($gdInfo['PNG Support']) || empty($gdInfo['FreeType Support'])) {
            Log::critical('CRITICAL c-pchart: GD extension lacks PNG and/or FreeType support.');
            return;
        }

        $this->pChartPrerequisitesMet = true;
    }

    private function getFontPath(string $fontName = "DejaVuSans.ttf"): string
    {
        $internalFontsDir = '';
        if (class_exists('CpChart\Image')) {
            try {
                $reflector = new \ReflectionClass('CpChart\Image');
                $internalFontsDir = realpath(dirname($reflector->getFileName()) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'fonts');
                if (!$internalFontsDir || !is_dir($internalFontsDir)) $internalFontsDir = '';
            } catch (\ReflectionException $e) { $internalFontsDir = ''; }
        }

        if ($internalFontsDir) {
            $internalPath = $internalFontsDir . DIRECTORY_SEPARATOR . $fontName;
            if (file_exists($internalPath) && is_readable($internalPath)) return $internalPath;
        }
        if (defined('CPCHART_FONT_PATH_FOLDER') && CPCHART_FONT_PATH_FOLDER && is_dir(CPCHART_FONT_PATH_FOLDER)) {
            $customPath = rtrim(CPCHART_FONT_PATH_FOLDER, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fontName;
            if (file_exists($customPath) && is_readable($customPath)) return $customPath;
        }
        
        return $fontName;
    }
    
    private function setupPdfLayout(Spreadsheet &$spreadsheet, string $reportTitle): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        
        $spreadsheet->getProperties()->setCreator("Firefly III Report Generator")->setTitle($reportTitle);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageMargins()->setTop(1)->setRight(0.7)->setLeft(0.7)->setBottom(1);
        $sheet->getHeaderFooter()->setOddHeader('&C&B' . $reportTitle)->setOddFooter('&L&D &T&RPage &P of &N');
    }

    private function addReportHeader(Worksheet $sheet, int &$currentRow, string $reportTitle): void
    {
        $lastCol = 'J';
        $sheet->mergeCells("A{$currentRow}:{$lastCol}{$currentRow}");
        $sheet->setCellValue("A{$currentRow}", $reportTitle);
        $sheet->getStyle("A{$currentRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['argb' => self::COLOR_MAIN_TITLE_TEXT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($currentRow)->setRowHeight(30);
        $currentRow++;
        
        $sheet->mergeCells("A{$currentRow}:{$lastCol}{$currentRow}");
        $sheet->setCellValue("A{$currentRow}", "Generated on: " . Carbon::now()->format('F j, Y, g:i a'));
        $sheet->getStyle("A{$currentRow}")->applyFromArray([
            'font' => ['size' => 9, 'color' => ['argb' => self::COLOR_SUBTITLE_TEXT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $currentRow += 2;
    }

    private function addChartImageToSheet(Worksheet $sheet, ?string $imagePath, string $chartTitle, string $topLeftPosition, int $imageHeightInSheet = 250): void
    {
        if (!$imagePath || !file_exists($imagePath)) {
            Log::error("c-pchart: Cannot add image to sheet, file not found or path is null for chart: {$chartTitle}");
            $sheet->setCellValue($topLeftPosition, "Error generating chart: {$chartTitle}");
            return;
        }
        $drawing = new Drawing();
        $drawing->setName($chartTitle); $drawing->setDescription($chartTitle);
        $drawing->setPath($imagePath); $drawing->setCoordinates($topLeftPosition);
        $drawing->setHeight($imageHeightInSheet); $drawing->setWorksheet($sheet);
    }

    private function generateChartImage(string $type, string $filenameBase, array $seriesData, array $xLabels, string $title, int $width, int $height): ?string
    {
        if (!$this->pChartPrerequisitesMet) return null;
        if ((empty($xLabels) && $type !== 'pie') || empty($seriesData)) return null;

        try {
            $myData = new pData();
            $fontFile = $this->defaultFontFileAbsPath;
            
            if ($type === 'pie') {
                $points = []; $pieLabels = [];
                foreach($seriesData as $row) if (isset($row[0], $row[1]) && (float)$row[1] > 0) { $points[] = (float)$row[1]; $pieLabels[] = (string)$row[0]; }
                if (empty($points)) { return null; }
                $myData->addPoints($points, "Data"); $myData->addPoints($pieLabels, "Labels"); $myData->setAbscissa("Labels");
            } else {
                $validSeriesData = []; $maxPoints = 0;
                foreach ($seriesData as $serie) {
                    if (isset($serie[0], $serie[1]) && is_array($serie[1]) && !empty($serie[1])) {
                        $validSeriesData[] = $serie;
                        if (count($serie[1]) > $maxPoints) $maxPoints = count($serie[1]);
                    }
                }
                if (empty($validSeriesData)) { return null; }
                if ($maxPoints > 0 && count($xLabels) < $maxPoints) $xLabels = array_pad($xLabels, $maxPoints, "N/A");
                foreach ($validSeriesData as $serie) $myData->addPoints(array_pad($serie[1], $maxPoints, VOID), (string)$serie[0]);
                $myData->setAxisName(0, "Values");
                if ($maxPoints > 0) {
                    $myData->addPoints(array_slice($xLabels,0,$maxPoints), "Labels");
                    $myData->setSerieDescription("Labels", "Categories");
                    $myData->setAbscissa("Labels");
                }
            }

            $myPicture = new pImage($width, $height, $myData, TRUE);
            $myPicture->Antialias = TRUE;
            $myPicture->drawFilledRectangle(0, 0, $width - 1, $height - 1, ["R" => 255, "G" => 255, "B" => 255, "Surrounding" => 200]);
            $myPicture->setFontProperties(["FontName" => $fontFile, "FontSize" => 12, "R" => 80, "G" => 80, "B" => 80]);
            $myPicture->drawText($width / 2, 22, $title, ["Align" => TEXT_ALIGN_MIDDLEMIDDLE]);
            $myPicture->setFontProperties(["FontName" => $fontFile, "FontSize" => 8, "R" => 80, "G" => 80, "B" => 80]);
            
            if ($type !== 'pie') {
                $myPicture->setGraphArea(60, 50, $width - 40, $height - 50);
                $myPicture->drawScale(["CycleBackground" => TRUE, "DrawSubTicks" => TRUE, "GridR" => 230, "GridG" => 230, "GridB" => 230, "LabelingMethod"=>LABELING_ALL, "Mode" => ($type === 'bar' ? SCALE_MODE_START0 : SCALE_MODE_FLOATING)]);
                $myPicture->setFontProperties(["FontName" => $fontFile, "FontSize" => 9]);
                if ($type === 'line') $myPicture->drawLineChart();
                elseif ($type === 'bar') $myPicture->drawBarChart(["DisplayValues"=>TRUE,"DisplayR"=>255,"DisplayG"=>255,"DisplayB"=>255, "DisplayShadow"=>TRUE, "Surrounding"=>30]);
                $myPicture->setFontProperties(["FontName" => $fontFile, "FontSize" => 9]);
                $myPicture->drawLegend($width / 2, $height - 20, ["Style" => LEGEND_NOBORDER, "Mode" => LEGEND_HORIZONTAL, "Align" => TEXT_ALIGN_BOTTOMMIDDLE]);
            } else {
                $pieChart = new pPie($myPicture, $myData);
                $pieColors = [['R'=>69, 'G'=>114, 'B'=>167], ['R'=>17, 'G'=>167, 'B'=>153], ['R'=>246, 'G'=>168, 'B'=>0], ['R'=>219, 'G'=>50, 'B'=>54], ['R'=>148, 'G'=>54, 'B'=>219], ['R'=>219, 'G'=>54, 'B'=>131]];
                $pieChart->draw3DPie($width/2 - 40,$height/2 + 15, ["Radius"=> 100, "WriteValues"=>PIE_VALUE_PERCENTAGE, "DataGapAngle"=>10, "Border"=>TRUE, "Palette" => $pieColors]);
                $pieChart->drawPieLegend($width - 160, 40, ["Style"=>LEGEND_NOBORDER,"Mode"=>LEGEND_VERTICAL, "FontSize"=>8, "WritePValues"=>TRUE]);
            }

            $imagePath = CHART_TEMP_DIR_CPCHART . DIRECTORY_SEPARATOR . $filenameBase . ".png";
            if (file_exists($imagePath)) @unlink($imagePath);
            $myPicture->render($imagePath);
            return file_exists($imagePath) ? $imagePath : null;
        } catch (\Throwable $e) {
            Log::error("c-pchart ({$type}): Exception rendering chart '{$title}': " . $e->getMessage());
            return null;
        }
    }
    
    private function createStyledTable(Worksheet $sheet, int &$masterCurrentRow, string $title, array $headers, array $data, bool $hasTotalRow = false): void
    {
        if (empty($headers)) return;
        $startRow = $masterCurrentRow;
        $numColumns = count($headers);
        $firstColLetter = 'A';
        $lastColLetter = Coordinate::stringFromColumnIndex($numColumns);

        $sheet->mergeCells("{$firstColLetter}{$masterCurrentRow}:{$lastColLetter}{$masterCurrentRow}");
        $sheet->setCellValue("{$firstColLetter}{$masterCurrentRow}", $title);
        $sheet->getStyle("{$firstColLetter}{$masterCurrentRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => self::COLOR_TABLE_HEADER_TEXT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $sheet->getRowDimension($masterCurrentRow)->setRowHeight(25);
        $masterCurrentRow++;
        
        $headerActualRow = $masterCurrentRow;
        $sheet->fromArray($headers, null, "{$firstColLetter}{$headerActualRow}");
        $sheet->getStyle("{$firstColLetter}{$headerActualRow}:{$lastColLetter}{$headerActualRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF000000'], 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::COLOR_HEADER_FILL]]
        ]);
        $sheet->getRowDimension($headerActualRow)->setRowHeight(20);
        $masterCurrentRow++;
        
        $firstDataRow = $masterCurrentRow;
        if (empty($data)) {
            $sheet->mergeCells("{$firstColLetter}{$masterCurrentRow}:{$lastColLetter}{$masterCurrentRow}");
            $sheet->setCellValue("{$firstColLetter}{$masterCurrentRow}", 'No data available for this table.');
            $sheet->getStyle("{$firstColLetter}{$masterCurrentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $masterCurrentRow++;
        } else {
            $sheet->fromArray($data, null, "{$firstColLetter}{$masterCurrentRow}");
            $masterCurrentRow += count($data);
        }
        $lastDataRow = $masterCurrentRow - 1;

        if ($hasTotalRow) {
            $indexOfSumColumn = count($headers);
            $sumTotal = 0;
            foreach ($data as $rowData) if (isset($rowData[$indexOfSumColumn-1]) && is_numeric($rowData[$indexOfSumColumn-1])) $sumTotal += $rowData[$indexOfSumColumn-1];
            
            if ($indexOfSumColumn > 1) {
                $sheet->mergeCells("{$firstColLetter}{$masterCurrentRow}:" . Coordinate::stringFromColumnIndex($indexOfSumColumn - 1) . "{$masterCurrentRow}");
                $sheet->setCellValue("{$firstColLetter}{$masterCurrentRow}", "Total");
                $sheet->getStyle("{$firstColLetter}{$masterCurrentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($indexOfSumColumn) . $masterCurrentRow, $sumTotal);
            $sheet->getStyle("{$firstColLetter}{$masterCurrentRow}:{$lastColLetter}{$masterCurrentRow}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::COLOR_HEADER_FILL]],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => self::COLOR_PRIMARY_BLUE]]],
            ]);
            $masterCurrentRow++;
        }
        
        if($firstDataRow <= $lastDataRow) {
            for ($i = $firstDataRow; $i <= $lastDataRow; $i++) {
                $sheet->getRowDimension($i)->setRowHeight(18);
                if ($i % 2 != 0) {
                    $sheet->getStyle("{$firstColLetter}{$i}:{$lastColLetter}{$i}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_ROW_ALT_FILL);
                }
            }
        }

        $sheet->getStyle("{$firstColLetter}{$headerActualRow}:{$lastColLetter}{$headerActualRow}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::COLOR_PRIMARY_BLUE));
        $masterCurrentRow += 2;
    }

    private function cleanupChartImages(string $pattern): void
    {
        $files = glob($pattern);
        if ($files === false) return;
        foreach ($files as $file) if (is_file($file)) @unlink($file);
    }

    private function calculateChartHeightInRows(int $imageHeightPx, int $rowHeightPt = 15): int
    {
        if ($rowHeightPt <= 0) $rowHeightPt = 15;
        return (int)ceil($imageHeightPx / ($rowHeightPt * 1.33)) + 2;
    }

    public function GenerateDefaultReport (DefaultReportExportRequest $request): JsonResponse {
        try {
            $validatedData = $request->validated();
            $reportTitle = 'Default Financial Report';

            // 1. Generar la imagen del gráfico (si hay datos)
            $chartImagePath = null;
            if ($this->pChartPrerequisitesMet) {
                $chartDateLabelsSource = $validatedData['chartDateLabels'] ?? [];
                $chartBalanceValuesSource = $validatedData['chartBalanceValues'] ?? [];
                $cpChartXLabels = [];
                if (count($chartDateLabelsSource) > 1) for ($i = 1; $i < count($chartDateLabelsSource); $i++) $cpChartXLabels[] = (string)($chartDateLabelsSource[$i][0] ?? 'N/A');
                
                $cpChartSeriesData = [];
                if (isset($chartBalanceValuesSource[0][0]) && count($chartBalanceValuesSource) > 1) {
                    $serieName = (string)($chartBalanceValuesSource[0][0] ?? 'Balance'); $values = [];
                    for ($i = 1; $i < count($chartBalanceValuesSource); $i++) $values[] = (float)($chartBalanceValuesSource[$i][0] ?? 0);
                    if (!empty($values)) $cpChartSeriesData[] = [$serieName, $values];
                }

                if (!empty($cpChartXLabels) && !empty($cpChartSeriesData)) {
                    $imagePath = $this->generateChartImage('line', 'def_line_'.time().rand(100,999), $cpChartSeriesData, $cpChartXLabels, 'Account Balances', 750, 250);
                    if ($imagePath) {
                        $chartImagePath = $imagePath;
                    }
                }
            }

            // Helper para asegurar que todas las filas tengan el mismo número de columnas que los encabezados.
            $normalizeTableData = function (array $data, int $numHeaders): array {
                return array_map(function ($row) use ($numHeaders) {
                    $row = (array) $row;
                    $cellCount = count($row);
                    if ($cellCount < $numHeaders) {
                        return array_pad($row, $numHeaders, '');
                    }
                    return array_slice($row, 0, $numHeaders);
                }, $data);
            };

            // 2. Renderizar la plantilla Blade a HTML
            $html = view('pdf.default_report', [
                'reportTitle' => $reportTitle,
                'chartImagePath' => $chartImagePath,
                'accountBalancesTableData' => $normalizeTableData($validatedData['accountBalancesTableData'] ?? [], 4),
                'incomeVsExpensesTableData' => $normalizeTableData($validatedData['incomeVsExpensesTableData'] ?? [], 4),
                'revenueIncomeTableData' => $normalizeTableData($validatedData['revenueIncomeTableData'] ?? [], 3),
                'expensesTableData' => $normalizeTableData($validatedData['expensesTableData'] ?? [], 3),
                'budgetsTableData' => $normalizeTableData($validatedData['budgetsTableData'] ?? [], 8),
                'categoriesTableData' => $normalizeTableData($validatedData['categoriesTableData'] ?? [], 4),
                'budgetSplitAccountTableData' => $normalizeTableData($validatedData['budgetSplitAccountTableData'] ?? [], 2),
                'subscriptionsTableData' => $normalizeTableData($validatedData['subscriptionsTableData'] ?? [], 5),
            ])->render();

            // 3. Generar el PDF desde el HTML con Mpdf
            $mpdf = new \Mpdf\Mpdf(['tempDir' => storage_path('app/temp_mpdf')]);
            $mpdf->WriteHTML($html);

            // 4. Guardar el archivo PDF
            $filename = 'default_report_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = storage_path('app/reports');
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);

            // 5. Limpiar imágenes temporales
            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/def_*.png');

            return response()->json(['message' => 'PDF report saved.', 'filename' => $filename, 'path' => $filePath], 200);

        } catch (\Throwable $e) {
            Log::error("PDF DefaultReport (HTML Template): ".$e->getMessage()."\nTrace: ".$e->getTraceAsString());
            return response()->json(['error' => 'PDF Error.', 'details' => $e->getMessage()], 500);
        }
    }

    public function GenerateTransactionReport(TransactionHistoryExportRequest $request): JsonResponse {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $reportTitle = 'Transaction History Report';
            $sheet->setTitle('TransactionHistory');
            $this->setupPdfLayout($spreadsheet, $reportTitle);
            $currentRow = 1;
            $this->addReportHeader($sheet, $currentRow, $reportTitle);
            $imageHeight = 250;

            if (!$this->pChartPrerequisitesMet) {
                 $sheet->setCellValue('A'.$currentRow, "Chart configuration incomplete. Check server logs.");
            } else {
                $ccChartDateLabels = $validatedData['creditCardChartDateLabels'] ?? [];
                $ccChartDebtValues = $validatedData['creditCardChartDebtValues'] ?? [];
                $cpChartXLabels1 = [];
                if(count($ccChartDateLabels) > 1) for($i=1; $i<count($ccChartDateLabels); $i++) $cpChartXLabels1[] = (string)($ccChartDateLabels[$i][0] ?? 'N/A');
                $cpChartSeriesData1 = [];
                if(count($ccChartDebtValues) > 1 && isset($ccChartDebtValues[0][0])) {
                    $sName = (string)($ccChartDebtValues[0][0] ?? 'Debt'); $vals = [];
                    for($i=1; $i<count($ccChartDebtValues); $i++) $vals[] = (float)($ccChartDebtValues[$i][0] ?? 0);
                    if(!empty($vals)) $cpChartSeriesData1[] = [$sName, $vals];
                }
                $chartTitle1 = "Transactions for ".($validatedData['creditCardChartAccountName'] ?? 'N/A')." (".($validatedData['creditCardChartDateRange'] ?? 'N/A').")";
                if(!empty($cpChartXLabels1) && !empty($cpChartSeriesData1)) {
                    $imgPath1 = $this->generateChartImage('line','trans_line1_'.time().rand(100,999), $cpChartSeriesData1, $cpChartXLabels1, $chartTitle1, 750, $imageHeight);
                    $this->addChartImageToSheet($sheet, $imgPath1, $chartTitle1, 'B'.$currentRow, $imageHeight);
                    $currentRow += $this->calculateChartHeightInRows($imageHeight);
                } else { $sheet->setCellValue('A'.$currentRow, "No data for chart: {$chartTitle1}"); $currentRow+=2; }
                
                $cwChartDateLabels = $validatedData['cashWalletChartDateLabels'] ?? [];
                $cwChartMoneyValues = $validatedData['cashWalletChartMoneyValues'] ?? [];
                $cpChartXLabels2 = [];
                if(count($cwChartDateLabels) > 1) for($i=1; $i<count($cwChartDateLabels); $i++) $cpChartXLabels2[] = (string)($cwChartDateLabels[$i][0] ?? 'N/A');
                $cpChartSeriesData2 = [];
                if(count($cwChartMoneyValues) > 1 && isset($cwChartMoneyValues[0][0])) {
                    $sName = (string)($cwChartMoneyValues[0][0] ?? 'Money'); $vals = [];
                    for($i=1; $i<count($cwChartMoneyValues); $i++) $vals[] = (float)($cwChartMoneyValues[$i][0] ?? 0);
                    if(!empty($vals)) $cpChartSeriesData2[] = [$sName, $vals];
                }
                $chartTitle2 = "Cash Wallet";
                if(!empty($cpChartXLabels2) && !empty($cpChartSeriesData2)) {
                    $imgPath2 = $this->generateChartImage('line','trans_line2_'.time().rand(100,999), $cpChartSeriesData2, $cpChartXLabels2, $chartTitle2, 750, $imageHeight);
                    $this->addChartImageToSheet($sheet, $imgPath2, $chartTitle2, 'B'.$currentRow, $imageHeight);
                    $currentRow += $this->calculateChartHeightInRows($imageHeight);
                } else { $sheet->setCellValue('A'.$currentRow, "No data for chart: {$chartTitle2}"); $currentRow+=2; }
            }
            $currentRow++;

            $tableHeaders = ["Description", "Balance before", "Amount", "Balance after", "Date", "From", "To", "Budget", "Category", "Subscription", "Created at", "Updated at", "Notes", "Interest date", "Book date", "Processing date", "Due date", "Payment date", "Invoice date"];
            $this->createStyledTable($sheet, $currentRow, "Account Balance Transactions", $tableHeaders, $validatedData['accountBalanceTableData'] ?? []);

            for ($col = 'A'; $col <= 'S'; $col++) $sheet->getColumnDimension($col)->setAutoSize(true);
            $writer = new Mpdf($spreadsheet);
            $filename = 'transaction_history_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = function_exists('storage_path') ? storage_path('app/reports') : __DIR__.'/../../storage/app/reports';
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $writer->save($filePath);
            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/trans_*.png');
            return response()->json(['message' => 'PDF report saved.', 'filename' => $filename, 'path' => $filePath], 200);
        } catch (\Throwable $e) {
            Log::error("PDF TransactionReport (c-pchart): ".$e->getMessage()."\nTrace: ".$e->getTraceAsString());
            return response()->json(['error' => 'PDF Error.', 'details' => $e->getMessage()], 500);
        }
    }

    public function GenerateBudgetReport(BudgetExportRequest $request): JsonResponse {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $reportTitle = 'Budget Report';
            $sheet->setTitle('BudgetReport');
            $this->setupPdfLayout($spreadsheet, $reportTitle);
            $currentRow = 1;
            $this->addReportHeader($sheet, $currentRow, $reportTitle);

            $this->createStyledTable($sheet, $currentRow, "Accounts", ["Name", "Spent"], $validatedData['accountsTableData'] ?? []);
            $this->createStyledTable($sheet, $currentRow, "Budgets", ["Name", "Spent", "pct"], $validatedData['budgetsTableData'] ?? []);
            $this->createStyledTable($sheet, $currentRow, "Account per budget", ["Name", "Groceries", "Bills", "Car", "Going out"], $validatedData['accountPerBudgetTableData'] ?? []);

            if (!$this->pChartPrerequisitesMet) {
                $sheet->setCellValue('A'.$currentRow, "Chart configuration incomplete. Check server logs.");
            } else {
                $pieImageHeight = 280;
                $currentChartRow = $currentRow;
                
                $expensePerBudgetChartData = $validatedData['expensePerBudgetChartData'] ?? [];
                $pieData1 = [];
                if(count($expensePerBudgetChartData) > 1) for($i=1; $i<count($expensePerBudgetChartData); $i++) if(isset($expensePerBudgetChartData[$i][0],$expensePerBudgetChartData[$i][1])) $pieData1[] = [(string)$expensePerBudgetChartData[$i][0], (float)$expensePerBudgetChartData[$i][1]];
                $chartTitlePie1 = "Expense per budget";
                if(!empty($pieData1)){
                    $imgPathPie1 = $this->generateChartImage('pie','budget_pie1_'.time().rand(100,999), $pieData1, [], $chartTitlePie1, 400, $pieImageHeight);
                    $this->addChartImageToSheet($sheet, $imgPathPie1, $chartTitlePie1, 'A'.$currentChartRow, $pieImageHeight);
                } else { $sheet->setCellValue('A'.$currentChartRow, "No data for chart: {$chartTitlePie1}"); }

                $expensePerCategoryChartData = $validatedData['expensePerCategoryChartData'] ?? [];
                $pieData2 = [];
                if(count($expensePerCategoryChartData)>1) for($i=1; $i<count($expensePerCategoryChartData); $i++) if(isset($expensePerCategoryChartData[$i][0],$expensePerCategoryChartData[$i][1])) $pieData2[] = [(string)$expensePerCategoryChartData[$i][0], (float)$expensePerCategoryChartData[$i][1]];
                $chartTitlePie2 = "Expense per category";
                if(!empty($pieData2)){
                    $imgPathPie2 = $this->generateChartImage('pie','budget_pie2_'.time().rand(100,999), $pieData2, [], $chartTitlePie2, 400, $pieImageHeight);
                    $this->addChartImageToSheet($sheet, $imgPathPie2, $chartTitlePie2, 'F'.$currentChartRow, $pieImageHeight);
                } else { $sheet->setCellValue('F'.$currentChartRow, "No data for chart: {$chartTitlePie2}"); }
                
                $currentRow = $currentChartRow + $this->calculateChartHeightInRows($pieImageHeight);

                $barChartsBudgetData = $validatedData['barChartsPerBudgetData'] ?? [];
                foreach($barChartsBudgetData as $idx => $chartData) {
                    $barTitle = $chartData['title'] ?? "Budget Details ".($idx+1);
                    $categoriesSource = $chartData['categories'] ?? []; $valuesSource = $chartData['values'] ?? [];
                    $barXLabels = [];
                    if(count($categoriesSource)>1) for($i=1; $i<count($categoriesSource); $i++) $barXLabels[] = (string)($categoriesSource[$i][0] ?? 'N/A');
                    $barSeriesData = [];
                    if(count($valuesSource)>1 && isset($valuesSource[0][0])){
                        $sName = (string)($valuesSource[0][0] ?? 'Amount'); $sVals = [];
                        for($i=1; $i<count($valuesSource); $i++) $sVals[] = (float)($valuesSource[$i][0] ?? 0);
                        if(!empty($sVals)) $barSeriesData[] = [$sName, $sVals];
                    }
                    if(!empty($barXLabels) && !empty($barSeriesData)){
                        $imgPathBar = $this->generateChartImage('bar','budget_bar'.$idx.'_'.time().rand(100,999), $barSeriesData, $barXLabels, $barTitle, 750, 300);
                        $this->addChartImageToSheet($sheet, $imgPathBar, $barTitle, 'B'.$currentRow, 300);
                        $currentRow += $this->calculateChartHeightInRows(300);
                    } else { $sheet->setCellValue('A'.$currentRow, "No data for chart: {$barTitle}"); $currentRow+=2; }
                }
            }

            $this->createStyledTable($sheet, $currentRow, "Expenses (top 10)", ["Description", "Amount", "Date", "Category"], $validatedData['topExpensesTableData'] ?? []);

            for ($col = 'A'; $col <= 'J'; $col++) $sheet->getColumnDimension($col)->setAutoSize(true);
            $writer = new Mpdf($spreadsheet);
            $filename = 'budget_report_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = function_exists('storage_path') ? storage_path('app/reports') : __DIR__.'/../../storage/app/reports';
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $writer->save($filePath);
            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/budget_*.png');
            return response()->json(['message' => 'PDF report saved.', 'filename' => $filename, 'path' => $filePath], 200);
        } catch (\Throwable $e) {
            Log::error("PDF BudgetReport (c-pchart): ".$e->getMessage()."\nTrace: ".$e->getTraceAsString());
            return response()->json(['error' => 'PDF Error.', 'details' => $e->getMessage()], 500);
        }
    }
    
    public function GenerateCategoryReport(CategoryReportRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();
            $mainSheet = $spreadsheet->getActiveSheet();
            $reportTitle = 'Category Report';
            $mainSheet->setTitle('CategoryReport');
            $this->setupPdfLayout($spreadsheet, $reportTitle);
            $currentRow = 1;
            $this->addReportHeader($mainSheet, $currentRow, $reportTitle);

            $this->createStyledTable($mainSheet, $currentRow, "Accounts", ["Name", "Spent", "Earned", "Sum"], $validatedData['accountsTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Categories", ["Name", "Spent", "Earned", "Sum"], $validatedData['categoriesTableData'] ?? []);
            $accountPerCategoryHeaders = $validatedData['accountPerCategoryTableHeaders'] ?? ['Name'];
            $this->createStyledTable($mainSheet, $currentRow, "Account per category", $accountPerCategoryHeaders, $validatedData['accountPerCategoryTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Average expense per destination account", ["Account", "Spent (average)", "Total", "Transaction count"], $validatedData['avgExpenseDestAccountTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Average earning per source account", ["Account", "Earned (average)", "Total", "Transaction count"], $validatedData['avgEarningSourceAccountTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Expenses (top 10)", ["Description", "Date", "Account", "Category", "Amount"], $validatedData['topExpensesTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Revenue / income (top 10)", ["Description", "Date", "Account", "Category", "Amount"], $validatedData['topRevenueTableData'] ?? []);
            
            if (!$this->pChartPrerequisitesMet) {
                $mainSheet->setCellValue('A' . ($currentRow + 1), "Chart configuration incomplete. Check server logs.");
            } else {
                $chartsStartRow = $currentRow;
                $pieImageHeight = 280;
                $pieChartColumns = ['A', 'F'];
                $currentChartRow = $chartsStartRow;
                $currentChartColIndex = 0;
                
                $pieChartConfigs = [
                    ['dataKey' => 'expensePerCategoryChartData', 'title' => 'Expense per Category'],
                    ['dataKey' => 'incomePerCategoryChartData', 'title' => 'Income per Category'],
                    ['dataKey' => 'expensePerBudgetChartData', 'title' => 'Expense per Budget'],
                    ['dataKey' => 'expensesPerSourceAccountChartData', 'title' => 'Expenses per Source Account'],
                    ['dataKey' => 'incomePerSourceAccountChartData', 'title' => 'Income per Source Account'],
                    ['dataKey' => 'expensesPerDestinationAccountChartData', 'title' => 'Expenses per Destination Account'],
                    ['dataKey' => 'incomePerDestinationAccountChartData', 'title' => 'Income per Destination Account'],
                ];

                foreach ($pieChartConfigs as $config) {
                    $chartData = $validatedData[$config['dataKey']] ?? [];
                    $pieData = [];
                    if (count($chartData) > 1) for ($i = 1; $i < count($chartData); $i++) if (isset($chartData[$i][0], $chartData[$i][1])) $pieData[] = [(string)$chartData[$i][0], (float)$chartData[$i][1]];
                    
                    $col = $pieChartColumns[$currentChartColIndex];
                    $topLeftPosition = $col . $currentChartRow;

                    if (!empty($pieData)) {
                        $imagePath = $this->generateChartImage('pie', 'cat_pie_' . $currentChartColIndex . '_' . time() . rand(100,999), $pieData, [], $config['title'], 400, $pieImageHeight);
                        $this->addChartImageToSheet($mainSheet, $imagePath, $config['title'], $topLeftPosition, $pieImageHeight);
                    } else {
                        $mainSheet->setCellValue($topLeftPosition, "No data for chart: " . $config['title']);
                    }

                    $currentChartColIndex++;
                    if ($currentChartColIndex >= count($pieChartColumns)) {
                        $currentChartColIndex = 0;
                        $currentChartRow += $this->calculateChartHeightInRows($pieImageHeight);
                    }
                }
                
                if ($currentChartColIndex != 0) $currentChartRow += $this->calculateChartHeightInRows($pieImageHeight);
                $currentRow = $currentChartRow + 2;

                $barChartsCategoryData = $validatedData['barChartsPerCategoryData'] ?? [];
                foreach ($barChartsCategoryData as $index => $chartData) {
                    $barTitle = $chartData['title'] ?? "Details";
                    $categoriesDataSource = $chartData['categories'] ?? [];
                    $valuesDataSource = $chartData['values'] ?? [];
                    $barXLabels = [];
                    if(count($categoriesDataSource)>1) for($i=1; $i<count($categoriesDataSource); $i++) $barXLabels[] = (string)($categoriesDataSource[$i][0] ?? 'N/A');
                    
                    $barSeriesData = [];
                    if(count($valuesDataSource)>1 && isset($valuesDataSource[0][0])){
                        $seriesName = (string)($valuesDataSource[0][0] ?? 'Amount');
                        $seriesValues = [];
                        for($i=1; $i<count($valuesDataSource); $i++) $seriesValues[] = (float)($valuesDataSource[$i][0] ?? 0);
                        if(!empty($seriesValues)) $barSeriesData[] = [$seriesName, $seriesValues];
                    }

                    if(!empty($barXLabels) && !empty($barSeriesData)){
                        $imgPathBar = $this->generateChartImage('bar','cat_bar'.$index.'_'.time().rand(100,999), $barSeriesData, $barXLabels, $barTitle, 750, 300);
                        $this->addChartImageToSheet($mainSheet, $imgPathBar, $barTitle, 'B'.$currentRow, 300); 
                        $currentRow += $this->calculateChartHeightInRows(300);
                    } else { 
                        $mainSheet->setCellValue('A'.$currentRow, "No data for chart: {$barTitle}"); $currentRow+=2; 
                    }
                }
            }

            for ($col = 'A'; $col <= 'J'; $col++) $mainSheet->getColumnDimension($col)->setAutoSize(true);
            $writer = new Mpdf($spreadsheet);
            $filename = 'category_report_' . Carbon::now()->format('Ymd_His') . '.pdf';
            $storageDir = function_exists('storage_path') ? storage_path('app/reports') : __DIR__.'/../../storage/app/reports';
            if (!is_dir($storageDir)) { @mkdir($storageDir, 0755, true); }
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $writer->save($filePath);
            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/cat_*.png');
            return response()->json(['message' => 'Category report generated successfully.', 'filename' => $filename, 'path' => $filePath], 200);
        } catch (\Throwable $e) {
            Log::error("Exception in CategoryReport: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return response()->json(['error' => 'Error generating category report.', 'details' => $e->getMessage()], 500);
        }
    }

    public function GenerateTagReport(TagReportRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();
            $mainSheet = $spreadsheet->getActiveSheet();
            $reportTitle = 'Tag Report';
            $mainSheet->setTitle('TagReport');
            $this->setupPdfLayout($spreadsheet, $reportTitle);
            $currentRow = 1;
            $this->addReportHeader($mainSheet, $currentRow, $reportTitle);
            
            $this->createStyledTable($mainSheet, $currentRow, "Accounts", ["Name", "Spent", "Earned", "Sum"], $validatedData['accountsTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Tags", ["Name", "Spent", "Earned", "Sum"], $validatedData['tagsTableData'] ?? []);
            $accountPerTagHeaders = $validatedData['accountPerTagTableHeaders'] ?? ['Name'];
            $this->createStyledTable($mainSheet, $currentRow, "Account per tag", $accountPerTagHeaders, $validatedData['accountPerTagTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Average expense per destination account", ["Account", "Spent (average)", "Total", "Transaction count"], $validatedData['avgExpenseDestAccountTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Average earning per source account", ["Account", "Earned (average)", "Total", "Transaction count"], $validatedData['avgEarningSourceAccountTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Expenses (top 10)", ["Description", "Date", "Account", "Tag", "Amount"], $validatedData['topExpensesTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Revenue / income (top 10)", ["Description", "Date", "Account", "Tag", "Amount"], $validatedData['topRevenueTableData'] ?? []);
            
            if (!$this->pChartPrerequisitesMet) {
                $mainSheet->setCellValue('A' . ($currentRow + 1), "Chart configuration incomplete. Check server logs.");
            } else {
                $chartsStartRow = $currentRow;
                $pieImageHeight = 280;
                $pieChartColumns = ['A', 'F'];
                $currentChartRow = $chartsStartRow;
                $currentChartColIndex = 0;
                
                $pieChartConfigs = [
                    ['dataKey' => 'expensePerTagChartData', 'title' => 'Expense per Tag'],
                    ['dataKey' => 'expensePerCategoryChartData', 'title' => 'Expense per Category'],
                    ['dataKey' => 'incomePerCategoryChartData', 'title' => 'Income per Category'],
                    ['dataKey' => 'expensePerBudgetChartData', 'title' => 'Expense per Budget'],
                    ['dataKey' => 'expensesPerSourceAccountChartData', 'title' => 'Expenses per Source Account'],
                    ['dataKey' => 'incomePerSourceAccountChartData', 'title' => 'Income per Source Account'],
                    ['dataKey' => 'expensesPerDestinationAccountChartData', 'title' => 'Expenses per Destination Account'],
                    ['dataKey' => 'incomePerDestinationAccountChartData', 'title' => 'Income per Destination Account'],
                ];
                
                foreach ($pieChartConfigs as $config) {
                    $chartData = $validatedData[$config['dataKey']] ?? [];
                    $pieData = [];
                    if (count($chartData) > 1) for ($i = 1; $i < count($chartData); $i++) if (isset($chartData[$i][0], $chartData[$i][1])) $pieData[] = [(string)$chartData[$i][0], (float)$chartData[$i][1]];
                    
                    $col = $pieChartColumns[$currentChartColIndex];
                    $topLeftPosition = $col . $currentChartRow;

                    if (!empty($pieData)) {
                        $imagePath = $this->generateChartImage('pie', 'tag_pie_' . $currentChartColIndex . '_' . time() . rand(100,999), $pieData, [], $config['title'], 400, $pieImageHeight);
                        $this->addChartImageToSheet($mainSheet, $imagePath, $config['title'], $topLeftPosition, $pieImageHeight);
                    } else {
                        $mainSheet->setCellValue($topLeftPosition, "No data for chart: " . $config['title']);
                    }

                    $currentChartColIndex++;
                    if ($currentChartColIndex >= count($pieChartColumns)) {
                        $currentChartColIndex = 0;
                        $currentChartRow += $this->calculateChartHeightInRows($pieImageHeight);
                    }
                }
                
                if ($currentChartColIndex != 0) $currentChartRow += $this->calculateChartHeightInRows($pieImageHeight);
                $currentRow = $currentChartRow + 2;

                $barChartsTagData = $validatedData['barChartsPerTagData'] ?? [];
                $barImageHeight = 350;
                foreach ($barChartsTagData as $index => $chartData) {
                    $barTitle = $chartData['title'] ?? 'Income and expenses';
                    $categoriesDataSource = $chartData['categories'] ?? [];
                    $barSeriesData = $chartData['series'] ?? [];
                    $barXLabels = [];
                    if(count($categoriesDataSource)>1) for($i=1; $i<count($categoriesDataSource); $i++) $barXLabels[] = (string)($categoriesDataSource[$i][0] ?? 'N/A');
                    
                    if(!empty($barXLabels) && !empty($barSeriesData)){
                        $imgPathBar = $this->generateChartImage('bar','tag_bar'.$index.'_'.time().rand(100,999), $barSeriesData, $barXLabels, $barTitle, 750, $barImageHeight);
                        $this->addChartImageToSheet($mainSheet, $imgPathBar, $barTitle, 'B'.$currentRow, $barImageHeight);
                        $currentRow += $this->calculateChartHeightInRows($barImageHeight);
                    } else { 
                        $mainSheet->setCellValue('A'.$currentRow, "No data for chart: {$barTitle}"); $currentRow+=2; 
                    }
                }
            }
            
            for ($col = 'A'; $col <= 'J'; $col++) $mainSheet->getColumnDimension($col)->setAutoSize(true);
            $writer = new Mpdf($spreadsheet);
            $filename = 'tag_report_' . Carbon::now()->format('Ymd_His') . '.pdf';
            $storageDir = function_exists('storage_path') ? storage_path('app/reports') : __DIR__.'/../../storage/app/reports';
            if (!is_dir($storageDir)) { @mkdir($storageDir, 0755, true); }
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $writer->save($filePath);
            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/tag_*.png');
            return response()->json(['message' => 'Tag report generated successfully.', 'filename' => $filename, 'path' => $filePath], 200);
        } catch (\Throwable $e) {
            Log::error("Exception in GenerateTagReport: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return response()->json(['error' => 'Error generating tag report.', 'details' => $e->getMessage()], 500);
        }
    }

    public function GenerateExpenseRevenueReport(ExpenseRevenueReportRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();
            $mainSheet = $spreadsheet->getActiveSheet();
            $reportTitle = 'Expense and Revenue Report';
            $mainSheet->setTitle('ExpenseRevenueReport');
            $this->setupPdfLayout($spreadsheet, $reportTitle);
            $currentRow = 1;
            $this->addReportHeader($mainSheet, $currentRow, $reportTitle);

            $this->createStyledTable($mainSheet, $currentRow, "Accounts", ["Name", "Spent", "Earned", "Sum"], $validatedData['accountsTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Tags", ["Name", "Spent", "Earned", "Sum"], $validatedData['tagsTableData'] ?? []);
            $accountPerTagHeaders = $validatedData['accountPerTagTableHeaders'] ?? ['Name'];
            $this->createStyledTable($mainSheet, $currentRow, "Account per tag", $accountPerTagHeaders, $validatedData['accountPerTagTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Average expense per destination account", ["Account", "Spent (average)", "Total", "Transaction count"], $validatedData['avgExpenseDestAccountTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Average earning per source account", ["Account", "Earned (average)", "Total", "Transaction count"], $validatedData['avgEarningSourceAccountTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Expenses (top 10)", ["Description", "Date", "Account", "Tag", "Amount"], $validatedData['topExpensesTableData'] ?? []);
            $this->createStyledTable($mainSheet, $currentRow, "Revenue / income (top 10)", ["Description", "Date", "Account", "Tag", "Amount"], $validatedData['topRevenueTableData'] ?? []);
            
            if (!$this->pChartPrerequisitesMet) {
                $mainSheet->setCellValue('A' . ($currentRow + 1), "Chart configuration incomplete. Check server logs.");
            } else {
                $chartsStartRow = $currentRow;
                $pieImageHeight = 280;
                $pieChartColumns = ['A', 'F'];
                $currentChartRow = $chartsStartRow;
                $currentChartColIndex = 0;
                
                $pieChartConfigs = [
                    ['dataKey' => 'expensePerTagChartData', 'title' => 'Expense per Tag'],
                    ['dataKey' => 'expensePerCategoryChartData', 'title' => 'Expense per Category'],
                    ['dataKey' => 'incomePerCategoryChartData', 'title' => 'Income per Category'],
                    ['dataKey' => 'expensePerBudgetChartData', 'title' => 'Expense per Budget'],
                    ['dataKey' => 'expensesPerSourceAccountChartData', 'title' => 'Expenses per Source Account'],
                    ['dataKey' => 'incomePerSourceAccountChartData', 'title' => 'Income per Source Account'],
                    ['dataKey' => 'expensesPerDestinationAccountChartData', 'title' => 'Expenses per Destination Account'],
                    ['dataKey' => 'incomePerDestinationAccountChartData', 'title' => 'Income per Destination Account'],
                ];
                
                foreach ($pieChartConfigs as $config) {
                    $chartData = $validatedData[$config['dataKey']] ?? [];
                    $pieData = [];
                    if (count($chartData) > 1) {
                        for ($i = 1; $i < count($chartData); $i++) if (isset($chartData[$i][0], $chartData[$i][1])) $pieData[] = [(string)$chartData[$i][0], (float)$chartData[$i][1]];
                    }
                    
                    $col = $pieChartColumns[$currentChartColIndex];
                    $topLeftPosition = $col . $currentChartRow;

                    if (!empty($pieData)) {
                        $imagePath = $this->generateChartImage('pie', 'exprev_pie_' . $currentChartColIndex . '_' . time() . rand(100, 999), $pieData, [], $config['title'], 400, $pieImageHeight);
                        $this->addChartImageToSheet($mainSheet, $imagePath, $config['title'], $topLeftPosition, $pieImageHeight);
                    } else {
                        $mainSheet->setCellValue($topLeftPosition, "No data for chart: " . $config['title']);
                    }

                    $currentChartColIndex++;
                    if ($currentChartColIndex >= count($pieChartColumns)) {
                        $currentChartColIndex = 0;
                        $currentChartRow += $this->calculateChartHeightInRows($pieImageHeight);
                    }
                }
                
                if ($currentChartColIndex != 0) $currentChartRow += $this->calculateChartHeightInRows($pieImageHeight);
                $currentRow = $currentChartRow + 2;

                $barChartsTagData = $validatedData['barChartsPerTagData'] ?? [];
                $barImageHeight = 350;
                foreach ($barChartsTagData as $index => $chartData) {
                    $barTitle = $chartData['title'] ?? 'Income and expenses';
                    $categoriesDataSource = $chartData['categories'] ?? [];
                    $barSeriesData = $chartData['series'] ?? [];

                    $barXLabels = [];
                    if(count($categoriesDataSource)>1) for($i=1; $i<count($categoriesDataSource); $i++) $barXLabels[] = (string)($categoriesDataSource[$i][0] ?? 'N/A');
                    
                    if(!empty($barXLabels) && !empty($barSeriesData)){
                        $imgPathBar = $this->generateChartImage('bar','exprev_bar'.$index.'_'.time().rand(100,999), $barSeriesData, $barXLabels, $barTitle, 750, $barImageHeight);
                        $this->addChartImageToSheet($mainSheet, $imgPathBar, $barTitle, 'B'.$currentRow, $barImageHeight);
                        $currentRow += $this->calculateChartHeightInRows($barImageHeight);
                    } else { 
                        $mainSheet->setCellValue('A'.$currentRow, "No data for chart: {$barTitle}"); $currentRow+=2; 
                    }
                }
            }
            
            for ($col = 'A'; $col <= 'J'; $col++) $mainSheet->getColumnDimension($col)->setAutoSize(true);
            $writer = new Mpdf($spreadsheet);
            $filename = 'expense_revenue_report_' . Carbon::now()->format('Ymd_His') . '.pdf';
            $storageDir = function_exists('storage_path') ? storage_path('app/reports') : __DIR__.'/../../storage/app/reports';
            if (!is_dir($storageDir)) { @mkdir($storageDir, 0755, true); }
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $writer->save($filePath);

            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/exprev_*.png');

            return response()->json([
                'message' => 'Expense/Revenue report generated successfully.',
                'filename' => $filename,
                'path' => $filePath
            ], 200);
        } catch (\Throwable $e) {
            Log::error("Exception in GenerateExpenseRevenueReport: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return response()->json(['error' => 'Error generating expense/revenue report.', 'details' => $e->getMessage()], 500);
        }
    }
}
