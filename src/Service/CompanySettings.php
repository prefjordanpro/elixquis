<?php

namespace App\Service;

class CompanySettings
{
    public function __construct(#[\Symfony\Component\DependencyInjection\Attribute\Autowire('%invoice.seller%')] private array $invoiceSeller = []) {}
    public function invoiceDetails(): array
    {
        $seller = $this->invoiceSeller;
        $shared = $this->details();
        foreach (['name'=>'legalName', 'address'=>'address', 'registration'=>'siret', 'vat'=>'vat', 'email'=>'email'] as $field=>$target) {
            if (trim($shared[$field]) !== '') $seller[$target] = $shared[$field];
        }
        foreach (['legalName', 'legalForm', 'capital', 'address', 'siren', 'siret', 'rcs', 'vat', 'email', 'phone'] as $field) {
            if (trim($seller[$field] ?? '') === '') throw new \DomainException('Les coordonnées vendeur de facturation doivent être complétées.');
        }
        return $seller;
    }
    public function details(): array
    {
        $details = [];
        foreach (['name', 'address', 'registration', 'vat', 'email'] as $field) {
            $details[$field] = $_ENV['COMPANY_'.strtoupper($field)] ?? $_SERVER['COMPANY_'.strtoupper($field)] ?? '';
        }
        $details['ready'] = $details['name'] !== '' && $details['address'] !== '' && $details['registration'] !== '';
        return $details;
    }
}
