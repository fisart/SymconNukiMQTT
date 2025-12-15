<?php

declare(strict_types=1);

class NukiMQTT extends IPSModule
{
    // Constants for Connection (Working Configuration)
    private const NUKI_MQTT_SERVER_GUID = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const NUKI_MQTT_TX_GUID     = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    
    // Actions
    const ACTION_UNLOCK = 1;
    const ACTION_LOCK = 2;
    const ACTION_UNLATCH = 3;

    public function Create()
    {
        parent::Create();

        // 1. Settings
        $this->RegisterPropertyString('BaseTopic', 'nuki'); 
        $this->RegisterPropertyString('DeviceID', '45A2F2BF');

        // 2. Connect to Parent
        $this->ConnectParent(self::NUKI_MQTT_SERVER_GUID);

        // 3. Profiles
        $this->CreateStatusProfile();
        $this->CreateActionProfile();
        $this->CreateDoorSensorProfile();

        // 4. Variables

        // --- Main Lock Controls ---
        $this->RegisterVariableInteger('LockState', 'Current Status', 'Nuki.State', 10);
        $this->RegisterVariableInteger('LockAction', 'Control', 'Nuki.Action', 20);
        $this->EnableAction('LockAction');
        
        // --- Connectivity ---
        $this->RegisterVariableBoolean('Connected', 'MQTT Connected', '~Alert.Reversed', 30);
        
        // --- Battery ---
        $this->RegisterVariableInteger('BatteryCharge', 'Battery Charge', '~Battery.100', 40);
        $this->RegisterVariableBoolean('BatteryCritical', 'Battery Low', '~Alert', 41);
        $this->RegisterVariableBoolean('BatteryCharging', 'Battery Charging', '~Switch', 42);
        $this->RegisterVariableBoolean('KeypadBatteryCritical', 'Keypad Battery Low', '~Alert', 43);

        // --- Door Sensor ---
        $this->RegisterVariableInteger('DoorSensorState', 'Door State', 'Nuki.DoorSensor', 50);

        // --- Info ---
        $this->RegisterVariableString('Firmware', 'Firmware Version', '', 80);
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
        
        // Filter: Capture everything for this device
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

        $topicRaw = $data->Topic;
        $payload = utf8_decode($data->Payload);

        // Debug incoming
        $this->SendDebug('MQTT In', "Topic: $topicRaw | Payload: $payload", 0);

        $baseTopic = $this->ReadPropertyString('BaseTopic');
        $deviceId = $this->ReadPropertyString('DeviceID');
        $root = $baseTopic . '/' . $deviceId . '/';

        // -----------------------------------------------------------
        // Process Topics
        // -----------------------------------------------------------

        // 1. Lock State
        if ($topicRaw === $root . 'lockState') {
            $this->SetValue('LockState', intval($payload));
        } 
        
        // 2. Connectivity
        elseif ($topicRaw === $root . 'connected') {
            $this->SetValue('Connected', ($payload === 'true'));
        }

        // 3. Battery Info
        elseif ($topicRaw === $root . 'batteryChargeState') {
            $this->SetValue('BatteryCharge', intval($payload));
        }
        elseif ($topicRaw === $root . 'batteryCritical') {
            $this->SetValue('BatteryCritical', ($payload === 'true'));
        }
        elseif ($topicRaw === $root . 'batteryCharging') {
            $this->SetValue('BatteryCharging', ($payload === 'true'));
        }
        elseif ($topicRaw === $root . 'keypadBatteryCritical') {
            $this->SetValue('KeypadBatteryCritical', ($payload === 'true'));
        }

        // 4. Door Sensor
        elseif ($topicRaw === $root . 'doorsensorState') {
            $this->SetValue('DoorSensorState', intval($payload));
        }

        // 5. Device Info
        elseif ($topicRaw === $root . 'firmware') {
            $this->SetValue('Firmware', $payload);
        }

        // 6. Last Action Event (Parsing "1,2,0,0,0")
        elseif ($topicRaw === $root . 'lockActionEvent') {
            $this->ParseLockEvent($payload);
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

    // Helper to make "1,2,0..." readable
    private function ParseLockEvent($payload)
    {
        // Format: Action, Trigger, AuthID, CodeID, AutoUnlock
        $parts = explode(',', $payload);
        if(count($parts) < 2) return;

        $actionMap = [
            1 => 'Unlock', 2 => 'Lock', 3 => 'Unlatch', 
            4 => "Lock 'n' Go", 240 => 'Door Open', 241 => 'Door Closed'
        ];
        
        $triggerMap = [
            0 => 'Manual/App', 1 => 'System', 2 => 'Button', 
            3 => 'Automatic', 6 => 'Auto Lock', 172 => 'MQTT'
        ];

        $action = isset($actionMap[$parts[0]]) ? $actionMap[$parts[0]] : 'Unknown(' . $parts[0] . ')';
        $trigger = isset($triggerMap[$parts[1]]) ? $triggerMap[$parts[1]] : 'Unknown(' . $parts[1] . ')';

        $text = "$action via $trigger";
        $this->SetValue('LastAction', $text);
    }

    // --- Profiles ---

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
            IPS_SetVariableProfileAssociation('Nuki.DoorSensor', 5, 'Calibrating', '', -1);
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