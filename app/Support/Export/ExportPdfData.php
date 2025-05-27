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
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf;

use FireflyIII\Api\V1\Requests\Data\Export\BudgetExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\DefaultReportExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TransactionHistoryExportRequest;

use CpChart\Data as pData;
use CpChart\Image as pImage;
use CpChart\Chart\Pie as pPie;

if (!defined('CHART_TEMP_DIR_CPCHART')) {
    $chartTempBasePath = function_exists('storage_path') ? storage_path('app/temp_charts_cpchart') : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'temp_charts_cpchart';
    define('CHART_TEMP_DIR_CPCHART', $chartTempBasePath);
}

if (!defined('CPCHART_FONT_PATH')) {
    $fontBasePath = function_exists('storage_path') ? storage_path('fonts') : __DIR__ . '/../../storage/fonts';
    define('CPCHART_FONT_PATH', $fontBasePath);
}

class ExportPdfData {
    private string $defaultFontFile;
    private bool $pChartPrerequisitesMet = false;

    public function __construct() {
        if (!is_dir(CHART_TEMP_DIR_CPCHART)) {
            if (!@mkdir(CHART_TEMP_DIR_CPCHART, 0775, true) && !is_dir(CHART_TEMP_DIR_CPCHART)) {
                Log::critical('CRITICAL c-pchart: Failed to create temporary directory: ' . CHART_TEMP_DIR_CPCHART . '. Verify permissions.');
                return;
            }
        }
        if (!is_writable(CHART_TEMP_DIR_CPCHART)) {
            Log::critical('CRITICAL c-pchart: Temporary directory ' . CHART_TEMP_DIR_CPCHART . ' IS NOT WRITABLE.');
            return;
        } else {
            Log::info('c-pchart: Temp directory OK: ' . CHART_TEMP_DIR_CPCHART);
        }

        if (!is_dir(CPCHART_FONT_PATH)) {
             Log::critical('CRITICAL c-pchart: Font directory CPCHART_FONT_PATH IS NOT A VALID DIRECTORY: ' . CPCHART_FONT_PATH . '. Please create this directory.');
             return;
        } else {
            Log::info('c-pchart: Font directory OK: ' . CPCHART_FONT_PATH);
        }

        $this->defaultFontFile = rtrim(CPCHART_FONT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "DejaVuSans.ttf";
        if (!file_exists($this->defaultFontFile)) {
            $errorMessage = "CRITICAL c-pchart: Default font DejaVuSans.ttf NOT FOUND at configured path: {$this->defaultFontFile}. c-pchart charts will fail. Ensure CPCHART_FONT_PATH is correctly defined and DejaVuSans.ttf exists in that folder.";
            Log::critical($errorMessage);
            return;
        } elseif (!is_readable($this->defaultFontFile)) {
            Log::critical("CRITICAL c-pchart: Default font {$this->defaultFontFile} exists BUT IS NOT READABLE by the PHP process.");
            return;
        } else {
            Log::info("c-pchart: Default font found and readable at: {$this->defaultFontFile}");
        }

        if (!function_exists('gd_info')) {
            Log::critical('CRITICAL c-pchart: PHP GD extension is not installed or enabled. It is required to generate images.');
            return;
        } else {
            $gdInfo = gd_info();
            if (empty($gdInfo['PNG Support']) || empty($gdInfo['FreeType Support'])) {
                 Log::critical('CRITICAL c-pchart: GD extension is enabled, but it appears to LACK SUPPORT FOR PNG and/or FreeType (required for TTF fonts). Review your GD configuration.');
                 return;
            }
            Log::info('c-pchart: GD extension detected with PNG and FreeType support.');
        }
        $this->pChartPrerequisitesMet = true;
    }

    private function getFontPath(string $fontName = "DejaVuSans.ttf"): string {
        if (!$this->pChartPrerequisitesMet) return $fontName;

        if ($fontName === "DejaVuSans.ttf" && $this->defaultFontFile && file_exists($this->defaultFontFile)) {
            return $this->defaultFontFile;
        }
        $customFontPath = rtrim(CPCHART_FONT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fontName;
        if (file_exists($customFontPath) && is_readable($customFontPath)) {
            return $customFontPath;
        }
        Log::error("c-pchart: Requested font '{$fontName}' NOT FOUND or NOT READABLE at '{$customFontPath}'. Using fallback '{$this->defaultFontFile}' or just the name.");
        return file_exists($this->defaultFontFile) ? $this->defaultFontFile : $fontName;
    }

    private function addChartImageToSheet(Worksheet $sheet, string $imagePath, string $chartTitle, string $topLeftPosition, int $imageHeightInSheet = 250): void {
        if (!file_exists($imagePath)) {
            Log::error("c-pchart: Cannot add image to sheet, file not found: {$imagePath}");
            $sheet->setCellValue($topLeftPosition, "Error: Chart image '{$chartTitle}' not found.");
            return;
        }
        $drawing = new Drawing();
        $drawing->setName($chartTitle); $drawing->setDescription($chartTitle);
        $drawing->setPath($imagePath); $drawing->setCoordinates($topLeftPosition);
        $drawing->setHeight($imageHeightInSheet); $drawing->setWorksheet($sheet);
    }

    private function generateChartImage(string $type, string $filenameBase, array $seriesData, array $xLabels, string $title, int $width, int $height): ?string {
        if (!$this->pChartPrerequisitesMet) {
            Log::error("c-pchart ({$type}): Prerequisites not met, will not attempt to generate '{$title}'. Review CRITICAL logs from constructor.");
            return null;
        }
        if (empty($xLabels) || empty($seriesData)) { Log::warning("c-pchart ({$type}): Insufficient data for '{$title}'."); return null; }

        $validSeriesData = []; $maxPoints = 0;
        foreach ($seriesData as $serie) {
            if (isset($serie[0], $serie[1]) && is_array($serie[1]) && !empty($serie[1])) {
                $validSeriesData[] = $serie;
                if (count($serie[1]) > $maxPoints) $maxPoints = count($serie[1]);
            }
        }
        if (empty($validSeriesData)) { Log::warning("c-pchart ({$type}): No valid series with data points for '{$title}'."); return null; }
        if ($maxPoints > 0 && count($xLabels) < $maxPoints) $xLabels = array_pad($xLabels, $maxPoints, "N/A");

        try {
            $myData = new pData();
            foreach ($validSeriesData as $serie) $myData->addPoints(array_pad($serie[1], $maxPoints, VOID), (string)$serie[0]);
            $myData->setAxisName(0, "Values");
            if ($maxPoints > 0) {
                 $myData->addPoints(array_slice($xLabels,0,$maxPoints), "Labels");
                 $myData->setSerieDescription("Labels", "Categories");
                 $myData->setAbscissa("Labels");
            } else {
                $myData->addPoints(["No Data"], "Labels");
                $myData->setAbscissa("Labels");
            }

            $myPicture = new pImage($width, $height, $myData, TRUE);
            $myPicture->Antialias = TRUE;
            $fontFile = $this->getFontPath("DejaVuSans.ttf");
            if (!file_exists($fontFile) || !is_readable($fontFile)) {
                Log::critical("c-pchart ({$type}): CRITICAL FAILURE - Font '{$fontFile}' is not a valid/readable file for '{$title}'. Cannot continue with this chart.");
                return null;
            }

            $myPicture->drawFilledRectangle(0, 0, $width - 1, $height - 1, ["R" => 240, "G" => 240, "B" => 240]);
            $myPicture->drawRectangle(0,0,$width-1,$height-1,["R"=>200,"G"=>200,"B"=>200]);
            $myPicture->setFontProperties(["FontName" => $fontFile, "FontSize" => 11]);
            $myPicture->drawText($width / 2, 25, $title, ["Align" => TEXT_ALIGN_MIDDLEMIDDLE]);
            $myPicture->setFontProperties(["FontName" => $fontFile, "FontSize" => 7]);
            $myPicture->setGraphArea(60, 50, $width - 50, $height - 50);
            $myPicture->drawScale(["CycleBackground" => TRUE, "DrawSubTicks" => TRUE, "GridR" => 0, "GridG" => 0, "GridB" => 0, "GridAlpha" => 10, "LabelingMethod"=>LABELING_ALL, "Mode" => ($type === 'bar' ? SCALE_MODE_START0 : SCALE_MODE_FLOATING)]);

            if ($type === 'line') {
                $myPicture->drawLineChart();
                $myPicture->drawPlotChart(["PlotBorder" => TRUE, "BorderSize" => 1,"Surrounding"=>-60,"BorderAlpha"=>80]);
            } elseif ($type === 'bar') {
                $myPicture->drawBarChart(["DisplayValues"=>FALSE, "DisplayR"=>0, "DisplayG"=>0, "DisplayB"=>0, "DisplayShadow"=>TRUE, "Surrounding"=>30]);
            }

            $myPicture->setFontProperties(["FontName" => $fontFile, "FontSize" => 8]);
            $myPicture->drawLegend($width / 2, $height - 25, ["Style" => LEGEND_NOBORDER, "Mode" => LEGEND_HORIZONTAL, "Align" => TEXT_ALIGN_BOTTOMMIDDLE, "BoxWidth"=>5, "BoxHeight"=>5, "Margin"=>5]);

            $imagePath = CHART_TEMP_DIR_CPCHART . DIRECTORY_SEPARATOR . $filenameBase . ".png";
            if (file_exists($imagePath)) @unlink($imagePath);
            $myPicture->render($imagePath);
            if (!file_exists($imagePath)) {
                 Log::error("c-pchart ({$type}): pChart->render() did not create the image file for '{$title}' at '{$imagePath}'. Check PHP logs for pChart/GD errors.");
                return null;
            }
            return $imagePath;
        } catch (\Throwable $e) {
            Log::error("c-pchart ({$type}): Exception for '{$title}': " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return null;
        }
    }

    private function generatePieChartImageCPChart(string $filenameBase, array $data, string $title, int $width = 450, int $height = 280): ?string {
         if (!$this->pChartPrerequisitesMet) {
            Log::error("c-pchart (Pie): Prerequisites not met, will not attempt to generate '{$title}'.");
            return null;
        }
        if (empty($data)) { Log::warning("c-pchart (Pie): Empty data for '{$title}'."); return null; }
        $points = []; $labels = [];
        foreach($data as $row) if (isset($row[0], $row[1]) && $row[1] > 0) { $points[] = (float)$row[1]; $labels[] = (string)$row[0]; }
        if (empty($points)) { Log::warning("c-pchart (Pie): No valid data points for '{$title}'."); return null; }

        try {
            $myData = new pData();
            $myData->addPoints($points, "Data"); $myData->addPoints($labels, "Labels"); $myData->setAbscissa("Labels");
            $myPicture = new pImage($width, $height, $myData, TRUE); $myPicture->Antialias = TRUE;
            $fontFile = $this->getFontPath();
            if (!file_exists($fontFile) || !is_readable($fontFile)) { Log::critical("c-pchart (Pie): CRITICAL FAILURE - Font '{$fontFile}' is not a valid/readable file for '{$title}'."); return null; }

            $myPicture->drawFilledRectangle(0,0,$width-1,$height-1,["R"=>240,"G"=>240,"B"=>240]);
            $myPicture->drawRectangle(0,0,$width-1,$height-1,["R"=>200,"G"=>200,"B"=>200]);
            $myPicture->setFontProperties(["FontName"=>$fontFile,"FontSize"=>11]);
            $myPicture->drawText($width/2,20,$title,["Align"=>TEXT_ALIGN_TOPMIDDLE]);
            $myPicture->setFontProperties(["FontName"=>$fontFile,"FontSize"=>8]);
            $pieChart = new pPie($myPicture, $myData);
            $pieChart->draw3DPie($width/2 - 50,$height/2 + 10, ["Radius"=> ($width < $height ? $width : $height) / 3.5, "WriteValues"=>PIE_VALUE_PERCENTAGE, "DataGapAngle"=>8, "DataGapRadius"=>6, "Border"=>TRUE, "ValueR"=>0, "ValueG"=>0, "ValueB"=>0, "ValueAlpha"=>90, "Precision" => 0]);
            $pieChart->drawPieLegend($width - 140, 50, ["Style"=>LEGEND_NOBORDER,"Mode"=>LEGEND_VERTICAL, "FontR"=>0,"FontG"=>0,"FontB"=>0,"FontSize"=>7, "WritePValues"=>TRUE]);

            $imagePath = CHART_TEMP_DIR_CPCHART . DIRECTORY_SEPARATOR . $filenameBase . ".png";
            if (file_exists($imagePath)) @unlink($imagePath);
            $myPicture->render($imagePath);
             if (!file_exists($imagePath)) {
                 Log::error("c-pchart (Pie): pChart->render() did not create the image file for '{$title}' at '{$imagePath}'.");
                return null;
            }
            return $imagePath;
        } catch (\Throwable $e) { Log::error("c-pchart (Pie): Exception for '{$title}': " . $e->getMessage() . "\n" . $e->getTraceAsString()); return null; }
    }

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
        $sumTotal = 0; $indexOfSumColumn = count($headers);
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
                        $sheet->setCellValueExplicit($cellCoordinate, (string)$cellData, DataType::TYPE_STRING);
                    }
                    if ($hasTotalRow && $currentColIndex === $indexOfSumColumn && is_numeric($cellData)) $sumTotal += $cellData;
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
                $styleArrayBorders = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]]];
                $sheet->getStyle($rangeForBorders)->applyFromArray($styleArrayBorders);
            }
            $headerRangeForFill = $startColForStyle . $headerActualRow . ':' . $endColForStyle . $headerActualRow;
            $sheet->getStyle($headerRangeForFill)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
        }
        $masterCurrentRow++;
    }

    private function cleanupChartImages(string $pattern): void {
        $files = glob($pattern);
        if ($files === false) return;
        foreach ($files as $file) if (is_file($file)) @unlink($file);
    }

    private function calculateChartHeightInRows(int $imageHeightPx, int $rowHeightPt = 15): int {
        if ($rowHeightPt <= 0) $rowHeightPt = 15;
        return (int)ceil($imageHeightPx / ($rowHeightPt * 1.33)) + 2;
    }

    public function GenerateDefaultReport (DefaultReportExportRequest $request): JsonResponse {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheetName = 'default_report'; $sheet->setTitle($sheetName);
            $currentRow = 1;

            if (!$this->pChartPrerequisitesMet) {
                Log::error("GenerateDefaultReport: Prerequisites for pChart not met. Charts will be skipped.");
                 $sheet->setCellValue('A'.$currentRow, "Chart configuration incomplete. Check server logs."); $currentRow+=2;
            } else {
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
                    $chartTitle = 'Account Balances'; $imageHeight = 250;
                    $imagePath = $this->generateChartImage('line', 'def_line_'.time().rand(100,999), $cpChartSeriesData, $cpChartXLabels, $chartTitle, 700, $imageHeight);
                    if ($imagePath) {
                        $this->addChartImageToSheet($sheet, $imagePath, $chartTitle, 'A'.$currentRow, $imageHeight);
                        $currentRow += $this->calculateChartHeightInRows($imageHeight);
                    } else { $sheet->setCellValue('A'.$currentRow, "Error generating chart: {$chartTitle}"); $currentRow += 2; }
                } else { $sheet->setCellValue('A'.$currentRow, "No data for chart: Account Balances"); $currentRow += 2; }
                $currentRow++;
            }

            $this->createTable($sheet, $currentRow, "Account balances", ["Name", "Balance at start of period", "Balance at end of period", "Difference"], $validatedData['accountBalancesTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Income vs Expenses", ["Currency", "In", "Out", "Difference"], $validatedData['incomeVsExpensesTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Revenue/Income", ["Name", "Total", "Average"], $validatedData['revenueIncomeTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Expenses", ["Name", "Total", "Average"], $validatedData['expensesTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Budgets", ["Budget", "Date", "Budgeted", "pct (%)", "Spent", "pct (%)", "Left", "Overspent"], $validatedData['budgetsTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Categories", ["Category", "Spent", "Earned", "Sum"], $validatedData['categoriesTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Budget (split by account)", ["Budget", "Sum"], $validatedData['budgetSplitAccountTableData'] ?? [], true);
            $this->createTable($sheet, $currentRow, "Subscriptions", ["Name", "Minimum amount", "Maximum amount", "Expected on", "Paid"], $validatedData['subscriptionsTableData'] ?? []);

            $highestColumn = $sheet->getHighestDataColumn();
            if ($highestColumn) for ($colIndex = 1; $colIndex <= Coordinate::columnIndexFromString($highestColumn); ++$colIndex) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
            $writer = new Mpdf($spreadsheet);
            $filename = 'default_report_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = function_exists('storage_path') ? storage_path('app/reports') : __DIR__.'/../../storage/app/reports';
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $writer->save($filePath);
            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/def_line_*.png');
            return response()->json(['message' => 'PDF report saved.', 'filename' => $filename, 'path' => $filePath], 200);
        } catch (\Throwable $e) {
            Log::error("PDF DefaultReport (c-pchart): ".$e->getMessage()."\nTrace: ".$e->getTraceAsString()."\nFile: ".$e->getFile()." Line: ".$e->getLine());
            return response()->json(['error' => 'PDF Error.', 'details' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        }
    }

    public function GenerateTransactionReport(TransactionHistoryExportRequest $request): JsonResponse {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('TransactionHistory');
            $currentRow = 1; $imageHeight = 250;

            if (!$this->pChartPrerequisitesMet) {
                Log::error("GenerateTransactionReport: Prerequisites for pChart not met. Charts will be skipped.");
                 $sheet->setCellValue('A'.$currentRow, "Chart configuration incomplete. Check server logs."); $currentRow+=2;
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
                    $imgPath1 = $this->generateChartImage('line','trans_line1_'.time().rand(100,999), $cpChartSeriesData1, $cpChartXLabels1, $chartTitle1, 700, $imageHeight);
                    if($imgPath1) { $this->addChartImageToSheet($sheet, $imgPath1, $chartTitle1, 'A'.$currentRow, $imageHeight); $currentRow += $this->calculateChartHeightInRows($imageHeight); }
                    else { $sheet->setCellValue('A'.$currentRow, "Error generating chart: {$chartTitle1}"); $currentRow+=2; }
                } else { $sheet->setCellValue('A'.$currentRow, "No data for chart: {$chartTitle1}"); $currentRow+=2; }
                $currentRow++;

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
                    $imgPath2 = $this->generateChartImage('line','trans_line2_'.time().rand(100,999), $cpChartSeriesData2, $cpChartXLabels2, $chartTitle2, 700, $imageHeight);
                    if($imgPath2) { $this->addChartImageToSheet($sheet, $imgPath2, $chartTitle2, 'A'.$currentRow, $imageHeight); $currentRow += $this->calculateChartHeightInRows($imageHeight); }
                    else { $sheet->setCellValue('A'.$currentRow, "Error generating chart: {$chartTitle2}"); $currentRow+=2; }
                } else { $sheet->setCellValue('A'.$currentRow, "No data for chart: {$chartTitle2}"); $currentRow+=2; }
                $currentRow++;
            }

            $tableHeaders = ["Description", "Balance before", "Amount", "Balance after", "Date", "From", "To", "Budget", "Category", "Subscription", "Created at", "Updated at", "Notes", "Interest date", "Book date", "Processing date", "Due date", "Payment date", "Invoice date"];
            $this->createTable($sheet, $currentRow, "Account balance", $tableHeaders, $validatedData['accountBalanceTableData'] ?? []);

            $highestColumn = $sheet->getHighestDataColumn();
            if ($highestColumn) for ($colIndex = 1; $colIndex <= Coordinate::columnIndexFromString($highestColumn); ++$colIndex) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
            $writer = new Mpdf($spreadsheet);
            $filename = 'transaction_history_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = function_exists('storage_path') ? storage_path('app/reports') : __DIR__.'/../../storage/app/reports';
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $writer->save($filePath);
            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/trans_line*.png');
            return response()->json(['message' => 'PDF report saved.', 'filename' => $filename, 'path' => $filePath], 200);
        } catch (\Throwable $e) {
            Log::error("PDF TransactionReport (c-pchart): ".$e->getMessage()."\nTrace: ".$e->getTraceAsString()."\nFile: ".$e->getFile()." Line: ".$e->getLine());
            return response()->json(['error' => 'PDF Error.', 'details' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        }
    }

    public function GenerateBudgetReport(BudgetExportRequest $request): JsonResponse {
        try {
            $validatedData = $request->validated();
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('BudgetReport');
            $currentRow = 1; $pieImageHeight = 280; $barImageHeight = 300;

            $this->createTable($sheet, $currentRow, "Accounts", ["Name", "Spent"], $validatedData['accountsTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Budgets", ["Name", "Spent", "pct"], $validatedData['budgetsTableData'] ?? []);
            $this->createTable($sheet, $currentRow, "Account per budget", ["Name", "Groceries", "Bills", "Car", "Going out"], $validatedData['accountPerBudgetTableData'] ?? []);

            if (!$this->pChartPrerequisitesMet) {
                Log::error("GenerateBudgetReport: Prerequisites for pChart not met. Charts will be skipped.");
                $sheet->setCellValue('A'.$currentRow, "Chart configuration incomplete. Check server logs."); $currentRow+=2;
            } else {
                $expensePerBudgetChartData = $validatedData['expensePerBudgetChartData'] ?? [];
                $pieData1 = [];
                if(count($expensePerBudgetChartData) > 1) for($i=1; $i<count($expensePerBudgetChartData); $i++) if(isset($expensePerBudgetChartData[$i][0],$expensePerBudgetChartData[$i][1])) $pieData1[] = [(string)$expensePerBudgetChartData[$i][0], (float)$expensePerBudgetChartData[$i][1]];
                $chartTitlePie1 = "Expense per budget";
                if(!empty($pieData1)){
                    $imgPathPie1 = $this->generatePieChartImageCPChart('budget_pie1_'.time().rand(100,999), $pieData1, $chartTitlePie1, 450, $pieImageHeight);
                    if($imgPathPie1) { $this->addChartImageToSheet($sheet, $imgPathPie1, $chartTitlePie1, 'A'.$currentRow, $pieImageHeight); $currentRow += $this->calculateChartHeightInRows($pieImageHeight); }
                    else { $sheet->setCellValue('A'.$currentRow, "Error generating chart: {$chartTitlePie1}"); $currentRow+=2; }
                } else { $sheet->setCellValue('A'.$currentRow, "No data for chart: {$chartTitlePie1}"); $currentRow+=2; }
                $currentRow++;

                $expensePerCategoryChartData = $validatedData['expensePerCategoryChartData'] ?? [];
                $pieData2 = [];
                if(count($expensePerCategoryChartData)>1) for($i=1; $i<count($expensePerCategoryChartData); $i++) if(isset($expensePerCategoryChartData[$i][0],$expensePerCategoryChartData[$i][1])) $pieData2[] = [(string)$expensePerCategoryChartData[$i][0], (float)$expensePerCategoryChartData[$i][1]];
                $chartTitlePie2 = "Expense per category";
                if(!empty($pieData2)){
                    $imgPathPie2 = $this->generatePieChartImageCPChart('budget_pie2_'.time().rand(100,999), $pieData2, $chartTitlePie2, 450, $pieImageHeight);
                    if($imgPathPie2) { $this->addChartImageToSheet($sheet, $imgPathPie2, $chartTitlePie2, 'A'.$currentRow, $pieImageHeight); $currentRow += $this->calculateChartHeightInRows($pieImageHeight); }
                    else { $sheet->setCellValue('A'.$currentRow, "Error generating chart: {$chartTitlePie2}"); $currentRow+=2; }
                } else { $sheet->setCellValue('A'.$currentRow, "No data for chart: {$chartTitlePie2}"); $currentRow+=2; }
                $currentRow++;

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
                        $imgPathBar = $this->generateChartImage('bar','budget_bar'.$idx.'_'.time().rand(100,999), $barSeriesData, $barXLabels, $barTitle, 700, $barImageHeight);
                        if($imgPathBar) { $this->addChartImageToSheet($sheet, $imgPathBar, $barTitle, 'A'.$currentRow, $barImageHeight); $currentRow += $this->calculateChartHeightInRows($barImageHeight); }
                        else { $sheet->setCellValue('A'.$currentRow, "Error generating chart: {$barTitle}"); $currentRow+=2; }
                    } else { $sheet->setCellValue('A'.$currentRow, "No data for chart: {$barTitle}"); $currentRow+=2; }
                    $currentRow++;
                }
            }

            $this->createTable($sheet, $currentRow, "Expenses (top 10)", ["Description", "Amount", "Date", "Category"], $validatedData['topExpensesTableData'] ?? []);

            $highestColumn = $sheet->getHighestDataColumn();
            if ($highestColumn) for ($colIndex = 1; $colIndex <= Coordinate::columnIndexFromString($highestColumn); ++$colIndex) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
            $writer = new Mpdf($spreadsheet);
            $filename = 'budget_report_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = function_exists('storage_path') ? storage_path('app/reports') : __DIR__.'/../../storage/app/reports';
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $writer->save($filePath);
            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/budget_*.png');
            return response()->json(['message' => 'PDF report saved.', 'filename' => $filename, 'path' => $filePath], 200);
        } catch (\Throwable $e) {
            Log::error("PDF BudgetReport (c-pchart): ".$e->getMessage()."\nTrace: ".$e->getTraceAsString()."\nFile: ".$e->getFile()." Line: ".$e->getLine());
            return response()->json(['error' => 'PDF Error.', 'details' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        }
    }
}
