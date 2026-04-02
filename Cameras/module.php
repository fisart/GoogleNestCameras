<?php

declare(strict_types=1);

class NestCameraViewer extends IPSModule
{
    private const STATUS_CREATING = 101;
    private const STATUS_ACTIVE = 102;
    private const STATUS_TOKEN_ERROR = 200;
    private const STATUS_NO_CAMERAS = 201;
    private const STATUS_GOOGLE_ERROR = 202;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('TokenVariableID', 0);
        $this->RegisterPropertyString('SelectedDeviceName', '');
        $this->RegisterPropertyString('HookName', 'nestcam');
        $this->RegisterPropertyBoolean('AutoExtend', true);
        $this->RegisterPropertyBoolean('Debug', false);

        $this->RegisterAttributeString('CachedDevicesJson', '[]');
        $this->RegisterAttributeString('LastOfferSummary', '');
        $this->RegisterAttributeString('LastAnswerSummary', '');

        $this->RegisterVariableString('ViewerHTML', 'Viewer', '~HTMLBox', 10);
        $this->RegisterVariableString('StreamStatus', 'Stream Status', '', 20);
        $this->RegisterVariableString('SelectedCameraLabel', 'Selected Camera', '', 30);
        $this->RegisterVariableString('ExpiresAt', 'Expires At', '', 40);

        $this->EnableAction('StreamStatus');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $hookName = $this->NormalizeHookName($this->ReadPropertyString('HookName'));
        $this->RegisterHook('/hook/' . $hookName);

        $token = $this->GetToken();
        if ($token === '') {
            $this->SetStatus(self::STATUS_TOKEN_ERROR);
            $this->SetValue('StreamStatus', 'Token variable missing or empty');
            $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('Token variable missing or empty'));
            return;
        }

        $devices = $this->FetchDevices();
        if ($devices === null) {
            $this->SetStatus(self::STATUS_GOOGLE_ERROR);
            $this->SetValue('StreamStatus', 'Google SDM request failed');
}