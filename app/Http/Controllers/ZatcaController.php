<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use ZATCA\EGS;
use ZATCA\GenerateQrCode;
use ZATCA\Tags\Seller;
use ZATCA\Tags\TaxNumber;
use ZATCA\Tags\InvoiceDate;
use ZATCA\Tags\InvoiceTotalAmount;
use ZATCA\Tags\InvoiceTaxAmount;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

class ZatcaController extends Controller
{
    public function index()
    {
        return view('welcome');
    }

    public function getInvoices()
    {
        return response()->json(\App\Models\Invoice::latest()->get());
    }

    public function downloadXml(\App\Models\Invoice $invoice)
    {
        if (!$invoice->signed_xml) {
            return response()->json(['message' => 'XML not available for this invoice.'], 404);
        }

        return response($invoice->signed_xml)
            ->header('Content-Type', 'text/xml')
            ->header('Content-Disposition', 'attachment; filename="' . $invoice->invoice_number . '.xml"');
    }

    public function phase1()
    {
        // Data for QR Code
        $generatedString = GenerateQrCode::fromArray([
            new Seller('Qr'), // seller name
            new TaxNumber('323457892389823'), // seller tax number
            new InvoiceDate('2021-07-12T14:25:09Z'), // invoice date as Zulu ISO8601
            new InvoiceTotalAmount('100.00'), // invoice total amount
            new InvoiceTaxAmount('15.00') // invoice tax amount
        ])->toBase64();

        // Generate QR Code
        $qrCode = QrCode::create($generatedString)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
            ->setSize(300)
            ->setMargin(10)
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));

        // Save QR Code to file
        $writer = new PngWriter();
        // Assuming logo.png exists in public/assets/logo.png
        $logoPath = public_path('assets/logo.png');
        $logo = null;
        if (file_exists($logoPath)) {
            $logo = Logo::create($logoPath)
                ->setResizeToWidth(50)
                ->setPunchoutBackground(true);
        }

        $label = Label::create('Qr Phase-1')
            ->setTextColor(new Color(255, 0, 0));

        $result = $writer->write($qrCode, $logo, $label);
        $result->saveToFile(public_path('assets/phase-1.png'));

        return response($result->getString())->header('Content-Type', $result->getMimeType());
    }

    public function phase2(Request $request)
    {
        $data = $request->all();


        $egs_unit = [
            'uuid' => $data['seller']['uuid'],
            'custom_id' => $data['seller']['custom_id'],
            'model' => $data['seller']['model'],
            'CRN_number' => $data['seller']['crn'],
            'VAT_name' => $data['seller']['name'],
            'VAT_number' => $data['seller']['vat_number'],
            'location' => [
                'city' => $data['location']['city'],
                'city_subdivision' => $data['location']['city_subdivision'],
                'street' => $data['location']['street'],
                'plot_identification' => $data['location']['plot'] ?? '0000',
                'building' => $data['location']['building'],
                'postal_zone' => $data['location']['postal'],
            ],
            'branch_name' => $data['seller']['name'] . ' Branch',
            'branch_industry' => 'Retail',
            'cancelation' => [
                'cancelation_type' => 'INVOICE',
                'canceled_invoice_number' => '',
            ],
        ];

        $invoice = [
            'invoice_counter_number' => $data['invoice']['counter'],
            'invoice_serial_number' => $data['invoice']['number'],
            'issue_date' => $data['invoice']['date'],
            'issue_time' => $data['invoice']['time'],
            'previous_invoice_hash' => $data['invoice']['previous_hash'],
            'line_items' => array_map(function ($index, $item) {
                return [
                    'id' => (string) ($index + 1),
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'tax_exclusive_price' => $item['price'],
                    'VAT_percent' => $item['vat_percent'],
                    'other_taxes' => [],
                    'discounts' => [],
                ];
            }, array_keys($data['items']), $data['items']),
        ];

        try {
            $egs = new EGS($egs_unit);
            $egs->production = false;

            // Generate private key & csr
            list($private_key, $csr) = $egs->generateNewKeysAndCSR('Qr');

            // Make an request to issue compliance certificate (Using a dummy OTP for testing)
            list($request_id, $binary_security_token, $secret) = $egs->issueComplianceCertificate('123345', $csr);

            // Sign invoice xml
            list($signed_invoice_string, $invoice_hash, $qr) = $egs->signInvoice($invoice, $egs_unit, $binary_security_token, $private_key);

            // Check compliance
            $invoiceCompliance = $egs->checkInvoiceCompliance($signed_invoice_string, $invoice_hash, $binary_security_token, $secret);

            // Generate QR Code
            $qrCode = QrCode::create($qr)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
                ->setSize(300)
                ->setMargin(10)
                ->setForegroundColor(new Color(0, 0, 0))
                ->setBackgroundColor(new Color(255, 255, 255));

            $writer = new PngWriter();
            $filename = 'phase-2-' . time() . '.png';
            $result = $writer->write($qrCode);
            $result->saveToFile(public_path('assets/' . $filename));

            // Calculate totals for DB
            $total_amount = 0;
            $vat_amount = 0;
            foreach ($data['items'] as $item) {
                $subtotal = $item['quantity'] * $item['price'];
                $total_amount += $subtotal * (1 + $item['vat_percent']);
                $vat_amount += $subtotal * $item['vat_percent'];
            }

            // Save to database
            $db_invoice = \App\Models\Invoice::create([
                'seller_name' => $data['seller']['name'],
                'vat_number' => $data['seller']['vat_number'],
                'invoice_number' => $data['invoice']['number'],
                'issue_date' => $data['invoice']['date'],
                'issue_time' => $data['invoice']['time'],
                'total_amount' => $total_amount,
                'vat_amount' => $vat_amount,
                'invoice_hash' => $invoice_hash,
                'qr_code' => $qr,
                'qr_url' => asset('assets/' . $filename),
                'items_data' => $data['items'],
                'compliance_results' => json_decode($invoiceCompliance, true),
                'signed_xml' => $signed_invoice_string,
            ]);

            return response()->json([
                'success' => true,
                'invoice_hash' => $invoice_hash,
                'qr_url' => asset('assets/' . $filename),
                'compliance' => json_decode($invoiceCompliance),
                'request_id' => $request_id,
                'invoice_id' => $db_invoice->id
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
