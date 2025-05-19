<?php

namespace FireflyIII\Api\V1\Requests\Data\Export;

use FireflyIII\Support\Request\ChecksLogin;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DefaultReportXLSController request.
*/

class DefaultFinancialXLSExportRequest extends FormRequest
{
     use ChecksLogin;

    public function rules(): array
    {
        return [
            'chartDateLabels' => 'array',
            'chartBalanceValues' => 'array',
            'accountBalancesTableData' => 'array',
            'incomeVsExpensesTableData' => 'array',
            'revenueIncomeTableData' => 'array',
            'expensesTableData' => 'array',
            'budgetsTableData' => 'array',
            'categoriesTableData' => 'array',
            'budgetSplitAccountTableData' => 'array',
            'subscriptionsTableData' => 'array',
        ];
    }
}
