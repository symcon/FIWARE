<?php

declare(strict_types=1);

require __DIR__ . '/../libs/vendor/autoload.php';

class FIWARE extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Watch Properties
        $this->RegisterPropertyString('WatchVariables', '[]');
        $this->RegisterPropertyString('WatchMedia', '[]');

        //Action Properties
        $this->RegisterPropertyString('ActionDoors', '[]');
        $this->RegisterPropertyString('ActionWindows', '[]');
        $this->RegisterPropertyString('ActionLights', '[]');

        //Server Properties
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('AuthToken', '');

        //Server Storage Properties
        $this->RegisterPropertyString('StorageUsername', '');
        $this->RegisterPropertyString('StoragePassword', '');

        //Google Maps Elevation Properties
        $this->RegisterPropertyString('GoogleMapsApiKey', '');

        //Building Properties
        $this->RegisterPropertyString('BuildingLocation', '{"latitude": 0, "longitude": 0}');
        $this->RegisterPropertyString('BuildingStreet', '');
        $this->RegisterPropertyString('BuildingPostcode', '');
        $this->RegisterPropertyString('BuildingCity', '');
        $this->RegisterPropertyString('BuildingPlan', '');

        //Timer
        $this->RegisterTimer('SendVariablesTimer', 0, 'FW_SendVariableData($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SendMediaTimer', 0, 'FW_SendMediaData($_IPS[\'TARGET\']);');

        //Cache Elevation value for further use
        $this->RegisterAttributeFloat('BuildingElevation', 0);

        //Access Privileges
        $this->RegisterAttributeString('AccessPrivileges', '[]');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        //Register the building
        $this->RegisterBuilding();

        //Update Building Elevation using Google Maps
        $this->UpdateBuildingElevation();

        //Delete all registrations in order to readd them
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        //Register variable messages
        $variableIDs = json_decode($this->ReadPropertyString('WatchVariables'), true);
        foreach ($variableIDs as $variable) {
            $this->RegisterMessage($variable['VariableID'], VM_UPDATE);
        }

        //Register media messages
        $mediaIDs = json_decode($this->ReadPropertyString('WatchMedia'), true);
        foreach ($mediaIDs as $media) {
            $this->RegisterMessage($media['MediaID'], MM_UPDATE);
        }
    }

    public function GetConfigurationForm()
    {
        $data = json_decode(file_get_contents(__DIR__ . '/form.json'));

        $elevation = $this->ReadAttributeFloat('BuildingElevation');

        if ($elevation == 0) {
            $elevation = $this->Translate('Unknown');
        } else {
            $elevation = number_format($elevation, 2, ',', '') . 'm';
        }

        $data->actions[1]->caption = sprintf($this->Translate('Elevation: %s'), $elevation);

        $accessPrivilege = json_decode($this->ReadAttributeString('AccessPrivileges'), true);
        foreach ($accessPrivilege as $key => $value) {
            $accessPrivilege[$key]['ValidUntil'] = json_encode([
                'hour'   => intval(date('H', $value['ValidUntil'])),
                'minute' => intval(date('i', $value['ValidUntil'])),
                'second' => intval(date('s', $value['ValidUntil'])),
                'day'    => intval(date('d', $value['ValidUntil'])),
                'month'  => intval(date('m', $value['ValidUntil'])),
                'year'   => intval(date('Y', $value['ValidUntil']))
            ]);
        }

        $data->actions[0]->items[0]->values = $accessPrivilege;

        return json_encode($data);
    }

    public function MessageSink($Timestamp, $SenderID, $MessageID, $Data)
    {
        switch ($MessageID) {
            case VM_UPDATE:
            {
                $this->SendDebug('Collecting', 'Sensor: ' . $SenderID . ', Value: ' . $Data[0] . ', Observed: ' . date('d.m.Y H:i:s', $Data[3]), 0);

                if (IPS_SemaphoreEnter('SendVariablesSemaphore', 500)) {
                    $sendVariablesString = $this->GetBuffer('SendVariables');
                    $sendVariables = ($sendVariablesString == '') ? [] : json_decode($sendVariablesString, true);
                    $sendVariables[] = [$SenderID, $Data];
                    $this->SetBuffer('SendVariables', json_encode($sendVariables));
                    IPS_SemaphoreLeave('SendVariablesSemaphore');
                    if ($this->GetTimerInterval('SendVariablesTimer') == 0) {
                        $this->SetTimerInterval('SendVariablesTimer', 500);
                    }
                }
                break;
            }
            case MM_UPDATE:
            {
                $this->SendDebug('Collecting', 'Image: ' . $SenderID . ', Observed: ' . date('d.m.Y H:i:s', $Data[2]), 0);

                if (IPS_SemaphoreEnter('SendMediaSemaphore', 500)) {
                    $sendMediaString = $this->GetBuffer('SendMedia');
                    $sendMedia = ($sendMediaString == '') ? [] : json_decode($sendMediaString, true);
                    $sendMedia[] = [$SenderID, $Data];
                    $this->SetBuffer('SendMedia', json_encode($sendMedia));
                    IPS_SemaphoreLeave('SendMediaSemaphore');
                    if ($this->GetTimerInterval('SendMediaTimer') == 0) {
                        $this->SetTimerInterval('SendMediaTimer', 500);
                    }
                }
                break;
            }
        }
    }

    public function SendData(array $Entities)
    {
        $url = $this->ReadPropertyString('Host') . '/v2/op/update';
        $token = $this->ReadPropertyString('AuthToken');

        $data = [
            'actionType' => 'append',
            'entities'   => $Entities
        ];

        $json = json_encode($data);
        $this->SendDebug('Data', $json, 0);

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "X-Auth-Token: $token\r\n" .
                    "Content-Type: application/json\r\n" .
                    'Content-Length:' . strlen($json) . "\r\n",
                'content' => $json
            ]
        ];

        $context = stream_context_create($options);

        file_get_contents($url, false, $context);
    }

    public function SendVariableData()
    {
        if (IPS_SemaphoreEnter('SendVariablesSemaphore', 0)) {
            $this->SetTimerInterval('SendVariablesTimer', 0);
            $sendVariables = $this->GetBuffer('SendVariables');
            $this->SetBuffer('SendVariables', '');
            IPS_SemaphoreLeave('SendVariablesSemaphore');
            if ($sendVariables != '') {
                $sendVariablesArray = json_decode($sendVariables, true);
                $entities = [];
                foreach ($sendVariablesArray as $update) {
                    $variableID = $update[0];
                    $data = $update[1];

                    $this->SendDebug('Sending', 'Sensor: ' . $variableID . ', Value: ' . $data[0] . ', Observed: ' . date('d.m.Y H:i:s', $data[3]), 0);
                    $entities[] = $this->BuildVariableEntity($variableID, $data);
                }
                $this->SendData($entities);
            }
        }
    }

    public function SendMediaData()
    {
        if (IPS_SemaphoreEnter('SendMediaSemaphore', 0)) {
            $this->SetTimerInterval('SendMediaTimer', 0);
            $sendMedia = $this->GetBuffer('SendMedia');
            $this->SetBuffer('SendMedia', '');
            IPS_SemaphoreLeave('SendMediaSemaphore');
            if ($sendMedia != '') {
                $client = new Aws\S3\S3Client([
                    'version'     => 'latest',
                    'region'      => 'eu-west-1',
                    'credentials' => [
                        'key'    => $this->ReadPropertyString('StorageUsername'),
                        'secret' => $this->ReadPropertyString('StoragePassword'),
                    ],
                    'endpoint' => 'https://inspireprojekt.de'
                ]);
                $client->registerStreamWrapper();

                $sendMediaArray = json_decode($sendMedia, true);
                foreach ($sendMediaArray as $update) {
                    $mediaID = $update[0];
                    $data = $update[1];

                    $this->SendDebug('Sending', 'Image: ' . $mediaID . ', Observed: ' . date('d.m.Y H:i:s', $data[2]), 0);

                    file_put_contents('s3://storage/smarthome/' . $this->GetBuildingID() . '/' . $mediaID . '/' . date('Ymd_His', $data[2]) . '.jpg', base64_decode(IPS_GetMediaContent($mediaID)));

                    $url = 'https://storage.inspireprojekt.de/smarthome/' . $this->GetBuildingID() . '/' . $mediaID . '/' . date('Ymd_His', $data[2]) . '.jpg';

                    $this->SendData([$this->BuildMediaEntity($mediaID, $url)]);
                }
            }
        }
    }

    public function UploadBuildingPlan()
    {
        $client = new Aws\S3\S3Client([
            'version'     => 'latest',
            'region'      => 'eu-west-1',
            'credentials' => [
                'key'    => $this->ReadPropertyString('StorageUsername'),
                'secret' => $this->ReadPropertyString('StoragePassword'),
            ],
            'endpoint' => 'https://inspireprojekt.de'
        ]);
        $client->registerStreamWrapper();

        $data = base64_decode($this->ReadPropertyString('BuildingPlan'));

        if (!$data) {
            return '';
        }

        $this->SendDebug('Sending', 'Plan: ' . strlen($data) / 1024 . ' kB', 0);

        file_put_contents('s3://storage/smarthome/' . $this->GetBuildingID() . '/plan.pdf', $data);

        return 'https://storage.inspireprojekt.de/smarthome/' . $this->GetBuildingID() . '/plan.pdf';
    }

    public function AddAccessPrivilege(string $Requester, string $Scope, int $ValidUntil)
    {
        $accessPrivilege = json_decode($this->ReadAttributeString('AccessPrivileges'), true);

        $accessPrivilege[] = [
            'Token'      => bin2hex(random_bytes(32)),
            'Requester'  => $Requester,
            'Scope'      => $Scope,
            'ValidUntil' => $ValidUntil
        ];

        $this->WriteAttributeString('AccessPrivileges', json_encode($accessPrivilege));
    }

    public function UpdateAccessPrivilege(string $Token, string $Requester, string $Scope, int $ValidUntil)
    {
        $accessPrivilege = json_decode($this->ReadAttributeString('AccessPrivileges'), true);

        foreach ($accessPrivilege as $key => $value) {
            if ($value['Token'] == $Token) {
                $accessPrivilege[$key]['Requester'] = $Requester;
                $accessPrivilege[$key]['Scope'] = $Scope;
                $accessPrivilege[$key]['ValidUntil'] = $ValidUntil;
            }
        }

        $this->WriteAttributeString('AccessPrivileges', json_encode($accessPrivilege));
    }

    public function DeleteAccessPrivilege(string $Token)
    {
        $accessPrivilege = json_decode($this->ReadAttributeString('AccessPrivileges'), true);

        foreach ($accessPrivilege as $key => $value) {
            if ($value['Token'] == $Token) {
                unset($accessPrivilege[$key]);
            }
        }

        $this->WriteAttributeString('AccessPrivileges', json_encode($accessPrivilege));
    }

    private function BuildEntity($ObjectID, $Type, $Time, $Location)
    {
        //Add fallback to building location if object location is not set
        if ($Location['longitude'] == 0 && $Location['latitude'] == 0) {
            $Location = json_decode($this->ReadPropertyString('BuildingLocation'), true);
        }

        return [
            'id'                    => 'urn:ngsi-ld:Device:' . $Type . $ObjectID,
            'type'                  => 'Device',
            'dateLastValueReported' => [
                'type'     => 'DateTime',
                'value'    => date("Y-m-d\TH:i:sO", $Time),
                'metadata' => new stdClass()
            ],
            'attachedTo' => [
                'value' => [
                    'attachedToType' => 'Building',
                    'attachedToId'   => 'urn:ngsi-ld:Building:' . $this->GetBuildingID()
                ]
            ],
            'name' => [
                'value' => IPS_GetName($ObjectID)
            ],
            'description' => [
                'value' => IPS_GetObject($ObjectID)['ObjectInfo']
            ],
            'source' => [
                'value' => 'IP-Symcon LoRa Gateway'
            ],
            'location' => [
                'type'  => 'geo:json',
                'value' => [
                    'type'        => 'Point',
                    'coordinates' => [
                        $Location['longitude'],
                        $Location['latitude']
                    ]
                ],
                'metadata' => new stdClass()
            ]
        ];
    }

    private function GetBuildingID()
    {
        $buildingID = md5('FIWARE' . IPS_GetLicensee());

        return substr($buildingID, 0, 8) . '-' .
            substr($buildingID, 8, 4) . '-' .
            substr($buildingID, 12, 4) . '-' .
            substr($buildingID, 16, 4) . '-' .
            substr($buildingID, 20, 12);
    }

    private function GetVariableProfile($VariableID)
    {
        $variable = IPS_GetVariable($VariableID);
        if ($variable['VariableCustomProfile'] != '') {
            return $variable['VariableCustomProfile'];
        }
        if ($variable['VariableProfile'] != '') {
            return $variable['VariableProfile'];
        }
        return '';
    }

    private function GetVariableData($VariableID)
    {
        $variables = json_decode($this->ReadPropertyString('WatchVariables'), true);
        foreach ($variables as $variable) {
            if ($variable['VariableID'] == $VariableID) {
                return $variable;
            }
        }
        return null;
    }

    private function BuildVariableEntity($VariableID, $Data)
    {
        $variable = $this->GetVariableData($VariableID);
        $location = json_decode($variable['Location'], true);
        $category = $variable['Category'];
        $controlledProperty = $variable['ControlledProperty'];

        $thresholds = function ($thresholds)
        {
            $result = [];
            foreach ($thresholds as $threshold) {
                $result[] = [
                    'comparison' => $threshold['Comparison'],
                    'value'      => $threshold['Value']
                ];
            }
            return $result;
        };

        return array_merge($this->BuildEntity($VariableID, 'Sensor', $Data[4], $location), [
            'category' => [
                'value' => [
                    $category
                ]
            ],
            'value' => [
                'value' => $Data[0],
            ],
            'controlledProperty' => [
                'value' => [
                    $controlledProperty
                ]
            ],
            'configuration' => [
                'type'  => 'Symcon',
                'value' => [
                    'profile'           => $this->GetVariableProfile($VariableID),
                    'reportingInterval' => 3600,
                    'thresholds'        => [
                        'invalid' => $thresholds($variable['ThresholdInvalid']),
                        'warning' => $thresholds($variable['ThresholdWarning']),
                        'alarm'   => $thresholds($variable['ThresholdAlarm'])
                    ]
                ],
            ]
        ]);
    }

    private function GetMediaData($MediaID)
    {
        $medias = json_decode($this->ReadPropertyString('WatchMedia'), true);
        foreach ($medias as $media) {
            if ($media['MediaID'] == $MediaID) {
                return $media;
            }
        }
        return null;
    }

    private function BuildMediaEntity($MediaID, $URL)
    {
        $media = $this->GetMediaData($MediaID);
        $location = json_decode($media['Location'], true);

        return array_merge($this->BuildEntity($MediaID, 'Media', time(), $location), [
            'category' => [
                'value' => [
                    'media'
                ]
            ],
            'value' => [
                'value' => [
                    'mediaType' => 'IMAGE',
                    'url'       => $URL
                ]
            ],
            'controlledProperty' => [
                'value' => [
                    'camera'
                ]
            ]
        ]);
    }

    private function RegisterBuilding()
    {
        $location = json_decode($this->ReadPropertyString('BuildingLocation'), true);

        $planUrl = $this->UploadBuildingPlan();

        $entity = [
            'id'       => 'urn:ngsi-ld:Building:' . $this->GetBuildingID(),
            'type'     => 'Building',
            'category' => [
                'value' => [
                    'house'
                ]
            ],
            'description' => [
                'value' => IPS_GetName(0)
            ],
            'source' => [
                'value' => CC_GetConnectURL(IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0])
            ],
            'location' => [
                'type'  => 'geo:json',
                'value' => [
                    'type'        => 'Point',
                    'coordinates' => [
                        $location['longitude'],
                        $location['latitude']
                    ]
                ],
                'metadata' => new stdClass()
            ],
            'address' => [
                'value' => [
                    'addressLocality' => $this->ReadPropertyString('BuildingCity'),
                    'postalCode'      => $this->ReadPropertyString('BuildingPostcode'),
                    'streetAddress'   => $this->ReadPropertyString('BuildingStreet')
                ]
            ],
            'plan' => [
                'value' => $planUrl
            ]
        ];

        $this->SendData([$entity]);
    }

    private function UpdateBuildingElevation()
    {
        $location = json_decode($this->ReadPropertyString('BuildingLocation'), true);

        $url = 'https://maps.googleapis.com/maps/api/elevation/json?locations=%s,%s&key=%s';

        $results = json_decode(file_get_contents(sprintf($url, number_format($location['latitude'], 7), number_format($location['longitude'], 7), $this->ReadPropertyString('GoogleMapsApiKey'))), true);

        $this->SendDebug('Elevation', json_encode($results), 0);

        if ($results['status'] == 'OK') {
            $this->WriteAttributeFloat('BuildingElevation', $results['results'][0]['elevation']);
        }
    }
}
