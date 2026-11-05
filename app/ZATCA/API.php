<?php

namespace ZATCA;

use Exception;
use stdClass;

class API
{
    private string $base_url;
    private string $version = 'V2';

    public function __construct(?string $base_url = null)
    {
        if ($base_url) {
            $this->base_url = rtrim($base_url, '/');
        } elseif (function_exists('config')) {
            $env = config('zatca.env', 'sandbox');
            $this->base_url = rtrim(config("zatca.urls.{$env}", 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal'), '/');
        } else {
            $this->base_url = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal';
        }
    }

    private function getAuthHeaders($certificate, $secret): array
    {
        if ($certificate && $secret) {

            $certificate_stripped = $this->cleanUpCertificateString($certificate);
            $certificate_stripped = base64_encode($certificate_stripped);
            $basic = base64_encode($certificate_stripped . ':' . $secret);

            return [
                "Authorization: Basic $basic",
            ];
        }
        return [];
    }

    public function compliance($certificate = NULL, $secret = NULL)
    {
        $auth_headers = $this->getAuthHeaders($certificate, $secret);
        $issueCertificate = function (string $csr, string $otp): stdClass {
            $headers = [
                'Accept-Version: ' . $this->version,
                'OTP: ' . $otp,
                'Content-Type: application/json'
            ];

            $curl = curl_init($this->base_url . '/compliance');

            curl_setopt_array($curl, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode(['csr' => base64_encode($csr)]),
                CURLOPT_HTTPHEADER => $headers,
            ));

            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $response = json_decode($response);
            if ($http_code != 200) {
                throw new Exception('Error issuing a compliance certificate. HTTP: ' . $http_code . ' Response: ' . json_encode($response));
            }

            $issued_certificate = base64_decode($response->binarySecurityToken);
            $response->binarySecurityToken = "-----BEGIN CERTIFICATE-----\n{$issued_certificate}\n-----END CERTIFICATE-----";

            return $response;
        };
        $checkInvoiceCompliance = function (string $signed_invoice_string, string $invoice_hash, string $uuid) use ($auth_headers) {
            $headers = [
                'Accept-Version: ' . $this->version,
                'Accept-Language: en',
                'Content-Type: application/json',
            ];

            $curl = curl_init($this->base_url . '/compliance/invoices');

            curl_setopt_array($curl, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode([
                    'invoiceHash' => $invoice_hash,
                    'uuid' => $uuid,
                    'invoice' => base64_encode($signed_invoice_string),
                ]),
                CURLOPT_HTTPHEADER => [...$headers, ...$auth_headers],
            ));

            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $response = json_decode($response);
            if ($http_code != 200 && $http_code != 202) {
                throw new Exception('Error in compliance check. HTTP: ' . $http_code . ' Response: ' . json_encode($response));
            }
            return $response;
        };

        return [$issueCertificate, $checkInvoiceCompliance];
    }

    public function invoices($certificate = NULL, $secret = NULL)
    {
        $auth_headers = $this->getAuthHeaders($certificate, $secret);

        $reportInvoice = function (string $signed_invoice_string, string $invoice_hash, string $uuid) use ($auth_headers) {
            $headers = [
                'Accept-Version: ' . $this->version,
                'Accept-Language: en',
                'Content-Type: application/json',
            ];

            $curl = curl_init($this->base_url . '/invoices/reporting/single');

            curl_setopt_array($curl, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode([
                    'invoiceHash' => $invoice_hash,
                    'uuid' => $uuid,
                    'invoice' => base64_encode($signed_invoice_string),
                ]),
                CURLOPT_HTTPHEADER => [...$headers, ...$auth_headers],
            ));

            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $response = json_decode($response);
            if ($http_code != 200 && $http_code != 202) {
                throw new Exception('Error in reporting invoice. HTTP: ' . $http_code . ' Response: ' . json_encode($response));
            }
            return $response;
        };

        $clearInvoice = function (string $signed_invoice_string, string $invoice_hash, string $uuid) use ($auth_headers) {
            $headers = [
                'Accept-Version: ' . $this->version,
                'Accept-Language: en',
                'Content-Type: application/json',
            ];

            $curl = curl_init($this->base_url . '/invoices/clearance/single');

            curl_setopt_array($curl, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode([
                    'invoiceHash' => $invoice_hash,
                    'uuid' => $uuid,
                    'invoice' => base64_encode($signed_invoice_string),
                ]),
                CURLOPT_HTTPHEADER => [...$headers, ...$auth_headers],
            ));

            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $response = json_decode($response);
            if ($http_code != 200 && $http_code != 202) {
                throw new Exception('Error in clearing invoice. HTTP: ' . $http_code . ' Response: ' . json_encode($response));
            }
            return $response;
        };

        return [$reportInvoice, $clearInvoice];
    }

    public static function cleanUpCertificateString(string $certificate): string
    {
        $certificate = str_replace('-----BEGIN CERTIFICATE-----', '', $certificate);
        $certificate = str_replace('-----END CERTIFICATE-----', '', $certificate);

        return trim($certificate);
    }
}