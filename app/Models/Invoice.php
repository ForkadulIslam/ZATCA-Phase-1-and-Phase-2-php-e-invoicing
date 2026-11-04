<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_name',
        'vat_number',
        'invoice_number',
        'issue_date',
        'issue_time',
        'total_amount',
        'vat_amount',
        'invoice_hash',
        'qr_code',
        'qr_url',
        'items_data',
        'compliance_results',
        'signed_xml',
    ];

    protected $casts = [
        'items_data' => 'array',
        'compliance_results' => 'array',
    ];
}
