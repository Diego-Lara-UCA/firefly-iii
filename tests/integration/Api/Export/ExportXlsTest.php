<?php

declare(strict_types=1);

namespace Tests\integration\Api\Export; // Asegúrate que este namespace sea correcto para tu estructura

use Tests\integration\TestCase;     // Extiende la clase base de tus pruebas de integración
use PhpOffice\PhpSpreadsheet\IOFactory; // Para leer el archivo Excel

final class ExportXlsTest extends TestCase
{
    private $user; // Propiedad para almacenar el usuario autenticado

    /**
     * Provee los datos JSON que se enviarán en el cuerpo de la solicitud a la API.
     */
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

    /**
     * Configuración que se ejecuta antes de cada método de prueba.
     */
    protected function setUp(): void
    {
        parent::setUp(); // Ejecuta el setUp de la clase TestCase padre
        $this->user = $this->createAuthenticatedUser(); // Crea un usuario usando el método de la clase padre
        $this->actingAs($this->user); // Autentica al usuario para las solicitudes HTTP de la prueba
    }

    /**
     * Verifica la generación del reporte XLS por defecto y la validación de su contenido.
     */
    public function testDefaultReportXlsGenerationAndContentValidation(): void // <--- NOMBRE DEL MÉTODO CAMBIADO
    {
        $endpointUrl = '/api/v1/data/export/xls/default-report'; // URL de tu endpoint
        $requestData = $this->getReportRequestBodyData();        // Obtiene los datos JSON para la solicitud

        // Realiza la solicitud GET a la API con un cuerpo JSON
        $response = $this->json('GET', $endpointUrl, $requestData, [
            'Accept' => 'application/json', // Indica que esperamos una respuesta JSON
        ]);

        // --- Parte 1: Verificar la respuesta JSON de la API ---
        $response->assertStatus(200); // Verificar que el código de estado sea 200 OK
        $response->assertHeader('Content-Type', 'application/json'); // Verificar que la respuesta sea JSON
        
        // Verificar la estructura básica del JSON de respuesta
        $response->assertJsonStructure([
            'message',
            'filename',
            'path'
        ]);

        // Verificar el mensaje de éxito específico (ajusta si el mensaje de tu API es diferente)
        $response->assertJson([
            'message' => 'File successfully saved with simple 2D LINE chart test!', 
        ]);

        // Obtener los datos de la respuesta JSON para usarlos después
        $responseData = $response->json(); 

        // Verificar patrones en el nombre del archivo
        $this->assertStringStartsWith('default_report_LINE_CHART_TEST_', $responseData['filename']);
        $this->assertStringEndsWith('.xlsx', $responseData['filename']); // Asegúrate que sea .xlsx

        // Verificar que la ruta del archivo no esté vacía y contenga el nombre del archivo
        $this->assertNotEmpty($responseData['path']);
        $this->assertStringContainsString($responseData['filename'], $responseData['path']);

        // --- Parte 2: Verificar el archivo Excel generado en el servidor ---
        $serverFilePath = $responseData['path']; // Ruta del archivo en el servidor

        // Verificar que el archivo realmente existe en la ruta proporcionada
        $this->assertFileExists($serverFilePath, "El archivo Excel no fue encontrado en la ruta del servidor: " . $serverFilePath);

        // Intentar leer el archivo Excel y verificar su contenido
        try {
            $spreadsheet = IOFactory::load($serverFilePath);
            $sheet = $spreadsheet->getActiveSheet(); // Obtener la primera hoja (o la hoja activa)

            // ---- VALIDACIONES DEL CONTENIDO DE LAS TABLAS EN EL EXCEL ----
            // ¡¡¡ESTA SECCIÓN ES LA QUE MÁS NECESITAS PERSONALIZAR!!!
            // Basado en las capturas que me enviaste y tu JSON de entrada.

            // Validación de la tabla "Date" / "Balance" (primera captura)
            $this->assertEquals('Date', $sheet->getCell('A1')->getValue(), "Cabecera A1 para 'Date' no coincide.");
            $this->assertEquals('Balance', $sheet->getCell('B1')->getValue(), "Cabecera B1 para 'Balance' no coincide.");

            $expectedDatesInExcel = ["Ene", "Feb", "Mar", "Abr", "May"]; 
            for ($i = 0; $i < count($expectedDatesInExcel); $i++) {
                $excelRow = $i + 2; // Los datos comienzan en la fila 2
                $this->assertEquals(
                    $expectedDatesInExcel[$i], 
                    $sheet->getCell('A' . $excelRow)->getValue(), 
                    "Contenido de Excel: Fecha en celda A{$excelRow} no coincide."
                );
                // El fallo que tuviste (100 vs 1000) indica que esta aserción es importante:
                $this->assertEquals(
                    (float) $requestData['chartBalanceValues'][$i+1][0], 
                    (float) $sheet->getCell('B' . $excelRow)->getValue(), 
                    "Contenido de Excel: Balance en celda B{$excelRow} no coincide." // Mensaje de error personalizado
                );
            }

            // Validación de la tabla "Account balances"
            // ¡¡AJUSTA ESTAS COORDENADAS SEGÚN TU ARCHIVO REAL!!
            $this->assertEquals('Account balances', $sheet->getCell('C30')->getValue(), "Contenido de Excel: Título de tabla 'Account balances'"); // Asumiendo C30
            $this->assertEquals('Name', $sheet->getCell('B31')->getValue(), "Contenido de Excel: Cabecera 'Name' (B31) para Account balances no coincide.");
            $this->assertEquals('Balance at start of period', $sheet->getCell('C31')->getValue(), "Contenido de Excel: Cabecera 'Balance at start...' (C31) no coincide.");
            // ... (más cabeceras de Account Balances)

            $accountBalancesData = $requestData['accountBalancesTableData'];
            $startDataRowForAccounts = 32; // ¡AJUSTA ESTA FILA!

            // Si tu API actualmente escribe "No data available...", entonces esta aserción fallará.
            // Si quieres que la prueba pase AHORA, tendrías que hacer:
            // $this->assertEquals("No data available for this table.", $sheet->getCell('B'.$startDataRowForAccounts)->getValue());
            // Pero es MEJOR dejar la aserción con los datos esperados para que la prueba te indique
            // que tu API aún no está poblando los datos.
            foreach ($accountBalancesData as $rowIndex => $rowData) {
                $currentExcelRow = $startDataRowForAccounts + $rowIndex;
                $this->assertEquals($rowData[0], $sheet->getCell('B' . $currentExcelRow)->getValue(), "Contenido de Excel: Account Name en B{$currentExcelRow} no coincide.");
                $this->assertEquals((float) $rowData[1], (float) $sheet->getCell('C' . $currentExcelRow)->getValue(), "Contenido de Excel: Account Start Balance en C{$currentExcelRow} no coincide.");
                $this->assertEquals((float) $rowData[2], (float) $sheet->getCell('D' . $currentExcelRow)->getValue(), "Contenido de Excel: Account End Balance en D{$currentExcelRow} no coincide.");
                $this->assertEquals((float) $rowData[3], (float) $sheet->getCell('E' . $currentExcelRow)->getValue(), "Contenido de Excel: Account Difference en E{$currentExcelRow} no coincide.");
            }
            
            // ... Continúa con este patrón para validar las OTRAS TABLAS ...
            // Para cada tabla: identifica celda de inicio, verifica cabeceras, itera y verifica datos.

            // Puedes dejar esto o quitarlo si ya tienes suficientes aserciones
            // $this->markTestIncomplete(
            //    'Completar las validaciones para todas las tablas y sus datos en el archivo Excel.'
            // );

        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            $this->fail("Error al leer el archivo Excel desde el servidor: " . $e->getMessage() . " en la ruta: " . $serverFilePath);
        }
        /* // Opcional: Bloque finally para limpiar el archivo generado después de la prueba
        finally {
            if (isset($serverFilePath) && file_exists($serverFilePath)) {
                unlink($serverFilePath); // Elimina el archivo
            }
        }
        */
    }

    // (El método createAuthenticatedUser se hereda de Tests\integration\TestCase)
}