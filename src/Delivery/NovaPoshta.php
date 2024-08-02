<?php

namespace Futurum\Delivery;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;

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
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $responseJson = $response->getBody()->getContents();
            $response = json_decode($responseJson);

            if ($response->success) {
                if (is_array($response->data) && isset($response->data[0])) {
                    return $response->data;
                } else {
                    dd('error', $response);
                }
            } else {
                return ['success' => false, 'errors' => $response->errors];
            }
        } catch (GuzzleException $e) {
            throw new \RuntimeException('API request failed: ' . $e->getMessage());
        }
    }

    public function getCity(string $city = '', ?string $ref = null): Collection
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "Address",
            'calledMethod' => "getCities",
            'methodProperties' => [
                'Limit' => $this->limit,
                'FindByString' => $city
            ]
        ];

        $response = $this->send($query);

        return collect($response)->map(function ($city) {
            $description = 'Description' . $this->language;
            return [
                'ref' => $city->Ref,
                'cityName' => $city->$description,
            ];
        });
    }

    public function getSettlements(string $city = '', ?string $ref = null): Collection
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "Address",
            'calledMethod' => "searchSettlements",
            'methodProperties' => [
                'Limit' => $this->limit,
                'Ref' => $ref,
                'CityName' => $city
            ]
        ];

        $response = $this->send($query);

        if (isset($response['success']) && !$response['success']) {
            return collect([]);
        }

        return collect($response[0]->Addresses)->map(function ($city) {
            return (object) [
                'ref' => $city->Ref,
                'deliveryCity' => $city->DeliveryCity,
                'present' => $city->Present,
                'mainDescription' => $city->MainDescription,
                'area' => $city->Area,
                'region' => $city->Region,
            ];
        });
    }

    public function getStreet(string $CityRef = '', string $FindByString = ''): Collection
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "Address",
            'calledMethod' => "searchSettlementStreets",
            'methodProperties' => [
                'SettlementRef' => $CityRef,
                'StreetName' => $FindByString
            ]
        ];

        $response = $this->send($query);

        if (isset($response['success']) && !$response['success']) {
            return collect([]);
        }

        return collect($response[0]->Addresses)->map(function ($street) {
            return [
                'ref' => $street->SettlementStreetRef,
                'description' => $street->Present,
            ];
        });
    }

    public function getWarehouses(string $cityRef, ?string $WarehouseId = null): Collection
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "AddressGeneral",
            'calledMethod' => "getWarehouses",
            'methodProperties' => [
                'CityRef' => $cityRef,
                'WarehouseId' => (string)$WarehouseId,
                'Limit' => $this->limit
            ]
        ];

        $response = $this->send($query);

        if (isset($response['success']) && !$response['success']) {
            return collect([]);
        }

        return collect($response)->map(function ($warehouse) {
            $description = ($this->language == 'UA') ? 'Description' : 'DescriptionRu';

            return [
                'number' => $warehouse->Number,
                'ref' => $warehouse->Ref,
                'description' => $warehouse->$description,
                'maxWeight' => $warehouse->TotalMaxWeightAllowed,
            ];
        });
    }

    public function getCounterpartySender(): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "Counterparty",
            'calledMethod' => "getCounterparties",
            'methodProperties' => [
                'CounterpartyProperty' => 'Sender'
            ]
        ];

        return $this->send($query);
    }

    public function getCounterpartyContactPersons(string $ref): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "Counterparty",
            'calledMethod' => "getCounterpartyContactPersons",
            'methodProperties' => [
                'Ref' => $ref
            ]
        ];

        return $this->send($query);
    }

    public function contactPerson(string $ref, array $user): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "ContactPerson",
            'calledMethod' => "update",
            'methodProperties' => [
                'CounterpartyRef' => $ref,
                'FirstName' => $user['name'],
                'LastName' => $user['surname'],
                'MiddleName' => $user['patronymic'],
                'Phone' => $user['phone']
            ]
        ];

        return $this->send($query);
    }

    public function getCounterpartyOptions(string $ref): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "Counterparty",
            'calledMethod' => "getCounterpartyOptions",
            'methodProperties' => [
                'Ref' => $ref
            ]
        ];

        return $this->send($query);
    }

    public function phoneFormatter(string $phone): string
    {
        return str_replace(['+', '(', ')', '-', ' '], '', $phone);
    }

    public function addCounterpartyRecipient(string $city_ref, array $Recipient): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "Counterparty",
            'calledMethod' => "save",
            'methodProperties' => [
                'CityRef' => $city_ref,
                'FirstName' => $Recipient['FirstName'],
                'MiddleName' => $Recipient['MiddleName'],
                'LastName' => $Recipient['LastName'],
                'Phone' => $Recipient['Phone'],
                'Email' => "",
                'CounterpartyType' => "PrivatePerson",
                'CounterpartyProperty' => 'Recipient'
            ]
        ];

        return $this->send($query);
    }

    public function addAddressCounterparty(string $CounterpartyRef, array $Counterparty): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "Address",
            'calledMethod' => "save",
            'methodProperties' => [
                'CounterpartyRef' => $CounterpartyRef,
                'FindByString' => $Counterparty['FindByString'] ?? null,
                'StreetRef' => $Counterparty['StreetRef'] ?? null,
                'BuildingNumber' => $Counterparty['BuildingNumber'],
                'Flat' => $Counterparty['Flat']
            ]
        ];

        return $this->send($query);
    }

    public function getCounterpartyAddresses(string $Ref): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "Counterparty",
            'calledMethod' => "getCounterpartyAddresses",
            'methodProperties' => [
                'CounterpartyProperty' => "Sender",
                'Ref' => $Ref
            ]
        ];

        return $this->send($query);
    }

    public function deleteInternetDocument(array $document_ref): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "InternetDocument",
            'calledMethod' => "delete",
            'methodProperties' => [
                'DocumentRefs' => $document_ref
            ]
        ];

        return $this->send($query);
    }

    public function getStatus(array $document_number, string $phone = ''): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "TrackingDocument",
            'calledMethod' => "getStatusDocuments",
            'methodProperties' => [
                'Documents' => $document_number
            ]
        ];

        return $this->send($query);
    }

    public function TrackingDocument(array $DocumentNumber): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "TrackingDocument",
            'calledMethod' => "getStatusDocuments",
            'methodProperties' => [
                'Documents' => $DocumentNumber
            ]
        ];

        return $this->send($query);
    }

    public function getRegistry(): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "ScanSheet",
            'calledMethod' => "getScanSheetList"
        ];

        return $this->send($query);
    }
    public function getMarkingZebra(array $trackingNumbers)
    { 
        $orders = implode(',', $trackingNumbers);

        $url = "https://my.novaposhta.ua/orders/printMarking100x100/orders/{$orders}/type/pdf/zebra/zebra/apiKey/{$this->api}";

        try {
            $response = $this->client->get($url, ['stream' => true]);
        
            if ($response->getStatusCode() === 200) {
                return $response->getBody()->getContents();
            } else {
                return null;
            }
        } catch (RequestException $e) {
            // Логирование ошибок или другое обработка ошибок
            return null;
        }
    }
    public function addRegistry(array $document_ref, ?string $registry_ref = null): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "ScanSheet",
            'calledMethod' => "insertDocuments",
            'methodProperties' => [
                'DocumentRefs' => $document_ref,
                'Ref' => $registry_ref
            ]
        ];

        return $this->send($query);
    }

    public function deleteRegistry(array $document_ref, string $Ref): object
    {
        $query = [
            'apiKey' => $api,
            'modelName' => "ScanSheet",
            'calledMethod' => "removeDocuments",
            'methodProperties' => [
                'DocumentRefs' => $document_ref,
                'Ref' => $Ref
            ]
        ];

        return $this->send($query);
    }

    public function getPackListSpecial(): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "Common",
            'calledMethod' => "getPackListSpecial",
            'methodProperties' => [
                'Length' => 10,
                'Width' => 10,
                'Height' => 190,
                'PackForSale' => 1
            ]
        ];

        return $this->send($query);
    }

    public function getDocumentList(string $DateTimeFrom, string $DateTimeTo, bool $RedeliveryMoney = false, bool $UnassembledCargo = false): object
    {
        $query = [
            'apiKey' => $this->api,
            'modelName' => "InternetDocument",
            'calledMethod' => "getDocumentList",
            'methodProperties' => [
                'DateTimeFrom' => $DateTimeFrom,
                'DateTimeTo' => $DateTimeTo,
                'RedeliveryMoney' => $RedeliveryMoney,
                'UnassembledCargo' => $UnassembledCargo,
                'GetFullList' => 1
            ]
        ];

        return $this->send($query);
    }

    public function addTtn( $data)
    {
      
        $query = [
            'apiKey' => $this->api,
            'modelName' => "InternetDocument",
            'calledMethod' => "save",
            'methodProperties' => [
                'NewAddress' => '1',
                'PayerType' => 'Recipient',
                'PaymentMethod' => 'Cash',
                'CargoType' => "Parcel",
                'SeatsAmount' => count($data['details']),
                'Weight' => array_reduce($data['details'], fn ($carry, $item) => $carry + $item['weight'], 0),
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
                'RecipientName' => $data['recipientName'],
                'RecipientsPhone' => $data['recipientPhone']
            ]
        ];

        if ($data['EDRPOU']) {
            $query['methodProperties']['EDRPOU'] = $data['EDRPOU'];
        }
      
        if ($data['serviceType'] === 'WarehouseWarehouse') {
            $r = $this->getSettlements($data['recipientCityName'])->first();
         
            $query['methodProperties']['RecipientCityName'] = $r->mainDescription;
      
            $query['methodProperties']['RecipientArea'] = $r->area;
            $query['methodProperties']['RecipientAreaRegions'] = $r->region;
            $query['methodProperties']['RecipientAddressName'] = $data['recipientAddress'];
        }
     
        if ($data['serviceType'] === 'WarehouseDoors') {
            $CityRecipient = $this->getCity($data['city'])->data[0];

            $Recipient = $this->addCounterpartyRecipient($CityRecipient->Ref, [
                'FirstName' => $data['name'],
                'MiddleName' => $data['middlename'],
                'LastName' => $data['lastname'],
                'Phone' => $data['phone']
            ])->data[0];

            $StreetRef = $this->getStreet($CityRecipient->Ref, $data['address'])->data[0]->Ref;

            $CounterpartyAddressRef = $this->addAddressCounterparty($Recipient->Ref, [
                'StreetRef' => $StreetRef,
                'BuildingNumber' => $data['building_number'],
                'Flat' => $data['flat']
            ])->data[0]->Ref;

            $query['methodProperties']['CityRecipient'] = $CityRecipient->Ref;
            $query['methodProperties']['Recipient'] = $Recipient->Ref;
            $query['methodProperties']['RecipientAddress'] = $CounterpartyAddressRef;
            $query['methodProperties']['RecipientAddressName'] = $data['address'];
            $query['methodProperties']['ContactRecipient'] = $Recipient->ContactPerson->data[0]->Ref;
        }

        if ($data['paid'] === 0) {
            if ($data['EDRPOU']) {
                $query['methodProperties']['AfterpaymentOnGoodsCost'] = (int)$data['totalPrice'];
            } else {
                $query['methodProperties']['BackwardDeliveryData'][0] = [
                    'PayerType' => "Recipient",
                    'CargoType' => "Money",
                    'RedeliveryString' => (int)$data['totalPrice']
                ];
            }
        }

        $result = $this->send($query);

        if (isset($result->success) && !$result->success) {
            return [
                'success' => false,
                'errors' => $result,
            ];
        }
      
        return [
            'success' => true,
            'intDocNumber' => $result[0]->IntDocNumber,
            'intDocNumberRef' => $result[0]->Ref,
            'costOnSite' => $result[0]->CostOnSite,
        ];
    }
}
