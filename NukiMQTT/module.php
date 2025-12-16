<?php

declare(strict_types=1);

// Polyfill for fnmatch if missing on some Symcon systems
if (!function_exists('fnmatch')) {
    function fnmatch($pattern, $string): bool
    {
        return boolval(preg_match('#^' . strtr(preg_quote($pattern, '#'), ['\*' => '.*', '\?' => '.']) . '$#i', $string));
    }
}

class NukiMQTT extends IPSModule
{
    private const NUKI_MQTT_SERVER_GUID = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const NUKI_MQTT_TX_GUID     = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    
    const ACTION_UNLOCK = 1;
    const ACTION_LOCK = 2;
    const ACTION_UNLATCH = 3;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('BaseTopic', 'nuki'); 
        $this->RegisterPropertyString('DeviceID', '45A2F2BF');

        $this->ConnectParent(self::NUKI_MQTT_SERVER_GUID);

        $this->CreateStatusProfile();
        $this->CreateActionProfile();
        $this->CreateDoorSensorProfile();

        // Register Variables
        $this->RegisterVariableInteger('LockState', 'Current Status', 'Nuki.State', 10);
        $this->RegisterVariableInteger('LockAction', 'Control', 'Nuki.Action', 20);
        $this->EnableAction('LockAction');
        
        $this->RegisterVariableBoolean('Connected', 'Connected', '~Alert.Reversed', 30);
        
        $this->RegisterVariableInteger('BatteryCharge', 'Battery Charge', '~Battery.100', 40);
        $this->RegisterVariableBoolean('BatteryCritical', 'Battery Low', '~Alert', 41);
        $this->RegisterVariableBoolean('BatteryCharging', 'Battery Charging', '~Switch', 42);
        $this->RegisterVariableBoolean('KeypadBatteryCritical', 'Keypad Battery Low', '~Alert', 43);

        $this->RegisterVariableInteger('DoorSensorState', 'Door Sensor', 'Nuki.DoorSensor', 50);

        $this->RegisterVariableString('Firmware', 'Firmware', '', 80);
        $this->RegisterVariableString('LastAction', 'Last Action', '', 90);
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $baseTopic = $this->ReadPropertyString('BaseTopic');
        $deviceId = $this->ReadPropertyString('DeviceID');
        
        // Filter ensures we only process messages for this specific lock
        $filter = '.*' . preg_quote($baseTopic . '/' . $deviceId) . '/.*';
        $this->SetReceiveDataFilter($filter);
    }

    // =================================================================
    // PUBLIC FUNCTIONS
    // =================================================================

    public function Lock()
    {
        $this->ControlLock(self::ACTION_LOCK);
        $this->SetValue('LockAction', self::ACTION_LOCK);
    }

    public function Unlock()
    {
        $this->ControlLock(self::ACTION_UNLOCK);
        $this->SetValue('LockAction', self::ACTION_UNLOCK);
    }

    public function Unlatch()
    {
        $this->ControlLock(self::ACTION_UNLATCH);
        $this->SetValue('LockAction', self::ACTION_UNLATCH);
    }

    // =================================================================
    // INTERNALS
    // =================================================================

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        
        if(!property_exists($data, 'Topic') || !property_exists($data, 'Payload')) {
            return;
        }

        $topic = $data->Topic;
        $payload = utf8_decode($data->Payload);

        $this->SendDebug('MQTT In', "Topic: $topic | Payload: $payload", 0);

        // We use fnmatch (Wildcards) to match topics.
        // This makes it insensitive to Case (A vs a) and simpler to read.

        switch (true) {
            // Lock State
            case fnmatch('*/lockState', $topic):
                $this->SetValue('LockState', intval($payload));
                break;
            
            // Connection
            case fnmatch('*/connected', $topic):
                $this->SetValue('Connected', ($payload === 'true'));
                break;

            // Battery
            case fnmatch('*/batteryChargeState', $topic):
                $this->SetValue('BatteryCharge', intval($payload));
                break;
            case fnmatch('*/batteryCritical', $topic):
                $this->SetValue('BatteryCritical', ($payload === 'true'));
                break;
            case fnmatch('*/batteryCharging', $topic):
                $this->SetValue('BatteryCharging', ($payload === 'true'));
                break;
            case fnmatch('*/keypadBatteryCritical', $topic):
                $this->SetValue('KeypadBatteryCritical', ($payload === 'true'));
                break;

            // Door Sensor
            case fnmatch('*/doorsensorState', $topic):
                $this->SetValue('DoorSensorState', intval($payload));
                break;

            // Info
            case fnmatch('*/firmware', $topic):
                $this->SetValue('Firmware', $payload);
                break;
            
            // Last Action (Parsing "1,2,0...")
            case fnmatch('*/lockActionEvent', $topic):
                $this->ParseLockEvent($payload);
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'LockAction':
                $this->ControlLock(intval($Value));
                $this->SetValue($Ident, $Value);
                break;
            default:
                throw new Exception("Invalid Ident");
        }
    }

    private function ControlLock(int $actionCode)
    {
        $baseTopic = $this->ReadPropertyString('BaseTopic');
        $deviceId = $this->ReadPropertyString('DeviceID');
        
        $topic = $baseTopic . '/' . $deviceId . '/lockAction';
        $payload = (string)$actionCode;

        $this->SendDebug('MQTT Out', "Topic: $topic | Payload: $payload", 0);
        
        $this->SendMQTT($topic, $payload);
    }

    private function SendMQTT($Topic, $Payload)
    {
        $DataJSON = json_encode([
            'DataID' => self::NUKI_MQTT_TX_GUID,
            'PacketType' => 3,       
            'QualityOfService' => 0, 
            'Retain' => false,       
            'Topic'  => $Topic,
            'Payload'=> $Payload
        ], JSON_UNESCAPED_SLASHES);
        
        $this->SendDataToParent($DataJSON);
    }

    private function ParseLockEvent($payload)
    {
        $parts = explode(',', $payload);
        if(count($parts) < 2) return;

        $actionMap = [
            1 => 'Unlock', 2 => 'Lock', 3 => 'Unlatch', 
            4 => "Lock 'n' Go", 240 => 'Door Open', 241 => 'Door Closed'
        ];
        
        $triggerMap = [
            0 => 'Manual', 1 => 'System', 2 => 'Button', 
            3 => 'Auto', 6 => 'AutoLock', 172 => 'MQTT'
        ];

        $action = isset($actionMap[$parts[0]]) ? $actionMap[$parts[0]] : $parts[0];
        $trigger = isset($triggerMap[$parts[1]]) ? $triggerMap[$parts[1]] : $parts[1];

        $this->SetValue('LastAction', "$action via $trigger");
    }

    private function CreateStatusProfile()
    {
        if (!IPS_VariableProfileExists('Nuki.State')) {
            IPS_CreateVariableProfile('Nuki.State', 1);
            IPS_SetVariableProfileAssociation('Nuki.State', 0, 'Uncalibrated', '', -1);
            IPS_SetVariableProfileAssociation('Nuki.State', 1, 'Locked', 'Lock', 0xFF0000);
            IPS_SetVariableProfileAssociation('Nuki.State', 2, 'Unlocking', '', -1);
            IPS_SetVariableProfileAssociation('Nuki.State', 3, 'Unlocked', 'LockOpen', 0x00FF00);
            IPS_SetVariableProfileAssociation('Nuki.State', 4, 'Locking', '', -1);
            IPS_SetVariableProfileAssociation('Nuki.State', 5, 'Unlatched', 'Door', 0x00FF00);
            IPS_SetVariableProfileAssociation('Nuki.State', 6, 'Unlocked (Lock \'n\' Go)', '', -1);
            IPS_SetVariableProfileAssociation('Nuki.State', 7, 'Unlatching', '', -1);
            IPS_SetVariableProfileAssociation('Nuki.State', 254, 'Motor Blocked', 'Warning', 0xFF0000);
        }
    }

    private function CreateDoorSensorProfile()
    {
        if (!IPS_VariableProfileExists('Nuki.DoorSensor')) {
            IPS_CreateVariableProfile('Nuki.DoorSensor', 1);
            IPS_SetVariableProfileIcon('Nuki.DoorSensor', 'Door');
            IPS_SetVariableProfileAssociation('Nuki.DoorSensor', 1, 'Deactivated', '', -1);
            IPS_SetVariableProfileAssociation('Nuki.DoorSensor', 2, 'Closed', 'Door', 0x00FF00);
            IPS_SetVariableProfileAssociation('Nuki.DoorSensor', 3, 'Open', 'Door', 0xFF0000);
            IPS_SetVariableProfileAssociation('Nuki.DoorSensor', 4, 'Unknown', '', -1);
        }
    }

    private function CreateActionProfile()
    {
        if (!IPS_VariableProfileExists('Nuki.Action')) {
            IPS_CreateVariableProfile('Nuki.Action', 1);
            IPS_SetVariableProfileIcon('Nuki.Action', 'Power');
            IPS_SetVariableProfileAssociation('Nuki.Action', 2, 'Lock', 'Lock', 0xFF0000);
            IPS_SetVariableProfileAssociation('Nuki.Action', 1, 'Unlock', 'LockOpen', 0x00FF00);
            IPS_SetVariableProfileAssociation('Nuki.Action', 3, 'Unlatch (Open)', 'Door', 0x0000FF);
        }
    }
}