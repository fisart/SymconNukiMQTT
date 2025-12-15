<?php

declare(strict_types=1);

class NukiMQTT extends IPSModule
{
    // Mappings based on Nuki MQTT API
    const ACTION_UNLOCK = 1;
    const ACTION_LOCK = 2;
    const ACTION_UNLATCH = 3;

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register Properties
        $this->RegisterPropertyString('BaseTopic', 'nuki');
        $this->RegisterPropertyString('DeviceID', '45A2F2BF');

        // Create Variable Profiles
        $this->CreateStatusProfile();
        $this->CreateActionProfile();

        // Register Variables
        // 1. Status (Read Only)
        $this->RegisterVariableInteger('LockState', 'Current Status', 'Nuki.State', 10);
        
        // 2. Action (Buttons to control the lock via WebFront)
        $this->RegisterVariableInteger('LockAction', 'Control', 'Nuki.Action', 20);
        $this->EnableAction('LockAction');

        // 3. Connectivity
        $this->RegisterVariableBoolean('Connected', 'Connected', '~Alert.Reversed', 30);
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Connect to MQTT Server (Parent)
        $baseTopic = $this->ReadPropertyString('BaseTopic');
        $deviceId = $this->ReadPropertyString('DeviceID');
        
        // Filter: nuki/DeviceID/#
        // We use preg_quote to ensure special characters don't break the regex
        $filter = '.*' . preg_quote($baseTopic . '/' . $deviceId) . '/.*';
        $this->SetReceiveDataFilter($filter);
    }

    // =================================================================
    // PUBLIC FUNCTIONS (These generate the NUKI_ commands)
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
        $topicRaw = $data->Topic;
        $payload = utf8_decode($data->Payload);

        // Debug output
        $this->SendDebug('MQTT In', "Topic: $topicRaw | Payload: $payload", 0);

        $baseTopic = $this->ReadPropertyString('BaseTopic');
        $deviceId = $this->ReadPropertyString('DeviceID');
        $root = $baseTopic . '/' . $deviceId . '/';

        // Check which sub-topic we received
        if ($topicRaw === $root . 'lockState') {
            // Payload is an integer (e.g. 1=Locked, 3=Unlocked)
            $this->SetValue('LockState', intval($payload));
        } 
        elseif ($topicRaw === $root . 'connected') {
            // Payload is "true" or "false"
            $isConnected = ($payload === 'true');
            $this->SetValue('Connected', $isConnected);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        // This function handles clicks from the WebFront
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
        
        // Topic: nuki/45A2F2BF/lockAction
        $topic = $baseTopic . '/' . $deviceId . '/lockAction';
        $payload = (string)$actionCode;

        $this->SendDebug('MQTT Out', "Topic: $topic | Payload: $payload", 0);
        
        // Send to MQTT Parent
        $this->SendMQTT($topic, $payload);
    }

    private function SendMQTT($Topic, $Payload)
    {
        $DataJSON = json_encode([
            'DataID' => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}', // MQTT Server/Client Interface GUID
            'Topic'  => $Topic,
            'Payload'=> $Payload
        ]);
        $this->SendDataToParent($DataJSON);
    }

    private function CreateStatusProfile()
    {
        if (!IPS_VariableProfileExists('Nuki.State')) {
            IPS_CreateVariableProfile('Nuki.State', 1); // 1 = Integer
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
            IPS_CreateVariableProfile('Nuki.Action', 1); // 1 = Integer
            IPS_SetVariableProfileIcon('Nuki.Action', 'Power');
            // These values map to the Action IDs defined at the top
            IPS_SetVariableProfileAssociation('Nuki.Action', 2, 'Lock', 'Lock', 0xFF0000);
            IPS_SetVariableProfileAssociation('Nuki.Action', 1, 'Unlock', 'LockOpen', 0x00FF00);
            IPS_SetVariableProfileAssociation('Nuki.Action', 3, 'Unlatch (Open)', 'Door', 0x0000FF);
        }
    }
}