<?php

namespace App\Library\Tasks;

use App\Library\Tasks\Base\BaseCtxExportTask;
use App\Library\Tasks\Base\XmlImportTask;
use Cybertill\Framework\Http\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class OfferQualifierTokenBinImportTask extends XmlImportTask
{
    const MAX_FILE_SIZE = 15 * 1024 * 1024; // 15 MB
    const ENDPOINT = '/api/offer-qualifier-token-bin-feed-batch';

    public function processFile($contents)
    {
        $contents = $this->validateContents($contents);

        if (count($this->errors)) {
            $this->processErrors();
            return false;
        }

        $contentAsArray = $this->convertXmlToArray(
            $this->parseXml($contents)
        );

        // Make request
        try {
            $response = Client::post(
                $this->getUrl(),
                $contentAsArray
            );

        } catch (ClientException $e) {
            if ($e->getCode() == 422) {
                $errorMessages = json_decode($e->getResponse()->getBody()->getContents(), true);
                $this->mapValidationErrors($errorMessages);
                $this->processErrors();
                return false;
            }

            $this->errors[] = ['line' => 0, 'message' => $e->getResponse()->getBody()->getContents()];
            $this->processErrors();
            return false;
        } catch (\Exception $e) {
            $this->errors[] = ['line' => 0, 'message' => $e->getMessage()];
            $this->processErrors();
            return false;
        }

        if($response->getStatusCode() !== 200)
        {
            $responseBodyContents = json_decode($response->getBody()->getContents(), true);

            if ($this->responseHasErrors($responseBodyContents)) {
                $this->mapErrors($responseBodyContents['error']);
                $this->processErrors();
                return false;
            }
        }

        return true;
    }

    protected function processErrors(): void
    {
        if (count($this->errors) === 0) {
            return;
        }

        foreach ($this->errors as $error) {
            if ($error['line'] == "error") {
                $error['line'] = 0;
            }
            $this->recordError($this->scheduledTaskLogFile, $error['line'], json_encode($error['message']));
        }
        // Write error file in standard way
        $this->writeErrorFile($this->errorName, $this->errors);
    }

    protected function mapValidationErrors(array $errors): void
    {
        $e = $errors['error'] ?? $errors;
        foreach (array_keys($e) as $index => $key) {
            // Validation error at offerList level
            $this->errors[] = [
                'line' => 0,
                'message' => json_encode($e[$key])
            ];
        }
    }

    protected function mapErrors($errors): void
    {
        $this->errors[] = json_encode($errors);
    }

    protected function responseHasErrors($response): bool
    {
        if (is_array($response) && array_key_exists('error', $response) && count($response['error'])) {
            return true;
        }

        return false;
    }

    protected function getUrl(): string
    {
        return env('CT_OFFER_SERVICE', 'https://offer-service') . self::ENDPOINT;
    }

    protected function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }

    protected function getXmlChildNodes(): array
    {
        return [
            'offerTokenType',
            'token',
        ];
    }
}
