<?php

declare(strict_types=1);
class FIWARE extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Properties
        $this->RegisterPropertyString('WatchVariables', '[]');
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('AuthToken', '');

        //Timer
        $this->RegisterTimer('SendDataTimer', 0, 'FW_SendMessageData($_IPS[\'TARGET\']);');
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

        $variableIDs = json_decode($this->ReadPropertyString('WatchVariables'), true);
        foreach ($variableIDs as $variable) {
            $this->RegisterMessage($variable['VariableID'], VM_UPDATE);
        }
    }

    public function MessageSink($Timestamp, $SenderID, $MessageID, $Data)
    {
        $this->SendDebug('Collecting', 'Sensor: ' . $SenderID . ', Value: ' . $Data[0] . ', Observed: ' . date('d.m.Y H:i:s', $Data[3]), 0);

        if (IPS_SemaphoreEnter('SendVariablesSemaphore', 500)) {
            $sendVariablesString = $this->GetBuffer('SendVariables');
            $sendVariables = ($sendVariablesString == '') ? [] : json_decode($sendVariablesString, true);
            $sendVariables[] = [$SenderID, $Data];
            $this->SetBuffer('SendVariables', json_encode($sendVariables));
            IPS_SemaphoreLeave('SendVariablesSemaphore');
            if ($this->GetTimerInterval('SendDataTimer') == 0) {
                $this->SetTimerInterval('SendDataTimer', 500);
            }
        }
    }

    public function SendMessageData()
    {
        if (IPS_SemaphoreEnter('SendVariablesSemaphore', 0)) {
            $this->SetTimerInterval('SendDataTimer', 0);
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
                    $entities[] = $this->BuildEntity($variableID, $data);
                }
                $this->SendData($entities);
            }
        }
    }

    public function SendData($Entities)
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
                'method'  => 'POST',
                'header'  => "X-Auth-Token: $token\r\n" .
                             "Content-Type: application/json\r\n" .
                             'Content-Length:' . strlen($json) . "\r\n",
                'content' => $json
            ]
        ];

        $context = stream_context_create($options);

        file_get_contents($url, false, $context);
    }

    private function BuildEntity($VariableID, $Data)
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

        return [
            'id'       => 'urn:ngsi-ld:Device:Sensor' . $VariableID,
            'type'     => 'Device',
            'category' => [
                'value' => [
                    $category
                ]
            ],
            'value' => [
                'value' => $Data[0],
            ],
            'dateLastValueReported' => [
                'type'     => 'DateTime',
                'value'    => date("Y-m-d\TH:i:sO", $Data[4]),
                'metadata' => new stdClass()
            ],
            'controlledProperty' => [
                'value' => [
                    $controlledProperty
                ]
            ],
            'name' => [
                'value' => IPS_GetName($VariableID)
            ],
            'description' => [
                'value' => IPS_GetObject($VariableID)['ObjectInfo']
            ],
            'source' => [
                'value' => 'IP-Symcon LoRa Gateway'
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
            ],
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
        return null;
    }
}
