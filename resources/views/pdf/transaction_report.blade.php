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

    {{-- Gráficos de transacciones --}}
    @if($ccChartImagePath)
        <h2>{{ $ccChartTitle }}</h2>
        <div class="chart-container">
            <img src="{{ $ccChartImagePath }}" alt="Credit Card Chart">
        </div>
    @endif
    @if($cwChartImagePath)
        <h2>{{ $cwChartTitle }}</h2>
        <div class="chart-container">
            <img src="{{ $cwChartImagePath }}" alt="Cash Wallet Chart">
        </div>
    @endif

    {{-- Tabla principal de transacciones --}}
    @include('pdf._table', [
        'title' => 'Account Balance Transactions',
        'headers' => [
            'Description', 'Balance before', 'Amount', 'Balance after', 'Date', 'From', 'To', 'Budget',
            'Category', 'Subscription', 'Created at', 'Updated at', 'Notes', 'Interest', 'Tag', 'ID', 'Type', 'Currency', 'Status'
        ],
        'data' => $accountBalanceTableData
    ])

</body>
</html>