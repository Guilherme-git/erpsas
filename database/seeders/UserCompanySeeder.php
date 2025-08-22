<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Setting\DocumentDefault;
use App\Enums\Accounting\DocumentType;
use App\Models\User;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Seeder;

class UserCompanySeeder extends Seeder
{
    public function run(): void
    {
        // Admin + empresa pessoal com BRL/PT-BR
        $user = User::factory()
            ->withPersonalCompany(function (CompanyFactory $factory) {
                return $factory
                    ->state(['name' => 'FinObotZap'])
                    // endereço/UF/telefone BR (gera Address->country_code = BR)
                    ->withCompanyProfile('BR')
                    // moeda BRL + locale pt (número, % e semana conforme Brasil)
                    ->withCompanyDefaults('BRL', 'pt')
                    ->withTransactions(250)
                    ->withOfferings()
                    ->withClients()
                    ->withVendors()
                    ->withInvoices(30)
                    ->withRecurringInvoices()
                    ->withEstimates(30)
                    ->withBills(30);
            })
            ->create([
                'name' => 'Admin',
                'email' => 'admin@obotzap.com',
                'password' => bcrypt('password!'),
                'current_company_id' => 1,
            ]);

        // Empresas adicionais já em BR
        $additionalCompanies = [
            ['name' => 'São Paulo Tech Ltda',     'country' => 'BR', 'currency' => 'BRL', 'locale' => 'pt'],
            ['name' => 'Rio Analytics Serviços',  'country' => 'BR', 'currency' => 'BRL', 'locale' => 'pt'],
            ['name' => 'Curitiba Data Studio',    'country' => 'BR', 'currency' => 'BRL', 'locale' => 'pt'],
        ];

        foreach ($additionalCompanies as $c) {
            Company::factory()
                ->state([
                    'name' => $c['name'],
                    'user_id' => $user->id,
                    'personal_company' => false,
                ])
                ->withCompanyProfile($c['country'])
                ->withCompanyDefaults($c['currency'], $c['locale'])
                ->withTransactions(50)
                ->withOfferings()
                ->withClients()
                ->withVendors()
                ->withInvoices()
                ->withRecurringInvoices()
                ->withEstimates()
                ->withBills()
                ->create();
        }

        // (Opcional) Localizar rótulos/prefixos dos documentos para PT-BR
        $this->localizeDocumentDefaultsToPtBr($user);
    }

    private function localizeDocumentDefaultsToPtBr(User $user): void
    {
        $company = $user->ownedCompanies()->first();

        // Fatura
        DocumentDefault::where('company_id', $company->id)
            ->where('type', DocumentType::Invoice)
            ->update(['header' => 'Fatura', 'number_prefix' => 'FAT']);

        // Orçamento / Proposta
        DocumentDefault::where('company_id', $company->id)
            ->where('type', DocumentType::Estimate)
            ->update(['header' => 'Proposta', 'number_prefix' => 'ORC']);

        // Contas a pagar
        DocumentDefault::where('company_id', $company->id)
            ->where('type', DocumentType::Bill)
            ->update(['header' => 'Conta a Pagar', 'number_prefix' => 'CPG']);
    }
}
