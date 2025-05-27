<?php

declare(strict_types=1);

namespace Tests\integration\Api\Export;

use Tests\integration\TestCase;     
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

final class ExportXlsTest extends TestCase
{
    // ... (propiedades $user y método getReportRequestBodyData sin cambios) ...
    private $user;

    private function getReportRequestBodyData(): array
    {
        return [
            "chartDateLabels" => [
                ["Fecha"], ["2024-01-01"], ["2024-02-01"], ["2024-03-01"], ["2024-04-01"], ["2024-05-01"]
            ],
            "chartBalanceValues" => [
                ["Saldo ($)"], [1000], [1200], [1100], [1500], [1400]
            ],
            "accountBalancesTableData" => [
                ["Cuenta Corriente Principal", 1250.75, 1875.20, 624.45],
                ["Cuenta de Ahorros", 5300.00, 5350.50, 50.50],
                ["Tarjeta de Crédito Visa", -450.30, -300.10, 150.20]
            ],
            "incomeVsExpensesTableData" => [
                ["USD", 3200.00, 2100.50, 1099.50],
                ["EUR", 500.00, 350.00, 150.00]
            ],
            "revenueIncomeTableData" => [
                ["Salario Mensual", 2800.00, 2800.00],
                ["Ingresos Freelance", 400.00, 200.00],
                ["Intereses Bancarios", 15.50, 5.17]
            ],
            "expensesTableData" => [
                ["Alquiler Apartamento", 950.00, 950.00],
                ["Supermercado", 350.70, 87.68],
                ["Transporte Público", 60.00, 20.00],
                ["Servicios (Luz, Agua, Internet)", 150.25, 150.25]
            ],
            "budgetsTableData" => [
                ["Alimentación", "2024-05", 400.00, "100%", 380.50, "95.13%", 19.50, 0.00],
                ["Ocio y Entretenimiento", "2024-05", 200.00, "100%", 210.00, "105.00%", 0.00, 10.00],
                ["Ahorro para Vacaciones", "2024-12", 1500.00, "100%", 600.00, "40.00%", 900.00, 0.00]
            ],
            "categoriesTableData" => [
                ["Ingresos por Nómina", 0.00, 2800.00, 2800.00],
                ["Vivienda", 950.00, 0.00, -950.00],
                ["Comida", 350.70, 0.00, -350.70],
                ["Transporte", 60.00, 0.00, -60.00]
            ],
            "budgetSplitAccountTableData" => [
                ["Presupuesto Comida (Cta. Principal)", 380.50],
                ["Presupuesto Ocio (Cta. Secundaria)", 210.00],
                ["Ahorro Vacaciones (Cta. Ahorros)", 150.00]
            ],
            "subscriptionsTableData" => [
                ["Servicio Streaming Música", 9.99, 9.99, "2024-05-10", "Yes"],
                ["Software de Diseño", 24.99, 24.99, "2024-06-01", "No"],
                ["Revista Digital Técnica", 15.00, 15.00, "2024-05-20", "Yes"],
                ["Membresía Gimnasio", 45.00, 45.00, "2024-05-05", "Yes"]
            ]
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createAuthenticatedUser();
        $this->actingAs($this->user);
    }

    public function testDefaultReportXlsGenerationAndContentValidation(): void
    {
        $endpointUrl = '/api/v1/data/export/xls/default-report';     
        $requestData = $this->getReportRequestBodyData();

        $response = $this->json('GET', $endpointUrl, $requestData, [
            'Accept' => 'application/json', 
        ]);

        $response->assertStatus(200); 
        $response->assertHeader('Content-Type', 'application/json'); 
        $response->assertJsonStructure(['message', 'filename', 'path']);
        $response->assertJson(['message' => 'File saved. Added setIncludeCharts(true).']); 
        $responseData = $response->json(); 
        $this->assertStringStartsWith('default_report_INCLUDE_CHARTS_TEST_', $responseData['filename']);
        $this->assertStringEndsWith('.xlsx', $responseData['filename']);
        $this->assertNotEmpty($responseData['path']);
        $this->assertStringContainsString($responseData['filename'], $responseData['path']);

        $serverFilePath = $responseData['path'];
        $this->assertFileExists($serverFilePath, "El archivo Excel no fue encontrado en la ruta del servidor: {$serverFilePath}");

        try {
            $spreadsheet = IOFactory::load($serverFilePath);
            $sheet = $spreadsheet->getSheetByName('default_report');
            $this->assertNotNull($sheet, "La hoja 'default_report' no fue encontrada en el Excel.");

            $this->assertEquals('Date', $sheet->getCell('A1')->getValue(), "Excel: Cabecera A1 Date");
            $this->assertEquals('Balance', $sheet->getCell('B1')->getValue(), "Excel: Cabecera B1 Balance");
            $expectedChartDataSourceDates = ["Ene", "Feb", "Mar", "Abr", "May"];
            $expectedChartDataSourceBalances = [100, 150, 120, 180, 160];
            for ($i = 0; $i < count($expectedChartDataSourceDates); $i++) {
                $excelRow = $i + 2;
                $this->assertEquals($expectedChartDataSourceDates[$i], $sheet->getCell('A' . $excelRow)->getValue(), "Excel: Chart Date A{$excelRow}");
                $this->assertEquals($expectedChartDataSourceBalances[$i], (float) $sheet->getCell('B' . $excelRow)->getValue(), "Excel: Chart Balance B{$excelRow}");
            }

            $currentRowInExcel = 30;

            // La forma en que definiste la closure con `use ($sheet, $requestData, &$currentRowInExcel, $this)`
            // ES la forma correcta de hacer que $this (la instancia de ExportXlsTest) esté disponible.
            // Si tu IDE sigue marcando "Cannot use $this as lexical variable" puede ser una
            // configuración del linter del IDE o una falsa alarma, o una versión de PHP muy antigua
            // donde esto era más problemático (pero con PHP 7+ y `use ($this)` debería estar bien).
            // Vamos a asegurarnos que el $this que pasas se usa correctamente.
            // Renombraré la variable local $this a $testInstance para evitar cualquier ambigüedad para el linter.
            
            $testInstance = $this; // Capturamos $this en una variable local

            $validateTable = function(
                string $tableKeyInRequest, 
                string $tableTitleInExcel, 
                array $tableHeadersInExcel,
                bool $hasTotalRow = false
            ) use ($sheet, $requestData, &$currentRowInExcel, $testInstance) { // Usamos $testInstance

                $tableDataFromRequest = $requestData[$tableKeyInRequest] ?? [];

                // Usamos $testInstance para llamar a los métodos de aserción
                $testInstance->assertEquals($tableTitleInExcel, $sheet->getCell('A' . $currentRowInExcel)->getValue(), "Excel: Título Tabla '{$tableTitleInExcel}' en A{$currentRowInExcel}");
                $currentRowInExcel++; 

                foreach ($tableHeadersInExcel as $colIndex => $header) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex + 1);
                    $testInstance->assertEquals($header, $sheet->getCell($colLetter . $currentRowInExcel)->getValue(), "Excel: '{$tableTitleInExcel}' Cabecera '{$header}'");
                }
                $currentRowInExcel++; 

                if (empty($tableDataFromRequest)) {
                    $testInstance->assertEquals('No data available for this table.', $sheet->getCell('A' . $currentRowInExcel)->getValue(), "Excel: '{$tableTitleInExcel}' mensaje 'No data'.");
                    $currentRowInExcel++; 
                } else {
                    foreach ($tableDataFromRequest as $rowIndex => $rowData) {
                        $excelDataRow = $currentRowInExcel + $rowIndex;
                        foreach ($rowData as $cellIndex => $cellValue) {
                            if ($cellIndex < count($tableHeadersInExcel)) {
                                $colLetter = Coordinate::stringFromColumnIndex($cellIndex + 1);
                                $actualCellValue = $sheet->getCell($colLetter . $excelDataRow)->getValue();
                                if (is_numeric($cellValue) && !is_string($cellValue)) {
                                    $testInstance->assertEquals((float) $cellValue, (float) $actualCellValue, "Excel: '{$tableTitleInExcel}' R{$rowIndex}C{$cellIndex} ({$colLetter}{$excelDataRow})");
                                } else {
                                    $testInstance->assertEquals($cellValue, $actualCellValue, "Excel: '{$tableTitleInExcel}' R{$rowIndex}C{$cellIndex} ({$colLetter}{$excelDataRow})");
                                }
                            }
                        }
                    }
                    $currentRowInExcel += count($tableDataFromRequest); 
                }

                if ($hasTotalRow) {
                    $totalLabelColLetter = Coordinate::stringFromColumnIndex(max(1, count($tableHeadersInExcel) -1));
                    $totalValueColLetter = Coordinate::stringFromColumnIndex(count($tableHeadersInExcel));
                    $expectedSumTotal = 0;
                    foreach ($tableDataFromRequest as $dataRow) {
                        if (count($dataRow) == count($tableHeadersInExcel) && is_numeric(end($dataRow))) {
                             $expectedSumTotal += (float) end($dataRow);   
                        } else if (count($dataRow) == 1 && is_numeric($dataRow[0]) && count($tableHeadersInExcel) == 2) {
                            $expectedSumTotal += (float) $dataRow[0];
                        }
                    }
                    if (count($tableHeadersInExcel) > 1) {
                        $testInstance->assertEquals("Total", $sheet->getCell($totalLabelColLetter . $currentRowInExcel)->getValue(), "Excel: '{$tableTitleInExcel}' Etiqueta Total");
                        $testInstance->assertEquals($expectedSumTotal, (float) $sheet->getCell($totalValueColLetter . $currentRowInExcel)->getValue(), "Excel: '{$tableTitleInExcel}' Valor Total");
                    } else { 
                         $testInstance->assertStringContainsString("Total: " . $expectedSumTotal, $sheet->getCell($totalValueColLetter . $currentRowInExcel)->getValue(), "Excel: '{$tableTitleInExcel}' Etiqueta y Valor Total");
                    }
                    $currentRowInExcel++;
                }
                $currentRowInExcel++; 
            };

            // Llamadas a $validateTable (sin cambios)
            $validateTable('accountBalancesTableData', "Account balances", ["Name", "Balance at start of period", "Balance at end of period", "Difference"]);
            $validateTable('incomeVsExpensesTableData', "Income vs Expenses", ["Currency", "In", "Out", "Difference"]);
            $validateTable('revenueIncomeTableData', "Revenue/Income", ["Name", "Total", "Average"]);
            $validateTable('expensesTableData', "Expenses", ["Name", "Total", "Average"]);
            $validateTable('budgetsTableData', "Budgets", ["Budget", "Date", "Budgeted", "pct (%)", "Spent", "pct (%)", "Left", "Overspent"]);
            $validateTable('categoriesTableData', "Categories", ["Category", "Spent", "Earned", "Sum"]);
            $validateTable('budgetSplitAccountTableData', "Budget (split by account)", ["Budget", "Sum"], true);
            $validateTable('subscriptionsTableData', "Subscriptions", ["Name", "Minimum amount", "Maximum amount", "Expected on", "Paid"]);

        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            $this->fail("Error al leer el archivo Excel desde el servidor: " . $e->getMessage() . " en la ruta: " . $serverFilePath);
        }
    }
}