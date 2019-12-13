<?php
	class FIWARE extends IPSModule {

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
				$variableID = $variable['VariableID'];
				$this->RegisterMessage($variableID, VM_UPDATE);
			}

		}

		public function MessageSink($Timestamp, $SenderID, $MessageID, $Data)
		{
            $this->SendDebug("Collecting", "Sensor: " . $SenderID . ", Value: " . $Data[0] . ", Observed: " . date("d.m.Y H:i:s", $Data[3]), 0);

            if (IPS_SemaphoreEnter('SendVariablesSemaphore', 500)) {
				$sendVariablesString = $this->GetBuffer('SendVariables');
				$sendVariables = ($sendVariablesString == '') ? [] : json_decode($sendVariablesString, true);
				$sendVariables[] = [$SenderID, $Data];
				$this->SetBuffer('SendVariables', json_encode($sendVariables));
				IPS_SemaphoreLeave('SendVariablesSemaphore');
				$this->SetTimerInterval('SendDataTimer', 1000);
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
					foreach ($sendVariablesArray as $update) {
						$this->SendData($update[0], $update[1]);
					}
				}
            }
		}

		public function SendData($VariableID, $Data)
		{
		    $this->SendDebug("Sending", "Sensor: " . $VariableID . ", Value: " . $Data[0] . ", Observed: " . date("d.m.Y H:i:s", $Data[3]), 0);

			$url = $this->ReadPropertyString('Host') . '/Sensor_' . $VariableID . '/attrs';
			$token = $this->ReadPropertyString('AuthToken');

			$data = [
				"name" => [
					"value" => IPS_GetName($VariableID)
				],
				"location" => [
					"type" => "geo:json",
					"value" => [
						"type" => "Point",
						"coordinates" => $this->GetLocation($VariableID)
					]
				],
				"dateObserved" => [
					"value" => date("Y-m-d\TH:i:sO", $Data[4])
				],
				"temperature" => [
					"value" => $Data[0]
				],
				"variableProfile" => [
					"value" => $this->GetVariableProfile($VariableID)
				]
			];

			$json = json_encode($data);

			$options = [
				'http' => [
					'method'  => 'PUT',
					'header'  => "X-Auth-Token: $token\r\n".
								 "Content-Type: application/json\r\n".
								 'Content-Length:'. strlen($json) . "\r\n",
					'content' => $json
				]
			];

			$context = stream_context_create($options);

			@file_get_contents($url, false, $context);
			
			if ($http_response_header[0] == 'HTTP/1.1 404 Not Found') {
				$this->CreateNewEntry($VariableID, $Data);
			}
		}

		private function CreateNewEntry($VariableID, $Data)
		{
			$url = $this->ReadPropertyString('Host');
			$token = $this->ReadPropertyString('AuthToken');

			$data = [
				"type" => "TemperatureObserved",
				"id" => "Sensor_$VariableID",
				"name" => [
					"value" => IPS_GetName($VariableID)
				],
				"location" => [
					"type" => "geo:json",
					"value" => [
						"type" => "Point",
						"coordinates" => $this->GetLocation($VariableID)
					]
				],
				"dateObserved" => [
					"value" => date("Y-m-d\TH:i:sO", $Data[4])
				],
				"temperature" => [
					"value" => $Data[0]
				],
				"variableProfile" => [
					"value" => $this->GetVariableProfile($VariableID)
				]
			];

			$json = json_encode($data);

			$options = [
				'http' => [
					'method'  => 'POST',
					'header'  => "X-Auth-Token: $token\r\n".
								 "Content-Type: application/json\r\n".
								 'Content-Length:'. strlen($json) . "\r\n",
					'content' => $json
				]
			];

			$context = stream_context_create($options);

			file_get_contents($url, false, $context);

		}

		private function GetVariableProfile($VariableID)
		{
			if (IPS_GetVariable($VariableID)['VariableCustomProfile'] == '') {
				if (IPS_GetVariable($VariableID)['VariableCustomProfile'] == '') {
					return 'NoProfile';
				} else {
					return IPS_GetVariable($VariableID)['VariableCustomProfile'];
				}
			} else {
				return IPS_GetVariable($VariableID)['VariableCustomProfile'];
			}
		}


		private function GetLocation($VariableID)
		{
			$variables = json_decode($this->ReadPropertyString('WatchVariables'), true);

			foreach ($variables as $variable) {
				$variableID = $variable['VariableID'];
				if ($variableID == $VariableID) {
					$location = json_decode($variable['Location'], true);
					$returnLocation = [$location['latitude'], $location['longitude']];
					return $returnLocation;
				}
			}
		}
	}