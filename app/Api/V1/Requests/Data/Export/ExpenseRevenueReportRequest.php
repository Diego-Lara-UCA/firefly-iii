<?php

namespace FireflyIII\Api\V1\Requests\Data\Export; // Ajusta este namespace

use FireflyIII\Support\Request\ChecksLogin;
use Illuminate\Foundation\Http\FormRequest;

// Nombre de la clase actualizado
class ExpenseRevenueReportRequest extends FormRequest 
{
    use ChecksLogin;

    public function rules(): array
    {
        return [
            'accountsTableData' => 'nullable|array',
            'tagsTableData' => 'nullable|array',
            'accountPerTagTableData' => 'nullable|array',
            'accountPerTagTableHeaders' => 'sometimes|required_with:accountPerTagTableData|array',
            'avgExpenseDestAccountTableData' => 'nullable|array',
            'avgEarningSourceAccountTableData' => 'nullable|array',
            'topExpensesTableData' => 'nullable|array',
            'topRevenueTableData' => 'nullable|array',
            'expensePerTagChartData' => 'nullable|array',
            'expensePerCategoryChartData' => 'nullable|array',
            'incomePerCategoryChartData' => 'nullable|array',
            'expensePerBudgetChartData' => 'nullable|array',
            'expensesPerSourceAccountChartData' => 'nullable|array',
            'incomePerSourceAccountChartData' => 'nullable|array',
            'expensesPerDestinationAccountChartData' => 'nullable|array',
            'incomePerDestinationAccountChartData' => 'nullable|array',
            'barChartsPerTagData' => 'nullable|array',
            'barChartsPerTagData.*.tagName' => 'sometimes|required|string',
            'barChartsPerTagData.*.title' => 'sometimes|required|string',
            'barChartsPerTagData.*.categories' => 'sometimes|required|array',
            'barChartsPerTagData.*.series' => 'sometimes|required|array',
        ];
    }
}