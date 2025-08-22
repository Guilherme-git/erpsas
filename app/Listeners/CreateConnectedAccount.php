<?php

namespace App\Listeners;

use App\Events\PlaidSuccess;
use App\Models\Banking\Institution;
use App\Models\Company;
use App\Services\PlaidService;
use App\Utilities\Currency\CurrencyConverter;
use Illuminate\Support\Facades\DB;

class CreateConnectedAccount
{
    /**
     * Create the event listener.
     */
    public function __construct(
        protected PlaidService $plaidService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(PlaidSuccess $event): void
    {
        DB::transaction(function () use ($event) {
            $this->processPlaidSuccess($event);
        });
    }

    public function processPlaidSuccess(PlaidSuccess $event): void
    {
        $accessToken = $event->accessToken;

        $company = $event->company;

        $authResponse = $this->plaidService->getAccounts($accessToken);

        $institutionResponse = $this->plaidService->getInstitution($authResponse->item->institution_id, $company->profile?->address?->country_code);

        $this->processInstitution($authResponse, $institutionResponse, $company, $accessToken);
    }

    public function processInstitution($authResponse, $institutionResponse, Company $company, $accessToken): void
    {
        $institution = Institution::updateOrCreate([
            'external_institution_id' => $authResponse->item->institution_id ?? null,
        ], [
            'name' => $institutionResponse->institution->name ?? null,
            'logo' => $institutionResponse->institution->logo ?? null,
            'website' => $institutionResponse->institution->url ?? null,
        ]);

        foreach ($authResponse->accounts as $plaidAccount) {
            $this->processConnectedBankAccount($plaidAccount, $company, $institution, $authResponse, $accessToken);
        }
    }

    public function processConnectedBankAccount($plaidAccount, Company $company, Institution $institution, $authResponse, $accessToken): void
    {
        $identifierHash = md5($company->id . $institution->external_institution_id . $plaidAccount->name . $plaidAccount->mask);

        $currencyCode = $plaidAccount->balances->iso_currency_code ?? 'USD';

        $currentBalance = $plaidAccount->balances->current ?? 0;

        $currentBalanceCents = CurrencyConverter::convertToCents($currentBalance, $currencyCode);

        $company->connectedBankAccounts()->updateOrCreate([
            'identifier' => $identifierHash,
        ], [
            'institution_id' => $institution->id,
            'external_account_id' => $plaidAccount->account_id,
            'access_token' => $accessToken,
            'item_id' => $authResponse->item->item_id,
            'currency_code' => $currencyCode,
            'current_balance' => $currentBalanceCents,
            'name' => $plaidAccount->name,
            'mask' => $plaidAccount->mask,
            'type' => $plaidAccount->type,
            'subtype' => $plaidAccount->subtype,
            'import_transactions' => false,
        ]);
    }
}
