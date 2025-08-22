<?php

namespace App\Factories;

use App\Models\Company;
use Illuminate\Support\Carbon;

class ReportDateFactory
{
    public Carbon $fiscalYearStartDate;

    public Carbon $fiscalYearEndDate;

    public string $defaultDateRange;

    public Carbon $defaultStartDate;

    public Carbon $defaultEndDate;

    public Carbon $earliestTransactionDate;

    protected Company $company;

    public function __construct(Company $company)
    {
        $this->company = $company;
        $this->buildReportDates();
    }

    protected function buildReportDates(): void
    {
        $companyFyStartDate = Carbon::parse($this->company->locale->fiscalYearStartDate());
        $companyFyEndDate = Carbon::parse($this->company->locale->fiscalYearEndDate())->endOfDay();
        $dateRange = 'FY-' . company_today()->year;
        $startDate = $companyFyStartDate->startOfDay();
        $endDate = $companyFyEndDate->isFuture() ? company_today()->endOfDay() : $companyFyEndDate->endOfDay();

        // Calculate the earliest transaction date based on the company's transactions
        $earliestDate = $this->company->transactions()->min('posted_at')
            ? Carbon::parse($this->company->transactions()->min('posted_at'))->startOfDay()
            : $startDate;

        // Assign values to properties
        $this->fiscalYearStartDate = $companyFyStartDate;
        $this->fiscalYearEndDate = $companyFyEndDate;
        $this->defaultDateRange = $dateRange;
        $this->defaultStartDate = $startDate;
        $this->defaultEndDate = $endDate;
        $this->earliestTransactionDate = $earliestDate;
    }

    public function refresh(): self
    {
        $this->buildReportDates();

        return $this;
    }

    public static function create(Company $company): self
    {
        return new static($company);
    }
}
