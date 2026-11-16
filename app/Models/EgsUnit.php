<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EgsUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'custom_id',
        'model',
        'crn_number',
        'vat_name',
        'vat_number',
        'branch_name',
        'branch_industry',
        'location',
        'private_key',
        'compliance_certificate',
        'compliance_secret',
        'certificate',
        'secret',
        'request_id',
        'current_counter',
        'last_invoice_hash',
        'status',
        'production',
    ];

    protected $casts = [
        'location' => 'array',
        'production' => 'boolean',
        'current_counter' => 'integer',
    ];

    /**
     * Convert the EGS unit record into the array structure expected by the ZATCA EGS class.
     */
    public function toEgsArray(string $cancelationType = 'INVOICE', string $canceledInvoiceNumber = ''): array
    {
        return [
            'uuid' => $this->uuid,
            'custom_id' => $this->custom_id,
            'model' => $this->model,
            'CRN_number' => $this->crn_number,
            'VAT_name' => $this->vat_name,
            'VAT_number' => $this->vat_number,
            'location' => [
                'city' => $this->location['city'] ?? 'Riyadh',
                'city_subdivision' => $this->location['city_subdivision'] ?? '',
                'street' => $this->location['street'] ?? '',
                'plot_identification' => $this->location['plot_identification'] ?? '0000',
                'building' => $this->location['building'] ?? '',
                'postal_zone' => $this->location['postal_zone'] ?? '',
            ],
            'branch_name' => $this->branch_name,
            'branch_industry' => $this->branch_industry ?? 'Retail',
            'cancelation' => [
                'cancelation_type' => $cancelationType,
                'canceled_invoice_number' => $canceledInvoiceNumber,
            ],
        ];
    }
}
