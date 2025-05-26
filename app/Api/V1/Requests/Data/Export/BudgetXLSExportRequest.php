<?php

namespace FireflyIII\Api\V1\Requests\Data\Export; // Ajusta este namespace

use FireflyIII\Support\Request\ChecksLogin;
use Illuminate\Foundation\Http\FormRequest;

class BudgetXLSExportRequest extends FormRequest
{
    use ChecksLogin;

    public function rules(): array
    {
        return [
            'accountsTableData' => 'nullable|array',
            'accountsTableData.*' => 'sometimes|array',

            'budgetsTableData' => 'nullable|array',
            'budgetsTableData.*' => 'sometimes|array',

            'accountPerBudgetTableData' => 'nullable|array',
            'accountPerBudgetTableData.*' => 'sometimes|array',

            'expensePerBudgetChartData' => 'nullable|array',
            'expensePerBudgetChartData.*' => 'sometimes|array|size:2',

            'expensePerCategoryChartData' => 'nullable|array',
            'expensePerCategoryChartData.*' => 'sometimes|array|size:2',

            'expensePerSourceAccountChartData' => 'nullable|array',
            'expensePerSourceAccountChartData.*' => 'sometimes|array|size:2',

            'expensePerDestinationAccountChartData' => 'nullable|array',
            'expensePerDestinationAccountChartData.*' => 'sometimes|array|size:2',

            'barChartsPerBudgetData' => 'nullable|array',
            'barChartsPerBudgetData.*.budgetName' => 'sometimes|required_with:barChartsPerBudgetData|string',
            'barChartsPerBudgetData.*.title' => 'sometimes|required_with:barChartsPerBudgetData|string',
            'barChartsPerBudgetData.*.categories' => 'sometimes|required_with:barChartsPerBudgetData|array',
            'barChartsPerBudgetData.*.values' => 'sometimes|required_with:barChartsPerBudgetData|array',

            'topExpensesTableData' => 'nullable|array',
            'topExpensesTableData.*' => 'sometimes|array',
        ];
    }
}