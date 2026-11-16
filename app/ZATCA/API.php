<?php

namespace ZATCA;

use Exception;
use stdClass;

class API
{
    private string $version = 'V2';

    public function getBaseUrl(): string
    {
        $env = function_exists('config') ? config('zatca.environment') : env('ZATCA_ENV', 'developer-portal');

        switch ($env) {
            case 'core':
            case 'production':
                return 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core';
            case 'simulation':
                return 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation';
            case 'developer-portal':
            case 'sandbox':
            default:
                return 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal';
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

    public function issueProductionCSID(string $compliance_request_id, string $compliance_certificate, string $compliance_secret): stdClass
    {
        $auth_headers = $this->getAuthHeaders($compliance_certificate, $compliance_secret);
        $headers = [
            'Accept-Version: ' . $this->version,
            'Content-Type: application/json',
            ...$auth_headers,
        ];

        $curl = curl_init($this->getBaseUrl() . '/production/csid');

        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode(['compliance_request_id' => $compliance_request_id]),
            CURLOPT_HTTPHEADER => $headers,
        ));

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $response_data = json_decode($response);
        if ($http_code != 200) {
            throw new Exception('Error issuing a production certificate. HTTP: ' . $http_code . ' Response: ' . json_encode($response_data));
        }

        $issued_certificate = base64_decode($response_data->binarySecurityToken);
        $response_data->binarySecurityToken = "-----BEGIN CERTIFICATE-----\n{$issued_certificate}\n-----END CERTIFICATE-----";

        return $response_data;
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

            $curl = curl_init($this->getBaseUrl() . '/compliance');

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

            $curl = curl_init($this->getBaseUrl() . '/compliance/invoices');

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

            $curl = curl_init($this->getBaseUrl() . '/invoices/reporting/single');

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

            $curl = curl_init($this->getBaseUrl() . '/invoices/clearance/single');

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