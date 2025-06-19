<?php

namespace FireflyIII\Api\V1\Requests\Data\Export; // Ajusta este namespace

use FireflyIII\Support\Request\ChecksLogin;
use Illuminate\Foundation\Http\FormRequest;

class CategoryReportRequest extends FormRequest
{
    use ChecksLogin;


    public function rules(): array
    {
        return [
            'accountsTableData' => 'nullable|array',
            'categoriesTableData' => 'nullable|array',
            'accountPerCategoryTableData' => 'nullable|array',
            'accountPerCategoryTableHeaders' => 'sometimes|required_with:accountPerCategoryTableData|array',
            'avgExpenseDestAccountTableData' => 'nullable|array',
            'avgEarningSourceAccountTableData' => 'nullable|array',
            'topExpensesTableData' => 'nullable|array',
            'topRevenueTableData' => 'nullable|array',

            'expensePerCategoryChartData' => 'nullable|array',
            'incomePerCategoryChartData' => 'nullable|array',
            'expensePerBudgetChartData' => 'nullable|array',
            'expensesPerSourceAccountChartData' => 'nullable|array',
            'incomePerSourceAccountChartData' => 'nullable|array',
            'expensesPerDestinationAccountChartData' => 'nullable|array',
            'incomePerDestinationAccountChartData' => 'nullable|array',
            
            'barChartsPerCategoryData' => 'nullable|array',
            'barChartsPerCategoryData.*.categoryName' => 'sometimes|required|string',
            'barChartsPerCategoryData.*.title' => 'sometimes|required|string',
            'barChartsPerCategoryData.*.categories' => 'sometimes|required|array',
            'barChartsPerCategoryData.*.values' => 'sometimes|required|array',    
        ];
    }
}
