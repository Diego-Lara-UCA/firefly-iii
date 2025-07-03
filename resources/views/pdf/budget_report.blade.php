<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $reportTitle }}</title>
    <style>
        @page { margin: 2.5cm 1.5cm 2.5cm 1.5cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #333; }
        h1, h2, h3 { font-weight: bold; color: #274060; }
        h1 { font-size: 24px; text-align: center; margin-bottom: 0; }
        h2 { font-size: 16px; border-bottom: 2px solid #4A86E8; padding-bottom: 5px; margin-top: 25px; margin-bottom: 10px; }
        .report-header-info { text-align: center; font-size: 9px; color: #666; margin-bottom: 30px; }
        .chart-container { text-align: center; margin-bottom: 20px; }
        .chart-container img { max-width: 100%; height: auto; }
        .table-container { page-break-inside: avoid; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 9px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        thead th { background-color: #D9E2F3; color: #274060; font-weight: bold; border-bottom: 2px solid #4A86E8; }
        tbody tr:nth-child(even) { background-color: #F3F6F9; }
        tbody tr:hover { background-color: #E8F0FE; }
        tfoot td { font-weight: bold; background-color: #D9E2F3; }
        tr.summary-row td { font-weight: bold; background-color: #F3F6F9; }
        .no-data { text-align: center; padding: 20px; color: #777; }
    </style>
</head>
<body>

    <h1>{{ $reportTitle }}</h1>
    <div class="report-header-info">
        Generated on: {{ \Carbon\Carbon::now()->format('F j, Y, g:i a') }}
    </div>

    @include('pdf._table', [
        'title' => 'Accounts',
        'headers' => ['Name', 'Spent'],
        'data' => $accountsTableData
    ])
    @include('pdf._table', [
        'title' => 'Budgets',
        'headers' => ['Name', 'Spent', 'pct'],
        'data' => $budgetsTableData
    ])
    @include('pdf._table', [
        'title' => 'Account per budget',
        'headers' => ['Name', 'Groceries', 'Bills', 'Car', 'Going out'],
        'data' => $accountPerBudgetTableData
    ])
    @include('pdf._table', [
        'title' => 'Expenses (top 10)',
        'headers' => ['Description', 'Amount', 'Date', 'Category'],
        'data' => $topExpensesTableData
    ])

</body>
</html>