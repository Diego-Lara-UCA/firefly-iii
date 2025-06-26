{{-- filepath: c:\Users\mosca\Escritorio\Ciclo 01-25\ACA\firefly-iii\resources\views\pdf\default_report.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $reportTitle }}</title>
    <style>
        /* --- Estilos Generales --- */
        @page {
            margin: 2.5cm 1.5cm 2.5cm 1.5cm;
            header: page-header;
            footer: page-footer;
        }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10px;
            color: #333;
        }
        h1, h2, h3 {
            font-weight: bold;
            color: #274060;
        }
        h1 {
            font-size: 24px;
            text-align: center;
            margin-bottom: 0;
        }
        h2 {
            font-size: 16px;
            border-bottom: 2px solid #4A86E8;
            padding-bottom: 5px;
            margin-top: 25px;
            margin-bottom: 10px;
        }
        h3 {
            font-size: 14px;
            margin-top: 20px;
            margin-bottom: 8px;
        }

        /* --- Cabecera y Pie de Página --- */
        .report-header-info {
            text-align: center;
            font-size: 9px;
            color: #666;
            margin-bottom: 30px;
        }

        /* --- Gráficos --- */
        .chart-container {
            text-align: center;
            margin-bottom: 20px;
        }
        .chart-container img {
            max-width: 100%;
            height: auto;
        }

        /* --- Tablas --- */
        .table-container {
            page-break-inside: avoid;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 9px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
        }
        thead th {
            background-color: #D9E2F3;
            color: #274060;
            font-weight: bold;
            border-bottom: 2px solid #4A86E8;
        }
        tbody tr:nth-child(even) {
            background-color: #F3F6F9;
        }
        tbody tr:hover {
            background-color: #E8F0FE;
        }
        tfoot td {
            font-weight: bold;
            background-color: #D9E2F3;
        }
        tr.summary-row td {
            font-weight: bold;
            background-color: #F3F6F9;
        }
        .no-data {
            text-align: center;
            padding: 20px;
            color: #777;
        }
    </style>
</head>
<body>

    <!-- Cabecera del Documento -->
    <h1>{{ $reportTitle }}</h1>
    <div class="report-header-info">
        Generated on: {{ \Carbon\Carbon::now()->format('F j, Y, g:i a') }}
    </div>

    <!-- Gráfico Principal -->
    @if($chartImagePath)
        <h2>Account Balances Over Time</h2>
        <div class="chart-container">
            <img src="{{ $chartImagePath }}" alt="Account Balances Chart">
        </div>
    @endif

    <!-- Renderizado de todas las tablas usando una vista parcial -->
    @include('pdf._table', [
        'title' => 'Account Balances',
        'headers' => ["Name", "Balance at start of period", "Balance at end of period", "Difference"],
        'data' => $accountBalancesTableData
    ])

    @include('pdf._table', [
        'title' => 'Income vs Expenses',
        'headers' => ["Currency", "In", "Out", "Difference"],
        'data' => $incomeVsExpensesTableData
    ])

    @include('pdf._table', [
        'title' => 'Revenue/Income',
        'headers' => ["Name", "Total", "Average"],
        'data' => $revenueIncomeTableData
    ])

    @include('pdf._table', [
        'title' => 'Expenses',
        'headers' => ["Name", "Total", "Average"],
        'data' => $expensesTableData
    ])

    @include('pdf._table', [
        'title' => 'Budgets',
        'headers' => ["Budget", "Date", "Budgeted", "pct (%)", "Spent", "pct (%)", "Left", "Overspent"],
        'data' => $budgetsTableData
    ])

    @include('pdf._table', [
        'title' => 'Categories',
        'headers' => ["Category", "Spent", "Earned", "Sum"],
        'data' => $categoriesTableData
    ])

    @include('pdf._table', [
        'title' => 'Budget (split by account)',
        'headers' => ["Budget", "Sum"],
        'data' => $budgetSplitAccountTableData
    ])

    @include('pdf._table', [
        'title' => 'Subscriptions',
        'headers' => ["Name", "Minimum amount", "Maximum amount", "Expected on", "Paid"],
        'data' => $subscriptionsTableData
    ])

</body>
</html>