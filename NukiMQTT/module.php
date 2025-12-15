<?php

declare(strict_types=1);

class NukiMQTT extends IPSModule
{
    // Constants from the working reference
    private const NUKI_MQTT_SERVER_GUID = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}'; // The Module ID of your Server
    private const NUKI_MQTT_TX_GUID     = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'; // Data TX (Send)
    
    // Actions
    const ACTION_UNLOCK = 1;
    const ACTION_LOCK = 2;
    const ACTION_UNLATCH = 3;

    public function Create()
    {
        parent::Create();

        // 1. Register Properties
        // Note: The reference calls it 'MQTTTopic', you called it 'BaseTopic'. 
        // We will stick to your naming to keep your settings.
        $this->RegisterPropertyString('BaseTopic', 'nuki'); 
        $this->RegisterPropertyString('DeviceID', '45A2F2BF');

        // 2. Connect to Parent
        // Using the GUID from the reference code which matches your system
        $this->ConnectParent(self::NUKI_MQTT_SERVER_GUID);

        // 3. Profiles and Variables
        $this->CreateStatusProfile();
        $this->CreateActionProfile();

        $this->RegisterVariableInteger('LockState', 'Current Status', 'Nuki.State', 10);
        $this->RegisterVariableInteger('LockAction', 'Control', 'Nuki.Action', 20);
        $this->EnableAction('LockAction');
        $this->RegisterVariableBoolean('Connected', 'Connected', '~Alert.Reversed', 30);
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
        
        // Filter: We construct the filter for the RX Interface
        // Reference uses '.*topic.*', we use specific path '.*nuki/ID/.*'
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
        
        // Robust check for Topic/Payload existence
        if(!property_exists($data, 'Topic') || !property_exists($data, 'Payload')) {
            return;
        }

        $topicRaw = $data->Topic;
        $payload = utf8_decode($data->Payload);

        $this->SendDebug('MQTT In', "Topic: $topicRaw | Payload: $payload", 0);

        $baseTopic = $this->ReadPropertyString('BaseTopic');
        $deviceId = $this->ReadPropertyString('DeviceID');
        $root = $baseTopic . '/' . $deviceId . '/';

        // Check which sub-topic we received
        if ($topicRaw === $root . 'lockState') {
            $this->SetValue('LockState', intval($payload));
        } 
        elseif ($topicRaw === $root . 'connected') {
            $isConnected = ($payload === 'true');
            $this->SetValue('Connected', $isConnected);
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
        // We use the TX GUID from the reference code here
        $DataJSON = json_encode([
            'DataID' => self::NUKI_MQTT_TX_GUID, // {043...}
            'PacketType' => 3,       
            'QualityOfService' => 0, 
            'Retain' => false,       
            'Topic'  => $Topic,
            'Payload'=> $Payload
        ], JSON_UNESCAPED_SLASHES);
        
        $this->SendDataToParent($DataJSON);
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
            IPS_SetVariableProfileAssociation('Nuki.State', 255, 'Undefined', '', -1);
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