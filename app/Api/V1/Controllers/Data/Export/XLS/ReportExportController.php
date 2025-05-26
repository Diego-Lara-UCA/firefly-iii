<?php

namespace FireflyIII\Api\V1\Controllers\Data\Export\XLS;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Data\Export\DefaultFinancialXLSExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\TransactionHistoryXLSExportRequest;
use FireflyIII\Api\V1\Requests\Data\Export\BudgetXLSExportRequest;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Support\Export\ExportXlsData;
use Illuminate\Http\JsonResponse;

class ReportExportController extends Controller
{
    private ExportXlsData $exporter;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(
            function ($request, $next) {
                $this->exporter = app(ExportXlsData::class);
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
    public function DefaultReport(DefaultFinancialXLSExportRequest $request): JsonResponse
    {
        return $this->exporter->GenerateDefaultReport($request);
    }
}
