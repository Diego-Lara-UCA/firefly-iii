<?php

namespace FireflyIII\Api\V1\Controllers\Data\Export\PDF;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Data\Export\DefaultReportExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TransactionHistoryExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\BudgetExportRequest;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Support\Export\ExportPdfData;
use Illuminate\Http\JsonResponse;

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
    public function DefaultReport(DefaultReportExportRequest $request): JsonResponse
    {
        return $this->exporter->GenerateDefaultReport($request);
    }

    /**
     * Transaction history report export
     *
     * @throws FireflyException
     */
    public function TransactionHistoryReport(TransactionHistoryExportRequest $request): JsonResponse
    {
        return $this->exporter->GenerateTransactionReport($request);
    }

    /**
     * Budget report export
     *
     * @throws FireflyException
     */
    public function BudgetReport(BudgetExportRequest $request): JsonResponse 
    {
        return $this->exporter->GenerateBudgetReport($request);
    }
}
