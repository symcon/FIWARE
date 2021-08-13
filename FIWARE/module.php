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
        $this->RegisterPropertyString('ActionLights', '[]');
        $this->RegisterPropertyString('ActionShutters', '[]');
        $this->RegisterPropertyString('ActionDoors', '[]');
        $this->RegisterPropertyString('ActionWindows', '[]');

        //Server Properties
        $this->RegisterPropertyString('HostContextBroker', '');
        $this->RegisterPropertyString('HostWebSocket', '');
        $this->RegisterPropertyString('AuthToken', '');

        //Server Storage Properties
        $this->RegisterPropertyString('StorageUsername', '');
        $this->RegisterPropertyString('StoragePassword', '');
        $this->RegisterPropertyString('StorageBucket', '');
        $this->RegisterPropertyString('StorageEndpoint', '');

        //Google Maps Elevation Properties
        $this->RegisterPropertyString('GoogleMapsApiKey', '');

        //Building Properties
        $this->RegisterPropertyString('BuildingID', '');
        $this->RegisterPropertyString('BuildingOwnerEMail', '');
        $this->RegisterPropertyString('BuildingOwnerName', '');
        $this->RegisterPropertyString('BuildingOwnerSurname', '');
        $this->RegisterPropertyString('BuildingLocation', '');
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

        //Create WebSocket Event channel
        $this->RequireParent('{D68FD31F-0E90-7019-F16C-1949BD3079EF}');

        //Register Allow/Deny profile
        if (!IPS_VariableProfileExists('FW.AllowDeny')) {
            IPS_CreateVariableProfile('FW.AllowDeny', 0);
            IPS_SetVariableProfileAssociation('FW.AllowDeny', 0, 'Deny', '', 0xFF0000);
            IPS_SetVariableProfileAssociation('FW.AllowDeny', 1, 'Allow', '', 0x00FF00);
        }
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

        //Update the building details
        if ($this->ReadPropertyString('BuildingID')) {
            $this->UpdateBuilding();
        }

        //Update Building Elevation using Google Maps
        //$this->UpdateBuildingElevation();

        //Delete all registrations in order to read them
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
        $variableIDs = json_decode($this->ReadPropertyString('ActionLights'), true);
        foreach ($variableIDs as $variable) {
            $this->RegisterMessage($variable['VariableID'], VM_UPDATE);
        }
        $variableIDs = json_decode($this->ReadPropertyString('ActionShutters'), true);
        foreach ($variableIDs as $variable) {
            $this->RegisterMessage($variable['VariableID'], VM_UPDATE);
        }
        $variableIDs = json_decode($this->ReadPropertyString('ActionDoors'), true);
        foreach ($variableIDs as $variable) {
            $this->RegisterMessage($variable['VariableID'], VM_UPDATE);
        }
        $variableIDs = json_decode($this->ReadPropertyString('ActionWindows'), true);
        foreach ($variableIDs as $variable) {
            $this->RegisterMessage($variable['VariableID'], VM_UPDATE);
        }

        //Register media messages
        $mediaIDs = json_decode($this->ReadPropertyString('WatchMedia'), true);
        foreach ($mediaIDs as $media) {
            $this->RegisterMessage($media['MediaID'], MM_UPDATE);
        }
    }

    public function GetConfigurationForParent()
    {
        $url = 'wss://echo.websocket.org';
        if ($this->ReadPropertyString('HostWebSocket')) {
            $url = $this->ReadPropertyString('HostWebSocket') . '/?type=smartHome&componentId=' . $this->ReadPropertyString('AuthToken');
        }

        return json_encode([
            'URL'               => $url,
            'VerifyCertificate' => true,
        ]);
    }

    public function GetConfigurationForm()
    {
        $data = json_decode(file_get_contents(__DIR__ . '/form.json'));

        if (!$this->ReadPropertyString('HostContextBroker')) {
            foreach ($data->elements as $element) {
                $element->visible = false;
            }
            foreach ($data->actions as $action) {
                $action->visible = false;
            }
            $data->actions[0]->visible = true;
            $data->actions[1]->visible = true;
            $data->actions[2]->visible = true;
        }

        $elevation = $this->ReadAttributeFloat('BuildingElevation');

        if ($elevation == 0) {
            $elevation = $this->Translate('Unknown');
        } else {
            $elevation = number_format($elevation, 2, ',', '') . 'm';
        }

        $data->elements[1]->items[7]->caption = sprintf($this->Translate('Elevation: %s'), $elevation);

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

        $data->actions[5]->items[0]->values = $accessPrivilege;

        return json_encode($data);
    }

    public function MessageSink($Timestamp, $SenderID, $MessageID, $Data)
    {
        switch ($MessageID) {
            case VM_UPDATE:
            {
                $this->SendDebug('Collecting', 'Variable: ' . $SenderID . ', Value: ' . $Data[0] . ', Observed: ' . date('d.m.Y H:i:s', $Data[3]), 0);

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
        $url = $this->ReadPropertyString('HostContextBroker') . '/v2/op/update';
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
                'content'       => $json,
                'ignore_errors' => true,
            ]
        ];

        $context = stream_context_create($options);

        $result = file_get_contents($url, false, $context);
        if ($result) {
            $this->SendDebug('RESPONSE', $result, 0);
        }
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

                    $variable = $this->GetVariableData($variableID);
                    switch ($variable['Category']) {
                        case 'actuator':
                            $this->SendDebug('Sending', 'Actuator: ' . $variableID . ', Value: ' . $data[0] . ', Observed: ' . date('d.m.Y H:i:s', $data[3]), 0);
                            $entities[] = $this->BuildActuatorEntity($variableID, $data);
                            break;
                        default:
                            $this->SendDebug('Sending', 'Sensor: ' . $variableID . ', Value: ' . $data[0] . ', Observed: ' . date('d.m.Y H:i:s', $data[3]), 0);
                            $entities[] = $this->BuildSensorEntity($variableID, $data);
                            break;
                    }
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
                    'endpoint' => $this->ReadPropertyString('StorageEndpoint')
                ]);
                $client->registerStreamWrapper();

                $sendMediaArray = json_decode($sendMedia, true);
                foreach ($sendMediaArray as $update) {
                    $mediaID = $update[0];
                    $data = $update[1];

                    $this->SendDebug('Sending', 'Image: ' . $mediaID . ', Observed: ' . date('d.m.Y H:i:s', $data[2]), 0);

                    file_put_contents('s3://storage/' . $this->ReadPropertyString('StorageBucket') . '/' . $this->ReadPropertyString('BuildingID') . '/' . $mediaID . '/' . date('Ymd_His', $data[2]) . '.jpg', base64_decode(IPS_GetMediaContent($mediaID)));

                    $url = 'https://storage.inspireprojekt.de/' . $this->ReadPropertyString('StorageBucket') . '/' . $this->ReadPropertyString('BuildingID') . '/' . $mediaID . '/' . date('Ymd_His', $data[2]) . '.jpg';

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

        file_put_contents('s3://storage/smarthome/' . $this->ReadPropertyString('BuildingID') . '/plan.pdf', $data);

        return 'https://storage.inspireprojekt.de/smarthome/' . $this->ReadPropertyString('BuildingID') . '/plan.pdf';
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

    public function Register()
    {
        $this->UpdateFormField('RegisterBuilding', 'visible', true);
    }

    public function RegisterBuilding(string $HubURL, string $BuildingOwnerEMail, string $BuildingOwnerName, string $BuildingOwnerSurname, string $BuildingStreet, string $BuildingPostcode, string $BuildingCity, string $BuildingLocation, string $BuildingPlan, bool $AcceptEULA, bool $AcceptDataProtection)
    {
        if (!$AcceptEULA) {
            echo 'Sie müssen den Allgemeinen Geschäftsbedingungen zustimmen!';
            return;
        }
        if (!$AcceptDataProtection) {
            echo 'Sie müssen den Datenschutzbedingungen zustimmen!';
            return;
        }

        $location = json_decode($BuildingLocation, true);
        $json = json_encode([
            'name'        => $BuildingOwnerName . ' ' . $BuildingOwnerSurname,
            'email'       => $BuildingOwnerEMail,
            'description' => IPS_GetName(0),
            'address'     => [
                'value' => [
                    'addressLocality' => $BuildingCity,
                    'postalCode'      => $BuildingPostcode,
                    'streetAddress'   => $BuildingStreet
                ]
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
        ]);
        $this->SendDebug('Register', $json, 0);

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" .
                            'Content-Length:' . strlen($json) . "\r\n",
                'content' => $json
            ]
        ];

        $context = stream_context_create($options);

        $content = file_get_contents($HubURL, false, $context);

        $data = json_decode($content);

        $this->SendDebug('RegisterResult', $content, 0);

        $this->UpdateFormField('ServerSettings', 'visible', true);
        $this->UpdateFormField('ServerSettings', 'expanded', true);

        $this->UpdateFormField('BuildingSettings', 'visible', true);
        $this->UpdateFormField('BuildingSettings', 'expanded', true);

        $this->UpdateFormField('HostContextBroker', 'value', $data->contextBrokerUrl);
        $this->UpdateFormField('HostWebSocket', 'value', $data->websocketUrl);
        $this->UpdateFormField('AuthToken', 'value', $data->authToken);
        $this->UpdateFormField('StorageUsername', 'value', $data->storage->username);
        $this->UpdateFormField('StoragePassword', 'value', $data->storage->password);
        $this->UpdateFormField('StorageBucket', 'value', $data->storage->bucket);
        $this->UpdateFormField('StorageEndpoint', 'value', $data->storage->url);

        $this->UpdateFormField('BuildingID', 'value', str_replace('urn:ngsi-ld:Building:', '', $data->buildingId));
        $this->UpdateFormField('BuildingOwnerEMail', 'value', $BuildingOwnerEMail);
        $this->UpdateFormField('BuildingOwnerName', 'value', $BuildingOwnerName);
        $this->UpdateFormField('BuildingOwnerSurname', 'value', $BuildingOwnerSurname);
        $this->UpdateFormField('BuildingStreet', 'value', $BuildingStreet);
        $this->UpdateFormField('BuildingPostcode', 'value', $BuildingPostcode);
        $this->UpdateFormField('BuildingCity', 'value', $BuildingCity);
        $this->UpdateFormField('BuildingLocation', 'value', $BuildingLocation);
        $this->UpdateFormField('BuildingPlan', 'value', $BuildingPlan);

        $this->UpdateFormField('RegisterBuilding', 'visible', false);
        $this->UpdateFormField('RegisterBuildingPermissions', 'visible', true);
    }

    public function RegisterBuildingPermissions(string $Permissions)
    {
        switch ($Permissions) {
            case 'allowed':
                $this->AddAccessPrivilege('Feuerwehr', '*', 2147483647);
                break;
            case 'request-required':
                $this->AddAccessPrivilege('Feuerwehr', '', 2147483647);
                break;
            case 'custom':
                // Open a new dialog for granular permission control
                break;
        }

        $this->UpdateFormField('RegisterBuildingPermissions', 'visible', false);
        $this->UpdateFormField('RegisterBuildingComplete', 'visible', true);
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->SendDebug('ReceiveData', utf8_decode($data->Buffer), 0);
        $json = json_decode($data->Buffer, true);
        $events = $json['data'];

        foreach ($events as $event) {
            $id = explode(':', $event['id']);
            $id = intval(str_replace('Sensor', '', str_replace('Actuator', '', $id[3])));

            //Receive only switch request for our building
            if ($event['attachedTo']['attachedToId'] == 'urn:ngsi-ld:Building:' . $this->ReadPropertyString('BuildingID')) {
                if (isset($event['action']['desiredValue'])) {
                    $accessPrivilege = json_decode($this->ReadAttributeString('AccessPrivileges'), true);
                    $status = 'DENIED';
                    foreach ($accessPrivilege as $privilege) {
                        if ($privilege['ValidUntil'] > time()) {
                            if ($privilege['Scope'] == '*') {
                                $status = 'ALLOWED';
                            } elseif ($status != 'ALLOWED') {
                                $status = 'REQUEST';
                            }
                        }
                    }
                    switch ($status) {
                        case 'ALLOWED':
                            $status = $this->RequestDesiredValue($id, $event['action']['desiredValue']);
                            $status = ($status === false) ? 'ERROR' : 'SUCCESS';
                            break;
                        case 'REQUEST':
                            $this->NotifyForAction($id, $event);
                            $status = 'PENDING';
                            break;
                        default:
                            // DENIED
                            break;
                    }
                    $this->SendConfirmation($event, $status);
                }
            }

            //Receive all alerts
            if (isset($event['info']['description'])) {
                $this->RaiseAlarm($event['info']['description']);
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {

        //Search Ident inside our pending state
        $pendingActions = json_decode($this->GetBuffer('PendingActions'), true);
        foreach ($pendingActions as $key => $value) {
            if ($value['ident'] == $Ident) {
                if ($Value) {
                    $status = $this->RequestDesiredValue($value['id'], $value['event']['action']['desiredValue']);
                    $status = ($status === false) ? 'ERROR' : 'SUCCESS';
                } else {
                    $status = 'DENIED';
                }
                $this->SendConfirmation($value['event'], $status);
                $this->UnregisterVariable($Ident);
                unset($pendingActions[$key]);
                $this->SetBuffer('PendingActions', json_encode($pendingActions));
                return;
            }
        }

        throw new Exception('Invalid Ident');
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
                    'attachedToId'   => 'urn:ngsi-ld:Building:' . $this->ReadPropertyString('BuildingID')
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
        $variables = json_decode($this->ReadPropertyString('ActionLights'), true);
        foreach ($variables as $variable) {
            if ($variable['VariableID'] == $VariableID) {
                $variable['Category'] = 'actuator';
                $variable['ControlledProperty'] = 'light';
                return $variable;
            }
        }
        $variables = json_decode($this->ReadPropertyString('ActionShutters'), true);
        foreach ($variables as $variable) {
            if ($variable['VariableID'] == $VariableID) {
                $variable['Category'] = 'actuator';
                $variable['ControlledProperty'] = 'shutter';
                return $variable;
            }
        }
        $variables = json_decode($this->ReadPropertyString('ActionDoors'), true);
        foreach ($variables as $variable) {
            if ($variable['VariableID'] == $VariableID) {
                $variable['Category'] = 'actuator';
                $variable['ControlledProperty'] = 'door';
                return $variable;
            }
        }
        $variables = json_decode($this->ReadPropertyString('ActionWindows'), true);
        foreach ($variables as $variable) {
            if ($variable['VariableID'] == $VariableID) {
                $variable['Category'] = 'actuator';
                $variable['ControlledProperty'] = 'window';
                return $variable;
            }
        }
        return null;
    }

    private function BuildSensorEntity($VariableID, $Data)
    {
        $variable = $this->GetVariableData($VariableID);
        $location = json_decode($variable['Location'], true);
        $category = $variable['Category'];
        $controlledProperty = $variable['ControlledProperty'];
        $value = $Data[0];

        // Map boolean values to 0/1 to better support thresholds
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

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
                'value' => $value,
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

    private function BuildActuatorEntity($VariableID, $Data)
    {
        $variable = $this->GetVariableData($VariableID);
        $location = json_decode($variable['Location'], true);
        $category = $variable['Category'];
        $controlledProperty = $variable['ControlledProperty'];

        // Map boolean values for door/window to string representation
        switch ($controlledProperty) {
            case 'light':
            case 'shutter':
                if (is_bool($Data[0])) {
                    $Data[0] = $Data[0] ? 100 : 0;
                } else {
                    // FIXME: We might need to convert to percentage value
                }
                break;
            case 'door':
            case 'window':
                $Data[0] = $Data[0] ? 'OPEN' : 'CLOSED';
                break;
        }

        return array_merge($this->BuildEntity($VariableID, 'Actuator', $Data[4], $location), [
            'category' => [
                'value' => [
                    $category
                ]
            ],
            'value' => [
                'value' => $Data[0]
            ],
            'controlledProperty' => [
                'value' => [
                    $controlledProperty
                ]
            ],
            'configuration' => [
                'type'  => 'Symcon',
                'value' => [
                    'profile' => $this->GetVariableProfile($VariableID)
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

    private function UpdateBuilding()
    {
        $location = json_decode($this->ReadPropertyString('BuildingLocation'), true);

        $planUrl = $this->UploadBuildingPlan();

        $entity = [
            'id'       => 'urn:ngsi-ld:Building:' . $this->ReadPropertyString('BuildingID'),
            'type'     => 'Building',
            'category' => [
                'value' => [
                    'house'
                ]
            ],
            'name' => [
                'value' => $this->ReadPropertyString('BuildingOwnerName') . ' ' . $this->ReadPropertyString('BuildingOwnerSurname'),
            ],
            'email' => [
                'value' => $this->ReadPropertyString('BuildingOwnerEMail'),
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
            'floorPlan' => [
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

    private function RequestDesiredValue($ID, $Value)
    {
        if ($Value === 'OPEN') {
            $Value = true;
        } elseif ($Value === 'CLOSE') {
            $Value = false;
        }
        return RequestAction($ID, $Value);
    }

    private function SendConfirmation($event, $status)
    {
        $response = [
            'id'     => $event['id'],
            'type'   => $event['type'],
            'action' => [
                'type'  => 'StructuredValue',
                'value' => [
                    'status' => $status
                ]
            ]
        ];
        $this->SendData([$response]);
    }

    private function GenerateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function NotifyForAction($ID, $Event)
    {
        $this->SendDebug('PENDING', 'Waiting for confirmation... ' . sprintf("'%s' -> '%s'", IPS_GetName($ID), GetValueFormattedEx($ID, $Event['action']['desiredValue'])), 0);

        $action = [
            'ident' => $this->GenerateRandomString(),
            'id'    => $ID,
            'event' => $Event,
        ];

        // Add to pending actions state
        $pendingActions = json_decode($this->GetBuffer('PendingActions'), true);
        $pendingActions[] = $action;
        $this->SetBuffer('PendingActions', json_encode($pendingActions));

        // Add variable
        $this->RegisterVariableBoolean($action['ident'], sprintf("Erlaube Schalten von '%s' auf '%s'", IPS_GetName($ID), GetValueFormattedEx($ID, $Event['action']['desiredValue'])), 'FW.AllowDeny');
        $this->SetValue($action['ident'], true);
        $this->EnableAction($action['ident']);

        // Notify everyone
        $wfcids = IPS_GetInstanceListByModuleID('{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}');
        foreach ($wfcids as $id) {
            if (@WFC_PushNotification($id, 'Alarm', sprintf("Die Feuerwehr Paderborn möchte im Notfall Geräte '%s' auf '%s' schalten. Bitte bestätigen!", IPS_GetName($ID), GetValueFormattedEx($ID, $Event['action']['desiredValue'])), '', $this->InstanceID) === false) {
                $this->SendDebug('PNS', 'Could not send Push-Notification!', 0);
            }
        }
    }

    private function RaiseAlarm($description)
    {
        //ToDo
    }
}