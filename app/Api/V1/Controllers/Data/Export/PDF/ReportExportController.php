<?php

namespace FireflyIII\Api\V1\Controllers\Data\Export\PDF;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Data\Export\DefaultReportExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TransactionHistoryExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\BudgetExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\CategoryReportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TagReportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\ExpenseRevenueReportRequest;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Support\Export\ExportPdfData;
use Symfony\Component\HttpFoundation\Response;

class ReportExportController extends Controller
{
    private ExportPdfData $exporter;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(
            function ($request, $next) {
                $this->exporter = app(ExportPdfData::class);
                //$this->exporter->setUser(auth()->user());
                return $next($request);
            }
        );
    }

    /**
     * Default report export
     *
     * @throws FireflyException
     */
    public function DefaultReport(DefaultReportExportRequest $request): Response
    {
        return $this->exporter->GenerateDefaultReport($request);
    }

    /**
     * Transaction history report export
     *
     * @throws FireflyException
     */
    public function TransactionHistoryReport(TransactionHistoryExportRequest $request): Response
    {
        return $this->exporter->GenerateTransactionReport($request);
    }

    /**
     * Budget report export
     *
     * @throws FireflyException
     */
    public function BudgetReport(BudgetExportRequest $request): Response 
    {
        return $this->exporter->GenerateBudgetReport($request);
    }

    /**
     * Category report export
     *
     * @throws FireflyException
     */
    public function CategoryReport(CategoryReportRequest $request): Response 
    {
        return $this->exporter->GenerateCategoryReport($request);
    }

    /**
     * Tag report export
     *
     * @throws FireflyException
     */
    public function TagReport(TagReportRequest $request): Response 
    {
        return $this->exporter->GenerateTagReport($request);
    }

    /**
     * Expense/Revenue report export
     *
     * @throws FireflyException
     */
    public function ExpenseRevenueReport(ExpenseRevenueReportRequest $request): Response 
    {
        return $this->exporter->GenerateExpenseRevenueReport($request);
    }
}
