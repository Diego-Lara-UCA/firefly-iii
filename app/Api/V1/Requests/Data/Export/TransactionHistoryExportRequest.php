<?php

namespace FireflyIII\Api\V1\Requests\Data\Export;

use FireflyIII\Support\Request\ChecksLogin;
use Illuminate\Foundation\Http\FormRequest;

class TransactionHistoryExportRequest extends FormRequest
{
    use ChecksLogin;
    public function rules(): array
    {
        return [
            'accountBalanceTableData' => 'required|array',
            'accountBalanceTableData.*' => 'sometimes|array',

            'creditCardChartAccountName' => 'nullable|string',
            'creditCardChartDateRange' => 'nullable|string',
            'creditCardChartDateLabels' => 'nullable|array',
            'creditCardChartDebtValues' => 'nullable|array',

            'cashWalletChartDateLabels' => 'nullable|array',
            'cashWalletChartMoneyValues' => 'nullable|array',
        ];
    }
}