<?php

declare(strict_types=1);

namespace FireflyIII\Support\Export;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use CpChart\Data as pData;
use CpChart\Image as pImage;
use CpChart\Chart\Pie as pPie;
use FireflyIII\Api\V1\Requests\Data\Export\BudgetExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\DefaultReportExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TransactionHistoryExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\CategoryReportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TagReportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\ExpenseRevenueReportRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
                $pieColors = [
                    ['R'=>69, 'G'=>114, 'B'=>167], ['R'=>17, 'G'=>167, 'B'=>153], ['R'=>246, 'G'=>168, 'B'=>0], 
                    ['R'=>219, 'G'=>50, 'B'=>54], ['R'=>148, 'G'=>54, 'B'=>219], ['R'=>219, 'G'=>133, 'B'=>54]
                ];
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

    private function cleanupChartImages(string $pattern): void
    {
        $files = glob($pattern);
        if ($files === false) return;
        foreach ($files as $file) if (is_file($file)) @unlink($file);
    }

    private function normalizeTableData(array $data, int $numHeaders): array {
        return array_map(function ($row) use ($numHeaders) {
            $row = (array) $row;
            $cellCount = count($row);
            if ($cellCount < $numHeaders) {
                return array_pad($row, $numHeaders, '');
            }
            return array_slice($row, 0, $numHeaders);
        }, $data);
    }

    public function GenerateDefaultReport(DefaultReportExportRequest $request): BinaryFileResponse {
        try {
            $validatedData = $request->validated();
            $reportTitle = 'Default Financial Report';

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

            $html = view('pdf.default_report', [
                'reportTitle' => $reportTitle,
                'chartImagePath' => $chartImagePath,
                'accountBalancesTableData' => $this->normalizeTableData($validatedData['accountBalancesTableData'] ?? [], 4),
                'incomeVsExpensesTableData' => $this->normalizeTableData($validatedData['incomeVsExpensesTableData'] ?? [], 4),
                'revenueIncomeTableData' => $this->normalizeTableData($validatedData['revenueIncomeTableData'] ?? [], 3),
                'expensesTableData' => $this->normalizeTableData($validatedData['expensesTableData'] ?? [], 3),
                'budgetsTableData' => $this->normalizeTableData($validatedData['budgetsTableData'] ?? [], 8),
                'categoriesTableData' => $this->normalizeTableData($validatedData['categoriesTableData'] ?? [], 4),
                'budgetSplitAccountTableData' => $this->normalizeTableData($validatedData['budgetSplitAccountTableData'] ?? [], 2),
                'subscriptionsTableData' => $this->normalizeTableData($validatedData['subscriptionsTableData'] ?? [], 5),
            ])->render();

            $mpdf = new \Mpdf\Mpdf(['tempDir' => storage_path('app/temp_mpdf')]);
            $mpdf->WriteHTML($html);

            $filename = 'default_report_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = storage_path('app/reports');
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);

            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/def_*.png');

            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/pdf'
            ])->deleteFileAfterSend(true);

        } catch (\Throwable $e) {
            Log::error("PDF DefaultReport (HTML Template): ".$e->getMessage()."\nTrace: ".$e->getTraceAsString());
            abort(500, 'PDF Error: ' . $e->getMessage());
        }
    }

    public function GenerateTransactionReport(TransactionHistoryExportRequest $request): BinaryFileResponse {
        try {
            $validatedData = $request->validated();
            $reportTitle = 'Transaction History Report';

            $ccChartImagePath = null;
            $ccChartTitle = null;
            if ($this->pChartPrerequisitesMet) {
                $ccChartDateLabels = $validatedData['creditCardChartDateLabels'] ?? [];
                $ccChartDebtValues = $validatedData['creditCardChartDebtValues'] ?? [];
                $ccXLabels = [];
                if (count($ccChartDateLabels) > 1) for ($i=1; $i<count($ccChartDateLabels); $i++) $ccXLabels[] = (string)($ccChartDateLabels[$i][0] ?? 'N/A');
                $ccSeriesData = [];
                if (count($ccChartDebtValues) > 1 && isset($ccChartDebtValues[0][0])) {
                    $sName = (string)($ccChartDebtValues[0][0] ?? 'Debt'); $vals = [];
                    for ($i=1; $i<count($ccChartDebtValues); $i++) $vals[] = (float)($ccChartDebtValues[$i][0] ?? 0);
                    if (!empty($vals)) $ccSeriesData[] = [$sName, $vals];
                }
                $ccChartTitle = "Transactions for ".($validatedData['creditCardChartAccountName'] ?? 'N/A')." (".($validatedData['creditCardChartDateRange'] ?? 'N/A').")";
                if (!empty($ccXLabels) && !empty($ccSeriesData)) {
                    $imgPath = $this->generateChartImage('line','trans_line1_'.time().rand(100,999), $ccSeriesData, $ccXLabels, $ccChartTitle, 750, 250);
                    if ($imgPath) $ccChartImagePath = $imgPath;
                }
            }
            $cwChartImagePath = null;
            $cwChartTitle = null;
            if ($this->pChartPrerequisitesMet) {
                $cwChartDateLabels = $validatedData['cashWalletChartDateLabels'] ?? [];
                $cwChartMoneyValues = $validatedData['cashWalletChartMoneyValues'] ?? [];
                $cwXLabels = [];
                if (count($cwChartDateLabels) > 1) for ($i=1; $i<count($cwChartDateLabels); $i++) $cwXLabels[] = (string)($cwChartDateLabels[$i][0] ?? 'N/A');
                $cwSeriesData = [];
                if (count($cwChartMoneyValues) > 1 && isset($cwChartMoneyValues[0][0])) {
                    $sName = (string)($cwChartMoneyValues[0][0] ?? 'Money'); $vals = [];
                    for ($i=1; $i<count($cwChartMoneyValues); $i++) $vals[] = (float)($cwChartMoneyValues[$i][0] ?? 0);
                    if (!empty($vals)) $cwSeriesData[] = [$sName, $vals];
                }
                $cwChartTitle = "Cash Wallet";
                if (!empty($cwXLabels) && !empty($cwSeriesData)) {
                    $imgPath = $this->generateChartImage('line','trans_line2_'.time().rand(100,999), $cwSeriesData, $cwXLabels, $cwChartTitle, 750, 250);
                    if ($imgPath) $cwChartImagePath = $imgPath;
                }
            }

            $html = view('pdf.transaction_report', [
                'reportTitle' => $reportTitle,
                'ccChartImagePath' => $ccChartImagePath,
                'ccChartTitle' => $ccChartTitle,
                'cwChartImagePath' => $cwChartImagePath,
                'cwChartTitle' => $cwChartTitle,
                'accountBalanceTableData' => $this->normalizeTableData($validatedData['accountBalanceTableData'] ?? [], 19),
            ])->render();

            $mpdf = new \Mpdf\Mpdf(['tempDir' => storage_path('app/temp_mpdf')]);
            $mpdf->WriteHTML($html);

            $filename = 'transaction_history_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = storage_path('app/reports');
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);

            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/trans_*.png');
            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/pdf'
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Log::error("PDF TransactionReport (Blade): ".$e->getMessage()."\nTrace: ".$e->getTraceAsString());
            abort(500, 'PDF Error: ' . $e->getMessage());
        }
    }

    public function GenerateBudgetReport(BudgetExportRequest $request): BinaryFileResponse {
        try {
            $validatedData = $request->validated();
            $reportTitle = 'Budget Report';

            $html = view('pdf.budget_report', [
                'reportTitle' => $reportTitle,
                'accountsTableData' => $this->normalizeTableData($validatedData['accountsTableData'] ?? [], 2),
                'budgetsTableData' => $this->normalizeTableData($validatedData['budgetsTableData'] ?? [], 3),
                'accountPerBudgetTableData' => $this->normalizeTableData($validatedData['accountPerBudgetTableData'] ?? [], 5),
                'topExpensesTableData' => $this->normalizeTableData($validatedData['topExpensesTableData'] ?? [], 4),
            ])->render();

            $mpdf = new \Mpdf\Mpdf(['tempDir' => storage_path('app/temp_mpdf')]);
            $mpdf->WriteHTML($html);

            $filename = 'budget_report_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = storage_path('app/reports');
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);

            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/budget_*.png');
            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/pdf'
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Log::error("PDF BudgetReport (Blade): ".$e->getMessage()."\nTrace: ".$e->getTraceAsString());
            abort(500, 'PDF Error: ' . $e->getMessage());
        }
    }

    public function GenerateCategoryReport(CategoryReportRequest $request): BinaryFileResponse {
        try {
            $validatedData = $request->validated();
            $reportTitle = 'Category Report';

            $html = view('pdf.category_report', [
                'reportTitle' => $reportTitle,
                'accountsTableData' => $this->normalizeTableData($validatedData['accountsTableData'] ?? [], 4),
                'categoriesTableData' => $this->normalizeTableData($validatedData['categoriesTableData'] ?? [], 4),
                'accountPerCategoryTableHeaders' => $validatedData['accountPerCategoryTableHeaders'] ?? ['Name'],
                'accountPerCategoryTableData' => $this->normalizeTableData($validatedData['accountPerCategoryTableData'] ?? [], count($validatedData['accountPerCategoryTableHeaders'] ?? ['Name'])),
                'avgExpenseDestinationTableData' => $this->normalizeTableData($validatedData['avgExpenseDestinationTableData'] ?? [], 4),
                'avgEarningSourceTableData' => $this->normalizeTableData($validatedData['avgEarningSourceTableData'] ?? [], 4),
                'topExpensesTableData' => $this->normalizeTableData($validatedData['topExpensesTableData'] ?? [], 5),
                'topRevenueTableData' => $this->normalizeTableData($validatedData['topRevenueTableData'] ?? [], 5),
            ])->render();

            $mpdf = new \Mpdf\Mpdf(['tempDir' => storage_path('app/temp_mpdf')]);
            $mpdf->WriteHTML($html);

            $filename = 'category_report_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = storage_path('app/reports');
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);

            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/cat_*.png');
            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/pdf'
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Log::error("Exception in CategoryReport (Blade): " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            abort(500, 'PDF Error: ' . $e->getMessage());
        }
    }

    public function GenerateTagReport(TagReportRequest $request): BinaryFileResponse {
        try {
            $validatedData = $request->validated();
            $reportTitle = 'Tag Report';

            $html = view('pdf.tag_report', [
                'reportTitle' => $reportTitle,
                'accountsTableData' => $this->normalizeTableData($validatedData['accountsTableData'] ?? [], 4),
                'tagsTableData' => $this->normalizeTableData($validatedData['tagsTableData'] ?? [], 4),
                'accountPerTagTableHeaders' => $validatedData['accountPerTagTableHeaders'] ?? ['Name'],
                'accountPerTagTableData' => $this->normalizeTableData($validatedData['accountPerTagTableData'] ?? [], count($validatedData['accountPerTagTableHeaders'] ?? ['Name'])),
                'avgExpenseDestinationTableData' => $this->normalizeTableData($validatedData['avgExpenseDestinationTableData'] ?? [], 4),
                'avgEarningSourceTableData' => $this->normalizeTableData($validatedData['avgEarningSourceTableData'] ?? [], 4),
                'topExpensesTableData' => $this->normalizeTableData($validatedData['topExpensesTableData'] ?? [], 5),
                'topRevenueTableData' => $this->normalizeTableData($validatedData['topRevenueTableData'] ?? [], 5),
            ])->render();

            $mpdf = new \Mpdf\Mpdf(['tempDir' => storage_path('app/temp_mpdf')]);
            $mpdf->WriteHTML($html);

            $filename = 'tag_report_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = storage_path('app/reports');
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);

            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/tag_*.png');
            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/pdf'
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Log::error("Exception in GenerateTagReport (Blade): " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            abort(500, 'PDF Error: ' . $e->getMessage());
        }
    }

    public function GenerateExpenseRevenueReport(ExpenseRevenueReportRequest $request): BinaryFileResponse {
        try {
            $validatedData = $request->validated();
            $reportTitle = 'Expense and Revenue Report';

            $html = view('pdf.expense_revenue_report', [
                'reportTitle' => $reportTitle,
                'accountsTableData' => $this->normalizeTableData($validatedData['accountsTableData'] ?? [], 4),
                'tagsTableData' => $this->normalizeTableData($validatedData['tagsTableData'] ?? [], 4),
                'accountPerTagTableHeaders' => $validatedData['accountPerTagTableHeaders'] ?? ['Name'],
                'accountPerTagTableData' => $this->normalizeTableData($validatedData['accountPerTagTableData'] ?? [], count($validatedData['accountPerTagTableHeaders'] ?? ['Name'])),
                'avgExpenseDestinationTableData' => $this->normalizeTableData($validatedData['avgExpenseDestinationTableData'] ?? [], 4),
                'avgEarningSourceTableData' => $this->normalizeTableData($validatedData['avgEarningSourceTableData'] ?? [], 4),
                'topExpensesTableData' => $this->normalizeTableData($validatedData['topExpensesTableData'] ?? [], 5),
                'topRevenueTableData' => $this->normalizeTableData($validatedData['topRevenueTableData'] ?? [], 5),
            ])->render();

            $mpdf = new \Mpdf\Mpdf(['tempDir' => storage_path('app/temp_mpdf')]);
            $mpdf->WriteHTML($html);

            $filename = 'expense_revenue_report_'.Carbon::now()->format('Ymd_His').'.pdf';
            $storageDir = storage_path('app/reports');
            if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
            $filePath = $storageDir . DIRECTORY_SEPARATOR . $filename;
            $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);

            $this->cleanupChartImages(CHART_TEMP_DIR_CPCHART . '/exprev_*.png');

            return response()->download($filePath, $filename, [
                'Content-Type' => 'application/pdf'
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Log::error("Exception in GenerateExpenseRevenueReport (Blade): " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            abort(500, 'PDF Error: ' . $e->getMessage());
        }
    }
}
