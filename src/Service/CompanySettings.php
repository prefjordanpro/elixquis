<?php

namespace App\Service;

class CompanySettings
{
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
