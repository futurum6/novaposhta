<?php

namespace Futurum\Delivery;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class NovaPoshta
{
    private Client $client;
    private string $api;
    private int $limit;
    private string $language;

    public function __construct(Client $client, string $api, int $limit = 20, string $language = 'UA')
    {
        $this->client = $client;
        $this->api = $api;
        $this->limit = $limit;
        $this->language = $language;
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    public function setApi(string $api): void
    {
        $this->api = $api;
    }

    private function send(array $data): array
    {
        try {
            $response = $this->client->post('https://api.novaposhta.ua/v2.0/json/', [
                'json' => $data,
                'headers' => ['Content-Type' => 'application/json'],
            ]);

            return $this->handleResponse($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    private function handleResponse(string $responseJson): array
    {
        $response = json_decode($responseJson);

        if (isset($response->success) && $response->success) {
            return is_array($response->data) && !empty($response->data)
                ? ['success' => true, 'data' => $response->data]
                : $this->handleError($response);
        }

        return $this->handleError($response);
    }

    private function handleError($response): array
    {
        return [
            'success' => false,
            'errors' => $response->errors ?? ['Unknown error occurred.'],
        ];
    }

    private function handleException(GuzzleException $e): array
    {
        return [
            'success' => false,
            'errors' => ['API request failed: ' . $e->getMessage()],
        ];
    }

    public function addCounterpartyRecipient(string $cityRef, array $recipient): array
    {
        return $this->sendRequest('Counterparty', 'save', [
            'CityRef' => $cityRef,
            'FirstName' => $recipient['FirstName'],
            'MiddleName' => $recipient['MiddleName'],
            'LastName' => $recipient['LastName'],
            'Phone' => $recipient['Phone'],
            'Email' => "",
            'CounterpartyType' => "PrivatePerson",
            'CounterpartyProperty' => 'Recipient'
        ]);
    }

    public function addAddressCounterparty(string $counterpartyRef, array $address): array
    {
        return $this->sendRequest('Address', 'save', [
            'CounterpartyRef' => $counterpartyRef,
            'FindByString' => $address['FindByString'] ?? null,
            'StreetRef' => $address['StreetRef'] ?? null,
            'BuildingNumber' => $address['BuildingNumber'],
            'Flat' => $address['Flat']
        ]);
    }

    public function deleteInternetDocument(array $documentRefs): array
    {
        return $this->sendRequest('InternetDocument', 'delete', [
            'DocumentRefs' => $documentRefs
        ]);
    }

    public function getStatus(array $documentNumbers, string $phone = ''): array
    {
        return $this->sendRequest('TrackingDocument', 'getStatusDocuments', [
            'Documents' => $documentNumbers
        ]);
    }

    public function getRegistry(): array
    {
        return $this->sendRequest('ScanSheet', 'getScanSheetList');
    }

    public function getMarkingZebra(array $trackingNumbers)
    {
        $url = "https://my.novaposhta.ua/orders/printMarking100x100/orders/" . implode(',', $trackingNumbers) . "/type/pdf/zebra/zebra/apiKey/{$this->api}";

        return $this->getPdf($url);
    }

    public function addRegistry(array $documentRefs, ?string $registryRef = null): array
    {
        return $this->sendRequest('ScanSheet', 'insertDocuments', [
            'DocumentRefs' => $documentRefs,
            'Ref' => $registryRef
        ]);
    }

    public function deleteRegistry(array $documentRefs, string $ref): array
    {
        return $this->sendRequest('ScanSheet', 'removeDocuments', [
            'DocumentRefs' => $documentRefs,
            'Ref' => $ref
        ]);
    }

    public function getPackListSpecial(): array
    {
        return $this->sendRequest('Common', 'getPackListSpecial', [
            'Length' => 10,
            'Width' => 10,
            'Height' => 190,
            'PackForSale' => 1
        ]);
    }
    public function getCounterpartyContactPersons(string $ref): array
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => 'Counterparty',
            'calledMethod' => 'getCounterpartyContactPersons',
            'methodProperties' => [
                'Ref' => $ref,
            ],
        ];

        return $this->send($query);
    }

    public function getCounterpartySender(): array
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => 'Counterparty',
            'calledMethod' => 'getCounterparties',
            'methodProperties' => [
                'CounterpartyProperty' => 'Sender',
            ],
        ];

        return $this->send($query);
    }

    public function getDocumentList(string $dateTimeFrom, string $dateTimeTo, bool $redeliveryMoney = false, bool $unassembledCargo = false): array
    {
        return $this->sendRequest('InternetDocument', 'getDocumentList', [
            'DateTimeFrom' => $dateTimeFrom,
            'DateTimeTo' => $dateTimeTo,
            'RedeliveryMoney' => $redeliveryMoney,
            'UnassembledCargo' => $unassembledCargo,
            'GetFullList' => 1
        ]);
    }

    public function addTtn(array $data): array
    {
        $methodProperties = [
            'NewAddress' => '1',
            'PayerType' => 'Recipient',
            'PaymentMethod' => 'Cash',
            'CargoType' => "Parcel",
            'SeatsAmount' => count($data['details']),
            'Weight' => array_sum(array_column($data['details'], 'weight')),
            'ServiceType' => $data['serviceType'],
            'Description' => $data['description'] . '. №' . $data['orderId'],
            'InfoRegClientBarcodes' => $data['orderId'],
            'DateTime' => date('d.m.Y', strtotime('+1 day')),
            'CitySender' => $data['citySender'],
            'Sender' => $data['senderRef'],
            'SenderAddress' => $data['warehouseRef'],
            'ContactSender' => $data['contactRef'],
            'SendersPhone' => $data['senderPhone'],
            'RecipientType' => 'PrivatePerson',
            'RecipientName' => implode(' ', [$data['recipientSurname'], $data['recipientName'], $data['recipientPatronymic']]),
            'RecipientsPhone' => $data['recipientPhone']
        ];

        if ($data['EDRPOU']) {
            $methodProperties['EDRPOU'] = $data['EDRPOU'];
        }

        if ($data['serviceType'] === 'WarehouseWarehouse') {
            $this->addWarehouseWarehouseProperties($methodProperties, $data);
        } elseif ($data['serviceType'] === 'WarehouseDoors') {
            return $this->addWarehouseDoorsProperties($methodProperties, $data);
        }

        if ($data['paid'] === 0) {
            $this->addPaymentProperties($methodProperties, $data);
        }

        return $this->sendRequest('InternetDocument', 'save', $methodProperties);
    }

    private function addWarehouseWarehouseProperties(array &$methodProperties, array $data): void
    {
        $methodProperties['RecipientCityName'] = $data['recipientCity']->name_ua;
        $methodProperties['RecipientArea'] = $data['recipientCity']->district_ua;
        $methodProperties['RecipientAreaRegions'] = $data['recipientCity']->region_ua;
        $methodProperties['RecipientAddressName'] = $data['recipientAddress']->number;
    }

    private function addWarehouseDoorsProperties(array &$methodProperties, array $data): array
    {
        $recipient = $this->addCounterpartyRecipient($data['recipientCity']->ref_np, [
            'FirstName' => $data['recipientName'],
            'MiddleName' => $data['recipientPatronymic'],
            'LastName' => $data['recipientSurname'],
            'Phone' => $data['recipientPhone']
        ]);

        if (!$recipient['success']) {
            return $recipient;
        }

        $counterpartyAddress = $this->addAddressCounterparty($recipient['data'][0]->Ref, [
            'StreetRef' => $data['recipientAddress']->ref,
            'BuildingNumber' => $data['recipientAddress']->buildingNumber,
            'Flat' => $data['recipientAddress']->flat
        ]);

        if (!$counterpartyAddress['success']) {
            return $counterpartyAddress;
        }

        $methodProperties['CityRecipient'] = $data['recipientCity']->ref_np;
        $methodProperties['Recipient'] = $recipient['data'][0]->Ref;
        $methodProperties['RecipientAddress'] = $counterpartyAddress['data'][0]->Ref;
        $methodProperties['RecipientAddressName'] = $counterpartyAddress['data'][0]->Description;
        $methodProperties['ContactRecipient'] = $recipient['data'][0]->ContactPerson->data[0]->Ref;

        return $this->sendRequest('InternetDocument', 'save', $methodProperties);
    }

    private function addPaymentProperties(array &$methodProperties, array $data): void
    {
        if ($data['EDRPOU']) {
            $methodProperties['AfterpaymentOnGoodsCost'] = (int)$data['totalPrice'];
        } else {
            $methodProperties['BackwardDeliveryData'][0] = [
                'PayerType' => "Recipient",
                'CargoType' => "Money",
                'RedeliveryString' => (int)$data['totalPrice']
            ];
        }
    }

    private function sendRequest(string $modelName, string $calledMethod, array $methodProperties = []): array
    {
        return $this->send([
            'apiKey' => $this->api,
            'modelName' => $modelName,
            'calledMethod' => $calledMethod,
            'methodProperties' => $methodProperties,
        ]);
    }

    private function getPdf(string $url)
    {
        try {
            $response = $this->client->get($url, ['stream' => true]);
            return $response->getStatusCode() === 200 ? $response->getBody()->getContents() : null;
        } catch (GuzzleException $e) {
            return null;
        }
    }
}
