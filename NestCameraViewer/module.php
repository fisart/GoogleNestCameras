<?php

declare(strict_types=1);

class NestCameraViewer extends IPSModuleStrict
{
    private const STATUS_CREATING = 101;
    private const STATUS_ACTIVE = 102;
    private const STATUS_TOKEN_ERROR = 200;
    private const STATUS_NO_CAMERAS = 201;
    private const STATUS_GOOGLE_ERROR = 202;
    private const WRITABLE_FIELDS = [
        'sdm.devices.traits.ThermostatMode.mode' => [
            'command_key' => 'ThermostatMode.SetMode',
            'value_type'  => 'string'
        ],
        'sdm.devices.traits.ThermostatTemperatureSetpoint.coolCelsius' => [
            'command_key' => 'ThermostatTemperatureSetpoint.SetCool',
            'value_type'  => 'float'
        ],
        'sdm.devices.traits.ThermostatTemperatureSetpoint.heatCelsius' => [
            'command_key' => 'ThermostatTemperatureSetpoint.SetHeat',
            'value_type'  => 'float'
        ]
    ];

    public function Create(): void
    {
        parent::Create();

        // Shared vault instance
        $this->RegisterPropertyInteger('VaultInstanceID', 0);

        // Viewer auth
        $this->RegisterPropertyString('ViewerAuthIdent', 'NestCameraViewer/Auth');

        // Local OAuth record path inside vault (__LOCAL__ via scope=local)
        $this->RegisterPropertyString('OAuthConnectionIdent', 'GoogleNest/SharedConnection');

        // Static OAuth setup input
        $this->RegisterPropertyString('OAuthSetupConnectionName', 'Hermitage Nest');
        $this->RegisterPropertyString('OAuthSetupProjectID', '');
        $this->RegisterPropertyString('OAuthSetupEnterpriseID', '');
        $this->RegisterPropertyString('OAuthSetupClientID', '');
        $this->RegisterPropertyString('OAuthSetupClientSecret', '');
        $this->RegisterPropertyString('OAuthSetupRedirectURI', 'https://www.google.com');
        $this->RegisterPropertyString('OAuthSetupScope', 'https://www.googleapis.com/auth/sdm.service');
        $this->RegisterPropertyString('BootstrapAuthorizationCode', '');

        // Viewer / routing
        $this->RegisterPropertyString('SelectedDeviceName', '');
        $this->RegisterPropertyString('HookName', 'nestcam');

        // Master / slave token model
        $this->RegisterPropertyBoolean('IsTokenMaster', false);
        $this->RegisterPropertyInteger('ExternalAccessTokenVariableID', 0);
        $this->RegisterPropertyInteger('ExternalRefreshTokenVariableID', 0);

        // WebHook protection
        $this->RegisterPropertyInteger('AuthMode', 0);
        $this->RegisterPropertyBoolean('AutoExtend', true);
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterPropertyInteger('AutoRefreshSeconds', 0);
        $this->RegisterTimer('RefreshTimer', 0, 'NESTCAM_AutoRefresh($_IPS[\'TARGET\']);');
        // Attributes
        $this->RegisterAttributeString('CachedDevicesJson', '[]');
        $this->RegisterAttributeString('LastOfferSummary', '');
        $this->RegisterAttributeString('LastAnswerSummary', '');
        $this->RegisterAttributeString('RegisteredHookName', '');
        $this->RegisterAttributeString('RegisteredCameraHooksJson', '[]');
        $this->RegisterAttributeString('HookDeviceMapJson', '{}');
        $this->RegisterAttributeString('OAuthLastState', '');
        $this->RegisterAttributeString('OAuthBootstrapCompleted', '0');
        $this->RegisterAttributeString('LastGoogleError', '');
        $this->RegisterAttributeString('DeviceCatalogJson', '{}');
        $this->RegisterAttributeString('VariableCatalogJson', '{}');
        $this->RegisterAttributeString('KnownDeviceCategoryIdentsJson', '[]');
        $this->RegisterAttributeString('KnownVariableCatalogKeysJson', '[]');
        $this->RegisterAttributeInteger('ManagedActionScriptID', 0);
        // Viewer variables
        $this->RegisterVariableString('ViewerHTML', 'Viewer', '~HTMLBox', 10);
        $this->RegisterVariableString('StreamStatus', 'Stream Status', '', 20);
        $this->RegisterVariableString('SelectedCameraLabel', 'Selected Camera', '', 30);
        $this->RegisterVariableString('ExpiresAt', 'Expires At', '', 40);

        // Local token variables (master)
        $this->RegisterVariableString('AccessToken', 'Access Token', '', 50);
        $this->RegisterVariableString('AccessTokenExpiresAt', 'Access Token Expires At', '', 60);
        $this->RegisterVariableString('RefreshToken', 'Refresh Token', '', 70);
        $this->RegisterVariableString('AuthorizationURL', 'Authorization URL', '', 80);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('RefreshTimer', 0);

        $autoRefreshSeconds = max(0, $this->ReadPropertyInteger('AutoRefreshSeconds'));

        $managedActionScriptID = $this->EnsureManagedActionScript();
        if ($managedActionScriptID <= 0 || !IPS_ScriptExists($managedActionScriptID)) {
            $this->SetStatus(self::STATUS_TOKEN_ERROR);
            $this->SetValue('StreamStatus', 'Managed action script could not be created');
            $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('Managed action script could not be created'));
            return;
        }

        $this->RegisterHook('webhook_for_google_events');

        $hookName = $this->NormalizeHookName($this->ReadPropertyString('HookName'));
        $oldHookName = $this->ReadAttributeString('RegisteredHookName');

        if ($oldHookName !== '' && $oldHookName !== $hookName) {
            $this->UnregisterHook($oldHookName);
        }

        if ($hookName !== '') {
            $this->RegisterHook($hookName);
            $this->WriteAttributeString('RegisteredHookName', $hookName);
        }

        $this->UnregisterGeneratedCameraHooks();

        $vaultID = $this->ReadPropertyInteger('VaultInstanceID');
        if ($vaultID <= 0) {
            $this->SetStatus(self::STATUS_TOKEN_ERROR);
            $this->SetValue('StreamStatus', 'Vault instance missing');
            $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('Vault instance missing'));
            return;
        }

        $authMode = $this->ReadPropertyInteger('AuthMode');
        if ($authMode > 0 && $vaultID <= 0) {
            $this->SetStatus(self::STATUS_GOOGLE_ERROR);
            $this->SetValue('StreamStatus', 'Vault instance missing');
            $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('Vault instance missing'));
            return;
        }

        if ($this->ReadPropertyBoolean('IsTokenMaster')) {
            try {
                $this->LoadOAuthStaticConfig();
            } catch (Throwable $e) {
                $this->SetStatus(self::STATUS_TOKEN_ERROR);
                $this->SetValue('StreamStatus', 'Local OAuth setup missing or invalid');
                $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('Local OAuth setup missing or invalid'));
                return;
            }

            $refreshToken = trim((string) GetValue($this->GetIDForIdent('RefreshToken')));
            if ($refreshToken === '') {
                $this->SetStatus(self::STATUS_TOKEN_ERROR);
                $this->SetValue('StreamStatus', 'Bootstrap required: no local refresh token');
                $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('Bootstrap required: no local refresh token'));
                return;
            }
        } else {
            $accessVarID = $this->ReadPropertyInteger('ExternalAccessTokenVariableID');
            $refreshVarID = $this->ReadPropertyInteger('ExternalRefreshTokenVariableID');

            if ($accessVarID <= 0 || !IPS_VariableExists($accessVarID)) {
                $this->SetStatus(self::STATUS_TOKEN_ERROR);
                $this->SetValue('StreamStatus', 'External access token variable missing');
                $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('External access token variable missing'));
                return;
            }

            if ($refreshVarID <= 0 || !IPS_VariableExists($refreshVarID)) {
                $this->SetStatus(self::STATUS_TOKEN_ERROR);
                $this->SetValue('StreamStatus', 'External refresh token variable missing');
                $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('External refresh token variable missing'));
                return;
            }

            $externalRefreshToken = trim((string) GetValue($refreshVarID));
            if ($externalRefreshToken === '') {
                $this->SetStatus(self::STATUS_TOKEN_ERROR);
                $this->SetValue('StreamStatus', 'External refresh token variable is empty');
                $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('External refresh token variable is empty'));
                return;
            }

            $externalAccessToken = trim((string) GetValue($accessVarID));
            if ($externalAccessToken === '') {
                $this->SetStatus(self::STATUS_TOKEN_ERROR);
                $this->SetValue('StreamStatus', 'External access token variable is empty');
                $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('External access token variable is empty'));
                return;
            }
        }

        $devices = $this->FetchDevices();
        if ($devices === null) {
            $detail = trim($this->ReadAttributeString('LastGoogleError'));
            $message = 'Google SDM request failed';
            if ($detail !== '') {
                $message .= ': ' . $detail;
            }

            $this->SetStatus(self::STATUS_GOOGLE_ERROR);
            $this->SetValue('StreamStatus', $message);
            $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml($message));
            return;
        }

        if (count($devices) === 0) {
            $this->SetStatus(self::STATUS_NO_CAMERAS);
            $this->SetValue('StreamStatus', 'No compatible WEB_RTC cameras found');
            $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('No compatible WEB_RTC cameras found'));
            return;
        }

        $this->SyncDeviceStructure($devices);
        $this->UpdateDeviceValues($devices);

        if ($this->ReadPropertyString('SelectedDeviceName') === '__ALL__') {
            $this->RegisterGeneratedCameraHooks($devices);
        }

        $selected = $this->ResolveSelectedDeviceName($devices);
        if ($selected === '__ALL__') {
            $this->SetValue('SelectedCameraLabel', 'All');
        } else {
            $this->SetValue('SelectedCameraLabel', $devices[$selected]['label']);
        }

        $this->SetValue('StreamStatus', 'Ready');
        $this->SetValue('ViewerHTML', $this->RenderViewerHtml());
        $this->SetTimerInterval('RefreshTimer', $autoRefreshSeconds > 0 ? ($autoRefreshSeconds * 1000) : 0);
        $this->SetStatus(self::STATUS_ACTIVE);
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [],
            'actions'  => [],
            'status'   => [
                [
                    'code'    => self::STATUS_CREATING,
                    'icon'    => 'active',
                    'caption' => 'Creating instance'
                ],
                [
                    'code'    => self::STATUS_ACTIVE,
                    'icon'    => 'active',
                    'caption' => 'Active'
                ],
                [
                    'code'    => self::STATUS_TOKEN_ERROR,
                    'icon'    => 'error',
                    'caption' => 'Token or OAuth configuration missing'
                ],
                [
                    'code'    => self::STATUS_NO_CAMERAS,
                    'icon'    => 'error',
                    'caption' => 'No compatible WEB_RTC cameras found for viewer'
                ],
                [
                    'code'    => self::STATUS_GOOGLE_ERROR,
                    'icon'    => 'error',
                    'caption' => 'Google SDM request failed'
                ]
            ]
        ];

        $vaultID = $this->ReadPropertyInteger('VaultInstanceID');
        $currentSelectedDevice = $this->ReadPropertyString('SelectedDeviceName');

        try {
            if ($vaultID > 0) {
                $devices = $this->GetCachedDevices();
            } else {
                $devices = [];
            }
        } catch (Throwable $e) {
            $devices = [];
        }

        $deviceOptions = [
            [
                'caption' => '',
                'value'   => ''
            ],
            [
                'caption' => 'All viewer camera hooks',
                'value'   => '__ALL__'
            ]
        ];

        foreach ($devices as $deviceName => $device) {
            $label = (string) ($device['label'] ?? $deviceName);
            $type  = (string) ($device['type'] ?? '');
            $deviceOptions[] = [
                'caption' => $label . ($type !== '' ? ' [' . $type . ']' : ''),
                'value'   => $deviceName
            ];
        }

        if ($currentSelectedDevice !== '') {
            $found = false;
            foreach ($deviceOptions as $option) {
                if ($option['value'] === $currentSelectedDevice) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $deviceOptions[] = [
                    'caption' => $currentSelectedDevice . ' (current)',
                    'value'   => $currentSelectedDevice
                ];
            }
        }

        $hookPath = '/hook/' . $this->NormalizeHookName($this->ReadPropertyString('HookName'));

        $isMaster = $this->ReadPropertyBoolean('IsTokenMaster');
        $deviceCatalogJson = $this->ReadAttributeString('DeviceCatalogJson');
        if (!is_string($deviceCatalogJson) || $deviceCatalogJson === '') {
            $deviceCatalog = [];
        } else {
            $deviceCatalog = json_decode($deviceCatalogJson, true);
            if (!is_array($deviceCatalog)) {
                $deviceCatalog = [];
            }
        }
        if (!is_array($deviceCatalog)) {
            $deviceCatalog = [];
        }

        $deviceRows = [];
        foreach ($deviceCatalog as $entry) {
            $deviceRows[] = [
                'Label'      => (string) ($entry['label'] ?? ''),
                'Type'       => (string) ($entry['device_type'] ?? ''),
                'DeviceName' => (string) ($entry['device_name'] ?? ''),
                'CategoryID' => (int) ($entry['category_id'] ?? 0)
            ];
        }
        $form['elements'] = [
            [
                'type'    => 'Label',
                'caption' => 'Google SDM Device Module'
            ],
            [
                'type'    => 'Label',
                'caption' => 'This module can discover all Google SDM devices. The HTML viewer remains camera-specific and only works for WEB_RTC capable camera devices.'
            ],

            [
                'type'    => 'ExpansionPanel',
                'caption' => 'General Configuration',
                'items'   => [
                    [
                        'type'    => 'SelectInstance',
                        'name'    => 'VaultInstanceID',
                        'caption' => 'Vault Instance'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'ViewerAuthIdent',
                        'caption' => 'Viewer Auth Ident'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'OAuthConnectionIdent',
                        'caption' => 'Local OAuth Record Path'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'HookName',
                        'caption' => 'Viewer Hook Name'
                    ],
                    [
                        'type'    => 'Select',
                        'name'    => 'AuthMode',
                        'caption' => 'Viewer WebHook Protection',
                        'options' => [
                            ['caption' => 'No password',              'value' => 0],
                            ['caption' => 'Vault / WebHook password', 'value' => 1],
                            ['caption' => 'Passkey',                  'value' => 2]
                        ]
                    ],
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'AutoExtend',
                        'caption' => 'Auto Extend Camera Stream'
                    ],
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'Debug',
                        'caption' => 'Show Debug Information in Viewer'
                    ],
                    [
                        'type'    => 'Select',
                        'name'    => 'AutoRefreshSeconds',
                        'caption' => 'Automatic Value Refresh',
                        'options' => [
                            ['caption' => 'Disabled',   'value' => 0],
                            ['caption' => '30 seconds', 'value' => 30],
                            ['caption' => '60 seconds', 'value' => 60],
                            ['caption' => '120 seconds', 'value' => 120],
                            ['caption' => '300 seconds', 'value' => 300],
                            ['caption' => '600 seconds', 'value' => 600]
                        ]
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => 'Viewer Hook URL: ' . $hookPath
                    ]
                ]
            ],

            [
                'type'    => 'ExpansionPanel',
                'caption' => 'Token Role',
                'items'   => [
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'IsTokenMaster',
                        'caption' => 'This instance is Token Master'
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => $isMaster
                            ? 'Master mode: this instance creates the authorization URL, exchanges the authorization code, stores RefreshToken locally and refreshes AccessToken locally.'
                            : 'Slave mode: this instance does not generate tokens. It reads external RefreshToken and AccessToken variables from the object tree.'
                    ],
                    [
                        'type'    => 'SelectVariable',
                        'name'    => 'ExternalAccessTokenVariableID',
                        'caption' => 'External Access Token Variable (slave mode)'
                    ],
                    [
                        'type'    => 'SelectVariable',
                        'name'    => 'ExternalRefreshTokenVariableID',
                        'caption' => 'External Refresh Token Variable (slave mode)'
                    ]
                ]
            ],

            [
                'type'    => 'ExpansionPanel',
                'caption' => 'Local OAuth Configuration',
                'items'   => [
                    [
                        'type'    => 'Label',
                        'caption' => 'These values are written to the local vault record. They are static connection settings, not runtime tokens.'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'OAuthSetupConnectionName',
                        'caption' => 'Connection Name'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'OAuthSetupProjectID',
                        'caption' => 'Project ID'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'OAuthSetupEnterpriseID',
                        'caption' => 'Enterprise ID'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'OAuthSetupClientID',
                        'caption' => 'Client ID'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'OAuthSetupClientSecret',
                        'caption' => 'Client Secret'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'OAuthSetupRedirectURI',
                        'caption' => 'Redirect URI'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'OAuthSetupScope',
                        'caption' => 'OAuth Scope'
                    ]
                ]
            ],

            [
                'type'    => 'ExpansionPanel',
                'caption' => 'Bootstrap (Master Only)',
                'items'   => [
                    [
                        'type'    => 'Label',
                        'caption' => '1) Fill in the OAuth fields above.'
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => '2) Click "Save OAuth Setup to Local Vault".'
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => '3) Click "Generate Authorization URL".'
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => '4) Open the generated URL in a normal browser and complete Google consent.'
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => '5) Paste the full returned URL or only the value after code= into the field below.'
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => '6) Click "Exchange Authorization Code". RefreshToken, AccessToken and AccessTokenExpiresAt will be stored below the master instance.'
                    ],
                    [
                        'type'    => 'ValidationTextBox',
                        'name'    => 'BootstrapAuthorizationCode',
                        'caption' => 'Returned Browser URL or Authorization Code'
                    ]
                ]
            ],

            [
                'type'    => 'ExpansionPanel',
                'caption' => 'Camera Viewer',
                'items'   => [
                    [
                        'type'    => 'Label',
                        'caption' => 'The HTML viewer is camera-specific. It only applies to devices that support WEB_RTC live streaming.'
                    ],
                    [
                        'type'    => 'Select',
                        'name'    => 'SelectedDeviceName',
                        'caption' => 'Viewer Device',
                        'options' => $deviceOptions
                    ]
                ]
            ],

            [
                'type'    => 'ExpansionPanel',
                'caption' => 'Device Synchronization',
                'items'   => [
                    [
                        'type'    => 'Label',
                        'caption' => 'All Google SDM devices are discovered and synchronized into categories and variables below this instance.'
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => 'The generated object tree is independent from the camera viewer selection above.'
                    ]
                ]
            ],
            [
                'type'    => 'ExpansionPanel',
                'caption' => 'Discovered Devices',
                'items'   => [
                    [
                        'type'    => 'List',
                        'name'    => 'DiscoveredDevices',
                        'caption' => 'Devices found in Google SDM',
                        'rowCount' => 10,
                        'add'     => false,
                        'delete'  => false,
                        'sort'    => [
                            'column'    => 'Label',
                            'direction' => 'ascending'
                        ],
                        'columns' => [
                            [
                                'name'    => 'Label',
                                'caption' => 'Name',
                                'width'   => '200px'
                            ],
                            [
                                'name'    => 'Type',
                                'caption' => 'Type',
                                'width'   => '220px'
                            ],
                            [
                                'name'    => 'DeviceName',
                                'caption' => 'Google Device Name',
                                'width'   => '400px'
                            ],
                            [
                                'name'    => 'CategoryID',
                                'caption' => 'Category ID',
                                'width'   => '100px'
                            ]
                        ],
                        'values'  => $deviceRows
                    ]
                ]
            ],
        ];

        $form['actions'] = [
            [
                'type'    => 'Label',
                'caption' => 'Maintenance'
            ],
            [
                'type'    => 'Button',
                'caption' => 'Save OAuth Setup to Local Vault',
                'onClick' => 'NESTCAM_SaveOAuthSetupToLocalVault($id);'
            ],
            [
                'type'    => 'Button',
                'caption' => 'Generate Authorization URL',
                'onClick' => 'NESTCAM_GenerateAuthorizationURL($id);'
            ],
            [
                'type'    => 'Button',
                'caption' => 'Exchange Authorization Code',
                'onClick' => 'NESTCAM_ExchangeAuthorizationCode($id);'
            ],
            [
                'type'    => 'Button',
                'caption' => 'Refresh Google SDM Devices',
                'onClick' => 'NESTCAM_RefreshDevices($id);'
            ],
            [
                'type'    => 'Button',
                'caption' => 'Rebuild Camera Viewer HTML',
                'onClick' => 'NESTCAM_RebuildViewer($id);'
            ]
        ];

        return json_encode($form);
    }

    public function AutoRefresh(): void
    {
        $devices = $this->FetchDevices();
        if ($devices === null) {
            $detail = trim($this->ReadAttributeString('LastGoogleError'));
            $message = 'Automatic refresh failed';
            if ($detail !== '') {
                $message .= ': ' . $detail;
            }

            $this->LogMessage($message, KL_MESSAGE);
            return;
        }

        $this->UpdateRelevantDeviceValues($devices);
    }

    public function RefreshDevices(): void
    {
        $devices = $this->FetchDevices();
        if ($devices === null) {
            $detail = trim($this->ReadAttributeString('LastGoogleError'));
            $message = 'Google SDM request failed';
            if ($detail !== '') {
                $message .= ': ' . $detail;
            }

            $this->SetStatus(self::STATUS_GOOGLE_ERROR);
            echo $message;
            return;
        }

        if (count($devices) === 0) {
            $this->SetStatus(self::STATUS_NO_CAMERAS);
            echo 'No compatible WEB_RTC cameras found';
            return;
        }

        if ($this->ReadPropertyString('SelectedDeviceName') === '__ALL__') {
            $this->UnregisterGeneratedCameraHooks();
            $this->RegisterGeneratedCameraHooks($devices);
            $this->SetValue('SelectedCameraLabel', 'All');
        } else {
            $selected = $this->ResolveSelectedDeviceName($devices);
            $this->SetValue('SelectedCameraLabel', $devices[$selected]['label']);
        }

        $this->SetStatus(self::STATUS_ACTIVE);
        echo 'Found ' . count($devices) . ' compatible camera(s)';
    }

    public function RebuildViewer(): void
    {
        $this->SetValue('ViewerHTML', $this->RenderViewerHtml());
        echo 'Viewer rebuilt';
    }

    protected function ProcessHookData(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        $eventHookName = 'webhook_for_google_events';
        if ($path === '/hook/' . $eventHookName) {
            try {
                $rawBody = @file_get_contents('php://input');
                if (!is_string($rawBody) || $rawBody === '') {
                    http_response_code(400);
                    echo 'Missing body';
                    return;
                }

                $envelope = json_decode($rawBody, true);
                if (!is_array($envelope)) {
                    http_response_code(400);
                    echo 'Invalid JSON';
                    return;
                }

                $pubsubMessage = $envelope['message'] ?? null;
                if (!is_array($pubsubMessage)) {
                    http_response_code(400);
                    echo 'Missing Pub/Sub message';
                    return;
                }

                $messageDataBase64 = (string) ($pubsubMessage['data'] ?? '');
                if ($messageDataBase64 === '') {
                    $this->LogMessage('Google event received without data payload', KL_MESSAGE);
                    http_response_code(200);
                    echo 'OK';
                    return;
                }

                $decodedMessageJson = base64_decode($messageDataBase64, true);
                if (!is_string($decodedMessageJson) || $decodedMessageJson === '') {
                    http_response_code(400);
                    echo 'Invalid base64 payload';
                    return;
                }

                $eventPayload = json_decode($decodedMessageJson, true);
                if (!is_array($eventPayload)) {
                    http_response_code(400);
                    echo 'Invalid decoded event JSON';
                    return;
                }

                $this->LogMessage(
                    'Google event received: ' . json_encode($eventPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    KL_MESSAGE
                );

                $deviceName = (string) ($eventPayload['resourceUpdate']['name'] ?? '');
                if ($deviceName === '') {
                    $this->LogMessage('Google event contains no resourceUpdate.name', KL_MESSAGE);
                    http_response_code(200);
                    echo 'OK';
                    return;
                }

                $deviceCatalogJson = $this->ReadAttributeString('DeviceCatalogJson');
                if (!is_string($deviceCatalogJson) || $deviceCatalogJson === '') {
                    $deviceCatalog = [];
                } else {
                    $deviceCatalog = json_decode($deviceCatalogJson, true);
                    if (!is_array($deviceCatalog)) {
                        $deviceCatalog = [];
                    }
                }

                if (!array_key_exists($deviceName, $deviceCatalog)) {
                    $devices = $this->FetchDevices();
                    if ($devices === null) {
                        $detail = trim($this->ReadAttributeString('LastGoogleError'));
                        $message = 'Google event full refresh failed for unknown device: ' . $deviceName;
                        if ($detail !== '') {
                            $message .= ' - ' . $detail;
                        }

                        $this->LogMessage($message, KL_MESSAGE);
                        http_response_code(200);
                        echo 'OK';
                        return;
                    }

                    $this->SyncDeviceStructure($devices);
                    $this->UpdateDeviceValues($devices);
                    $this->LogMessage('Google event triggered full refresh for unknown device: ' . $deviceName, KL_MESSAGE);

                    http_response_code(200);
                    echo 'OK';
                    return;
                }

                $device = $this->FetchSingleDevice($deviceName);
                if ($device === null) {
                    $detail = trim($this->ReadAttributeString('LastGoogleError'));

                    if (strpos($detail, 'HTTP 404') !== false) {
                        $this->LogMessage('Google event ignored non-fetchable resource: ' . $deviceName, KL_MESSAGE);
                        http_response_code(200);
                        echo 'OK';
                        return;
                    }

                    $message = 'Google event refresh failed for device: ' . $deviceName;
                    if ($detail !== '') {
                        $message .= ' - ' . $detail;
                    }

                    $this->LogMessage($message, KL_MESSAGE);
                    http_response_code(200);
                    echo 'OK';
                    return;
                }

                $this->UpdateSingleDeviceValues($deviceName, $device);
                $this->LogMessage('Google event refreshed known device: ' . $deviceName, KL_MESSAGE);
                http_response_code(200);
                echo 'OK';
                return;
            } catch (Throwable $e) {
                $this->LogMessage('Google event hook error: ' . $e->getMessage(), KL_MESSAGE);
                http_response_code(500);
                echo 'Error';
                return;
            }
        }

        $baseHookName = $this->NormalizeHookName($this->ReadPropertyString('HookName'));
        $allowedHooks = [$baseHookName];

        $generatedHooksJson = $this->ReadAttributeString('RegisteredCameraHooksJson');
        if (!is_string($generatedHooksJson) || $generatedHooksJson === '') {
            $generatedHooks = [];
        } else {
            $generatedHooks = json_decode($generatedHooksJson, true);
            if (!is_array($generatedHooks)) {
                $generatedHooks = [];
            }
        }

        if (is_array($generatedHooks)) {
            foreach ($generatedHooks as $generatedHook) {
                if (is_string($generatedHook) && $generatedHook !== '') {
                    $allowedHooks[] = $generatedHook;
                }
            }
        }

        $matched = false;
        foreach ($allowedHooks as $allowedHook) {
            if ($path === '/hook/' . $allowedHook) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $action = $_GET['action'] ?? $_POST['action'] ?? 'page';

        try {
            switch ($action) {
                case 'page':
                    $this->EnforceWebhookAuth();
                    header('Content-Type: text/html; charset=utf-8');
                    echo $this->RenderViewerHtml();
                    return;

                case 'ping':
                    $this->SendJson([
                        'ok'         => true,
                        'instanceID' => $this->InstanceID,
                        'message'    => 'Backend reached'
                    ]);
                    return;

                case 'authcheck':
                    $authMode = $this->ReadPropertyInteger('AuthMode');
                    if ($authMode === 0) {
                        $this->SendJson([
                            'ok'            => true,
                            'authenticated' => true
                        ]);
                        return;
                    }

                    $this->SendJson([
                        'ok'            => true,
                        'authenticated' => $this->IsWebhookAuthenticated(),
                        'loginUrl'      => $this->GetWebhookLoginUrl()
                    ]);
                    return;

                case 'devices':
                    $devices = $this->GetCachedDevices();
                    $items = [];
                    foreach ($devices as $name => $device) {
                        $items[] = [
                            'label' => $device['label'],
                            'name'  => $name
                        ];
                    }

                    $this->SendJson([
                        'ok'      => true,
                        'devices' => $items,
                        'default' => $this->ResolveSelectedDeviceName($devices)
                    ]);
                    return;

                case 'info':
                    $this->RequireWebhookAuthForApi();
                    $deviceName = $this->ResolveRequestDeviceName();
                    $url = 'https://smartdevicemanagement.googleapis.com/v1/' . $deviceName;
                    $result = $this->GoogleRequest($url, 'GET');

                    if ($result['httpCode'] !== 200) {
                        $this->SendJson([
                            'ok'       => false,
                            'error'    => 'Device read failed',
                            'httpCode' => $result['httpCode'],
                            'raw'      => $result['response']
                        ], 500);
                        return;
                    }

                    $this->SendJson([
                        'ok'     => true,
                        'device' => json_decode($result['response'], true)
                    ]);
                    return;

                case 'generate':
                    $this->RequireWebhookAuthForApi();
                    $deviceName = $this->ResolveRequestDeviceName();
                    $offerSdp = $this->ReadPostedOfferSdp();

                    if ($offerSdp === '') {
                        $this->SendJson([
                            'ok'    => false,
                            'error' => 'Missing offerSdp'
                        ], 400);
                        return;
                    }

                    if (!preg_match("/(\r\n|\n)$/", $offerSdp)) {
                        $offerSdp .= "\n";
                    }

                    $url = 'https://smartdevicemanagement.googleapis.com/v1/' . $deviceName . ':executeCommand';
                    $body = [
                        'command' => 'sdm.devices.commands.CameraLiveStream.GenerateWebRtcStream',
                        'params'  => [
                            'offerSdp' => $offerSdp
                        ]
                    ];

                    $result = $this->GoogleRequest($url, 'POST', $body);

                    if ($result['httpCode'] !== 200) {
                        $this->SendJson([
                            'ok'           => false,
                            'error'        => 'GenerateWebRtcStream failed',
                            'httpCode'     => $result['httpCode'],
                            'raw'          => $result['response'],
                            'offerSummary' => $this->SummarizeSdp($offerSdp)
                        ], 500);
                        return;
                    }

                    $data = json_decode($result['response'], true);
                    $answerSdp = $data['results']['answerSdp'] ?? '';

                    $this->WriteAttributeString('LastOfferSummary', $this->SummarizeSdp($offerSdp));
                    $this->WriteAttributeString('LastAnswerSummary', $this->SummarizeSdp($answerSdp));
                    $this->SetValue('ExpiresAt', (string) ($data['results']['expiresAt'] ?? ''));
                    $this->SetValue('StreamStatus', 'Stream started');

                    $this->SendJson([
                        'ok'             => true,
                        'answerSdp'      => $answerSdp,
                        'answerSummary'  => $this->SummarizeSdp($answerSdp),
                        'offerSummary'   => $this->SummarizeSdp($offerSdp),
                        'mediaSessionId' => $data['results']['mediaSessionId'] ?? '',
                        'expiresAt'      => $data['results']['expiresAt'] ?? ''
                    ]);
                    return;

                case 'extend':
                    $this->RequireWebhookAuthForApi();
                    $deviceName = $this->ResolveRequestDeviceName();
                    $mediaSessionId = $_POST['mediaSessionId'] ?? '';

                    if ($mediaSessionId === '') {
                        $this->SendJson(['ok' => false, 'error' => 'Missing mediaSessionId'], 400);
                        return;
                    }

                    $url = 'https://smartdevicemanagement.googleapis.com/v1/' . $deviceName . ':executeCommand';
                    $body = [
                        'command' => 'sdm.devices.commands.CameraLiveStream.ExtendWebRtcStream',
                        'params'  => [
                            'mediaSessionId' => $mediaSessionId
                        ]
                    ];

                    $result = $this->GoogleRequest($url, 'POST', $body);

                    if ($result['httpCode'] !== 200) {
                        $raw = (string) ($result['response'] ?? '');

                        if (
                            ($result['httpCode'] === 400) &&
                            (strpos($raw, 'FAILED_PRECONDITION') !== false) &&
                            (strpos($raw, 'invalid session or user id mismatch') !== false)
                        ) {
                            $this->SetValue('StreamStatus', 'Stream restart required');
                            $this->SetValue('ExpiresAt', '');

                            $this->SendJson([
                                'ok'             => false,
                                'restartRequired' => true,
                                'error'          => 'Stream session is no longer valid',
                                'httpCode'       => $result['httpCode'],
                                'raw'            => $raw
                            ], 200);
                            return;
                        }

                        $this->SendJson([
                            'ok'       => false,
                            'error'    => 'ExtendWebRtcStream failed',
                            'httpCode' => $result['httpCode'],
                            'raw'      => $result['response']
                        ], 500);
                        return;
                    }

                    $data = json_decode($result['response'], true);
                    $this->SetValue('ExpiresAt', (string) ($data['results']['expiresAt'] ?? ''));

                    $this->SendJson([
                        'ok'        => true,
                        'expiresAt' => $data['results']['expiresAt'] ?? ''
                    ]);
                    return;
                case 'stop':
                    $this->RequireWebhookAuthForApi();
                    $deviceName = $this->ResolveRequestDeviceName();
                    $mediaSessionId = $_POST['mediaSessionId'] ?? '';

                    if ($mediaSessionId === '') {
                        $this->SendJson(['ok' => false, 'error' => 'Missing mediaSessionId'], 400);
                        return;
                    }

                    $url = 'https://smartdevicemanagement.googleapis.com/v1/' . $deviceName . ':executeCommand';
                    $body = [
                        'command' => 'sdm.devices.commands.CameraLiveStream.StopWebRtcStream',
                        'params'  => [
                            'mediaSessionId' => $mediaSessionId
                        ]
                    ];

                    $result = $this->GoogleRequest($url, 'POST', $body);

                    if ($result['httpCode'] !== 200) {
                        $raw = (string) ($result['response'] ?? '');

                        if (
                            ($result['httpCode'] === 400) &&
                            (strpos($raw, 'FAILED_PRECONDITION') !== false) &&
                            (strpos($raw, 'invalid session or user id mismatch') !== false)
                        ) {
                            $this->SetValue('StreamStatus', 'Stream stopped');
                            $this->SetValue('ExpiresAt', '');

                            $this->SendJson([
                                'ok'              => false,
                                'restartRequired' => true,
                                'error'           => 'Stream session is no longer valid',
                                'httpCode'        => $result['httpCode'],
                                'raw'             => $raw
                            ], 200);
                            return;
                        }

                        $this->SendJson([
                            'ok'       => false,
                            'error'    => 'StopWebRtcStream failed',
                            'httpCode' => $result['httpCode'],
                            'raw'      => $result['response']
                        ], 500);
                        return;
                    }

                    $this->SetValue('StreamStatus', 'Stream stopped');
                    $this->SetValue('ExpiresAt', '');
                    $this->SendJson(['ok' => true]);
                    return;
                default:
                    $this->SendJson([
                        'ok'    => false,
                        'error' => 'Unknown action'
                    ], 400);
                    return;
            }
        } catch (Throwable $e) {
            $this->SendJson([
                'ok'    => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function UpdateRelevantDeviceValues(array $devices): void
    {
        $variableCatalogJson = $this->ReadAttributeString('VariableCatalogJson');
        if (!is_string($variableCatalogJson) || $variableCatalogJson === '') {
            return;
        }

        $variableCatalog = json_decode($variableCatalogJson, true);
        if (!is_array($variableCatalog)) {
            return;
        }
        if (!is_array($variableCatalog)) {
            return;
        }

        foreach ($devices as $deviceName => $device) {
            $traits = $device['raw']['traits'] ?? [];
            if (!is_array($traits)) {
                continue;
            }

            $deviceCategoryIdent = $this->BuildDeviceCategoryIdent($deviceName);

            foreach ($traits as $traitName => $traitData) {
                if (!is_array($traitData)) {
                    continue;
                }

                $flat = $this->FlattenArray($traitData);
                foreach ($flat as $fieldPath => $value) {
                    if (!$this->IsRelevantAutoRefreshField($traitName, $fieldPath)) {
                        continue;
                    }

                    $varIdent = $this->BuildVariableIdent($deviceName, $traitName, $fieldPath);
                    $catalogKey = $deviceCategoryIdent . '__' . $varIdent;

                    if (!isset($variableCatalog[$catalogKey]['object_id'])) {
                        continue;
                    }

                    $varID = (int) $variableCatalog[$catalogKey]['object_id'];
                    if ($varID <= 0 || !IPS_VariableExists($varID)) {
                        continue;
                    }

                    $this->WriteValueToVariable($varID, $value);
                }
            }
        }
    }


    private function IsRelevantAutoRefreshField(string $traitName, string $fieldPath): bool
    {
        $fullKey = $traitName . '.' . $fieldPath;

        $relevantKeys = [
            'sdm.devices.traits.Temperature.ambientTemperatureCelsius',
            'sdm.devices.traits.Humidity.ambientHumidityPercent',
            'sdm.devices.traits.ThermostatMode.mode',
            'sdm.devices.traits.ThermostatTemperatureSetpoint.coolCelsius',
            'sdm.devices.traits.ThermostatTemperatureSetpoint.heatCelsius'
        ];

        return in_array($fullKey, $relevantKeys, true);
    }


    private function FetchDevices(): ?array
    {
        $this->WriteAttributeString('LastGoogleError', '');

        try {
            $token = $this->GetApiAccessToken();
        } catch (Throwable $e) {
            $msg = 'Access token unavailable: ' . $e->getMessage();
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return null;
        }

        if ($token === '') {
            $msg = 'Access token is empty';
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return null;
        }

        $enterpriseId = $this->DetectEnterpriseId();
        if ($enterpriseId === '') {
            $msg = 'EnterpriseID missing or unreadable from local OAuth config';
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return null;
        }

        $url = 'https://smartdevicemanagement.googleapis.com/v1/enterprises/' . $enterpriseId . '/devices';
        $result = $this->GoogleRequest($url, 'GET');

        if (($result['httpCode'] ?? 0) !== 200) {
            $raw = trim((string) ($result['response'] ?? ''));
            $httpCode = (int) ($result['httpCode'] ?? 0);

            $detail = 'HTTP ' . $httpCode;
            if ($raw !== '') {
                $detail .= ' - ' . $raw;
            }

            $msg = 'Devices request failed: ' . $detail;
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return null;
        }

        $json = json_decode((string) $result['response'], true);
        if (!is_array($json) || !isset($json['devices']) || !is_array($json['devices'])) {
            $msg = 'Devices response is not valid JSON or contains no devices array';
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return [];
        }

        $devices = [];
        foreach ($json['devices'] as $device) {
            $name = (string) ($device['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $traits = $device['traits'] ?? [];


            $label = trim((string) (($traits['sdm.devices.traits.Info']['customName'] ?? '') ?: ''));
            if ($label === '') {
                $label = (string) ($device['parentRelations'][0]['displayName'] ?? basename($name));
            }

            $devices[$name] = [
                'label' => $label,
                'type'  => (string) ($device['type'] ?? ''),
                'raw'   => $device
            ];
        }

        $this->WriteAttributeString('CachedDevicesJson', json_encode($devices));
        return $devices;
    }

    private function FetchSingleDevice(string $deviceName): ?array
    {
        $this->WriteAttributeString('LastGoogleError', '');

        try {
            $token = $this->GetApiAccessToken();
        } catch (Throwable $e) {
            $msg = 'Access token unavailable: ' . $e->getMessage();
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return null;
        }

        if ($token === '') {
            $msg = 'Access token is empty';
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return null;
        }

        if ($deviceName === '') {
            $msg = 'Device name is empty';
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return null;
        }

        $url = 'https://smartdevicemanagement.googleapis.com/v1/' . $deviceName;
        $result = $this->GoogleRequest($url, 'GET');

        if (($result['httpCode'] ?? 0) !== 200) {
            $raw = trim((string) ($result['response'] ?? ''));
            $httpCode = (int) ($result['httpCode'] ?? 0);

            $detail = 'HTTP ' . $httpCode;
            if ($raw !== '') {
                $detail .= ' - ' . $raw;
            }

            $msg = 'Single device request failed: ' . $detail;
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return null;
        }

        $device = json_decode((string) $result['response'], true);
        if (!is_array($device)) {
            $msg = 'Single device response is not valid JSON';
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return null;
        }

        $name = (string) ($device['name'] ?? '');
        if ($name === '') {
            $msg = 'Single device response contains no device name';
            $this->WriteAttributeString('LastGoogleError', $msg);
            $this->LogMessage($msg, KL_ERROR);
            return null;
        }

        $traits = $device['traits'] ?? [];
        $label = trim((string) (($traits['sdm.devices.traits.Info']['customName'] ?? '') ?: ''));
        if ($label === '') {
            $label = (string) ($device['parentRelations'][0]['displayName'] ?? basename($name));
        }

        return [
            'label' => $label,
            'type'  => (string) ($device['type'] ?? ''),
            'raw'   => $device
        ];
    }
    private function SyncDeviceStructure(array $devices): void
    {
        $devicesCategoryID = $this->EnsureDevicesCategory();

        $deviceCatalog = [];
        $knownDeviceCategoryIdents = [];
        $knownVariableCatalogKeys = [];
        $variableCatalogJson = $this->ReadAttributeString('VariableCatalogJson');
        if (!is_string($variableCatalogJson) || $variableCatalogJson === '') {
            $variableCatalog = [];
        } else {
            $variableCatalog = json_decode($variableCatalogJson, true);
            if (!is_array($variableCatalog)) {
                $variableCatalog = [];
            }
        }
        if (!is_array($variableCatalog)) {
            $variableCatalog = [];
        }

        foreach ($devices as $deviceName => $device) {
            $deviceCategoryID = $this->EnsureDeviceCategory($device, $devicesCategoryID);
            $deviceCategoryIdent = $this->BuildDeviceCategoryIdent($deviceName);
            $knownDeviceCategoryIdents[] = $deviceCategoryIdent;
            $deviceShortId = $this->GetDeviceShortId($deviceName);

            $deviceCatalog[$deviceName] = [
                'device_name'     => $deviceName,
                'device_id_short' => $deviceShortId,
                'device_type'     => (string) ($device['type'] ?? ''),
                'label'           => (string) ($device['label'] ?? $deviceShortId),
                'category_ident'  => $deviceCategoryIdent,
                'category_id'     => $deviceCategoryID
            ];

            $traits = $device['raw']['traits'] ?? [];
            if (!is_array($traits)) {
                continue;
            }

            foreach ($traits as $traitName => $traitData) {
                if (!is_array($traitData)) {
                    continue;
                }

                $flat = $this->FlattenArray($traitData);
                foreach ($flat as $fieldPath => $value) {
                    $varID = $this->EnsureDeviceVariable($deviceName, $deviceCategoryID, $traitName, $fieldPath, $value);
                    $varIdent = $this->BuildVariableIdent($deviceName, $traitName, $fieldPath);
                    $fullKey = $traitName . '.' . $fieldPath;
                    $writableDef = $this->GetWritableDefinition($traitName, $fieldPath);
                    $catalogKey = $deviceCategoryIdent . '__' . $varIdent;
                    $knownVariableCatalogKeys[] = $catalogKey;
                    $variableCatalog[$catalogKey] = [
                        'device_name'    => $deviceName,
                        'device_type'    => (string) ($device['type'] ?? ''),
                        'trait'          => $traitName,
                        'field_path'     => $fieldPath,
                        'full_key'       => $fullKey,
                        'variable_ident' => $varIdent,
                        'object_id'      => $varID,
                        'value_type'     => $this->DetectValueType($value),
                        'writable'       => $writableDef !== null,
                        'command_key'    => $writableDef['command_key'] ?? null
                    ];
                }
            }
        }
        $this->CleanupObsoleteDevices($devicesCategoryID, $knownDeviceCategoryIdents);
        $this->CleanupObsoleteVariables($variableCatalog, $knownVariableCatalogKeys);

        $this->WriteAttributeString('KnownDeviceCategoryIdentsJson', json_encode(array_values(array_unique($knownDeviceCategoryIdents))));
        $this->WriteAttributeString('KnownVariableCatalogKeysJson', json_encode(array_values(array_unique($knownVariableCatalogKeys))));
        $this->WriteAttributeString('DeviceCatalogJson', json_encode($deviceCatalog));
        $this->WriteAttributeString('VariableCatalogJson', json_encode($variableCatalog));
    }

    private function UpdateDeviceValues(array $devices): void
    {
        $variableCatalogJson = $this->ReadAttributeString('VariableCatalogJson');
        if (!is_string($variableCatalogJson) || $variableCatalogJson === '') {
            return;
        }

        $variableCatalog = json_decode($variableCatalogJson, true);
        if (!is_array($variableCatalog)) {
            return;
        }
        if (!is_array($variableCatalog)) {
            return;
        }

        foreach ($devices as $deviceName => $device) {
            $traits = $device['raw']['traits'] ?? [];
            if (!is_array($traits)) {
                continue;
            }

            $deviceCategoryIdent = $this->BuildDeviceCategoryIdent($deviceName);

            foreach ($traits as $traitName => $traitData) {
                if (!is_array($traitData)) {
                    continue;
                }

                $flat = $this->FlattenArray($traitData);
                foreach ($flat as $fieldPath => $value) {
                    $varIdent = $this->BuildVariableIdent($deviceName, $traitName, $fieldPath);
                    $catalogKey = $deviceCategoryIdent . '__' . $varIdent;

                    if (!isset($variableCatalog[$catalogKey]['object_id'])) {
                        continue;
                    }

                    $varID = (int) $variableCatalog[$catalogKey]['object_id'];
                    if ($varID <= 0 || !IPS_VariableExists($varID)) {
                        continue;
                    }

                    $this->WriteValueToVariable($varID, $value);
                }
            }
        }
    }

    private function UpdateSingleDeviceValues(string $deviceName, array $device): void
    {
        $variableCatalogJson = $this->ReadAttributeString('VariableCatalogJson');
        if (!is_string($variableCatalogJson) || $variableCatalogJson === '') {
            return;
        }

        $variableCatalog = json_decode($variableCatalogJson, true);
        if (!is_array($variableCatalog)) {
            return;
        }

        $traits = $device['raw']['traits'] ?? [];
        if (!is_array($traits)) {
            return;
        }

        $deviceCategoryIdent = $this->BuildDeviceCategoryIdent($deviceName);

        foreach ($traits as $traitName => $traitData) {
            if (!is_array($traitData)) {
                continue;
            }

            $flat = $this->FlattenArray($traitData);
            foreach ($flat as $fieldPath => $value) {
                $varIdent = $this->BuildVariableIdent($deviceName, $traitName, $fieldPath);
                $catalogKey = $deviceCategoryIdent . '__' . $varIdent;

                if (!isset($variableCatalog[$catalogKey]['object_id'])) {
                    continue;
                }

                $varID = (int) $variableCatalog[$catalogKey]['object_id'];
                if ($varID <= 0 || !IPS_VariableExists($varID)) {
                    continue;
                }

                $this->WriteValueToVariable($varID, $value);
            }
        }
    }


    private function CleanupObsoleteDevices(int $devicesCategoryID, array $knownDeviceCategoryIdents): void
    {
        $known = array_flip($knownDeviceCategoryIdents);
        $children = IPS_GetChildrenIDs($devicesCategoryID);

        foreach ($children as $childID) {
            $obj = IPS_GetObject($childID);
            $ident = (string) ($obj['ObjectIdent'] ?? '');

            if ($ident === '') {
                continue;
            }

            if (!isset($known[$ident])) {
                IPS_DeleteCategory($childID);
            }
        }
    }

    private function CleanupObsoleteVariables(array $variableCatalog, array $knownVariableCatalogKeys): void
    {
        $known = array_flip($knownVariableCatalogKeys);

        foreach ($variableCatalog as $catalogKey => $entry) {
            if (isset($known[$catalogKey])) {
                continue;
            }

            $objectID = (int) ($entry['object_id'] ?? 0);
            if ($objectID > 0 && IPS_ObjectExists($objectID)) {
                IPS_DeleteVariable($objectID);
            }

            unset($variableCatalog[$catalogKey]);
        }

        $this->WriteAttributeString('VariableCatalogJson', json_encode($variableCatalog));
    }
    private function EnsureDevicesCategory(): int
    {
        $ident = 'Devices';
        $existing = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($existing !== false) {
            return $existing;
        }

        $catID = IPS_CreateCategory();
        IPS_SetParent($catID, $this->InstanceID);
        IPS_SetIdent($catID, $ident);
        IPS_SetName($catID, 'Devices');
        return $catID;
    }

    private function EnsureDeviceCategory(array $device, int $parentID): int
    {
        $deviceName = (string) ($device['raw']['name'] ?? '');
        $label = $this->BuildDeviceDisplayName($device);
        $ident = $this->BuildDeviceCategoryIdent($deviceName);

        $existing = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($existing !== false) {
            IPS_SetName($existing, $label);
            return $existing;
        }

        $catID = IPS_CreateCategory();
        IPS_SetParent($catID, $parentID);
        IPS_SetIdent($catID, $ident);
        IPS_SetName($catID, $label);
        return $catID;
    }

    private function BuildDeviceDisplayName(array $device): string
    {
        $label = trim((string) ($device['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $type = (string) ($device['type'] ?? '');
        $shortId = $this->GetDeviceShortId((string) ($device['raw']['name'] ?? ''));

        if ($type !== '') {
            $parts = explode('.', $type);
            $typeShort = (string) end($parts);
            return $typeShort . ' ' . $shortId;
        }

        return $shortId;
    }

    private function BuildVariableDisplayName(string $traitName, string $fieldPath): string
    {
        $traitShort = $this->GetTraitShortName($traitName);
        $fieldLabel = str_replace(['.', '_'], ' ', $fieldPath);
        $fieldLabel = ucwords($fieldLabel);

        return $traitShort . ' - ' . $fieldLabel;
    }

    private function UpdateManagedActionScript(int $scriptID): void
    {
        $code = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "\$instanceID = " . $this->InstanceID . ";\n\n"
            . "if (!isset(\$_IPS['VARIABLE']) || !array_key_exists('VALUE', \$_IPS)) {\n"
            . "    IPS_LogMessage('NestCameraViewer_ActionProxy', 'Missing VARIABLE or VALUE in \$_IPS');\n"
            . "    return;\n"
            . "}\n\n"
            . "\$variableID = (int) \$_IPS['VARIABLE'];\n"
            . "\$value      = \$_IPS['VALUE'];\n\n"
            . "if (!IPS_VariableExists(\$variableID)) {\n"
            . "    IPS_LogMessage('NestCameraViewer_ActionProxy', 'Variable does not exist: ' . \$variableID);\n"
            . "    return;\n"
            . "}\n\n"
            . "if (!IPS_InstanceExists(\$instanceID)) {\n"
            . "    IPS_LogMessage('NestCameraViewer_ActionProxy', 'Instance does not exist: ' . \$instanceID);\n"
            . "    return;\n"
            . "}\n\n"
            . "\$ident = IPS_GetObject(\$variableID)['ObjectIdent'] ?? '';\n"
            . "if (!is_string(\$ident) || \$ident === '') {\n"
            . "    IPS_LogMessage('NestCameraViewer_ActionProxy', 'Variable has no ObjectIdent: ' . \$variableID);\n"
            . "    return;\n"
            . "}\n\n"
            . "IPS_LogMessage(\n"
            . "    'NestCameraViewer_ActionProxy',\n"
            . "    'INPUT: instance=' . \$instanceID . ' variable=' . \$variableID . ' ident=' . \$ident . ' type=' . gettype(\$value) . ' value=' . json_encode(\$value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)\n"
            . ");\n\n"
            . "try {\n"
            . "    IPS_RequestAction(\$instanceID, \$ident, \$value);\n"
            . "    IPS_LogMessage(\n"
            . "        'NestCameraViewer_ActionProxy',\n"
            . "        'OK: instance=' . \$instanceID . ' variable=' . \$variableID . ' ident=' . \$ident . ' value=' . json_encode(\$value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)\n"
            . "    );\n"
            . "} catch (Throwable \$e) {\n"
            . "    IPS_LogMessage(\n"
            . "        'NestCameraViewer_ActionProxy',\n"
            . "        'ERROR: instance=' . \$instanceID . ' variable=' . \$variableID . ' ident=' . \$ident . ' message=' . \$e->getMessage()\n"
            . "    );\n"
            . "    throw \$e;\n"
            . "}\n";

        IPS_SetScriptContent($scriptID, $code);
    }

    private function EnsureManagedActionScript(): int
    {
        $storedID = (int) $this->ReadAttributeInteger('ManagedActionScriptID');
        if ($storedID > 0 && IPS_ScriptExists($storedID)) {
            $this->UpdateManagedActionScript($storedID);
            return $storedID;
        }

        $scriptID = IPS_CreateScript(0);
        IPS_SetParent($scriptID, $this->InstanceID);
        IPS_SetIdent($scriptID, 'ManagedActionProxy');
        IPS_SetName($scriptID, 'Managed Action Proxy');
        IPS_SetHidden($scriptID, true);

        $this->UpdateManagedActionScript($scriptID);
        $this->WriteAttributeInteger('ManagedActionScriptID', $scriptID);

        return $scriptID;
    }

    private function EnsureDeviceVariable(string $deviceName, int $parentID, string $traitName, string $fieldPath, $value): int
    {
        $ident = $this->BuildVariableIdent($deviceName, $traitName, $fieldPath);
        $existing = @IPS_GetObjectIDByIdent($ident, $parentID);

        $type = $this->DetectValueType($value);
        $variableType = $this->MapValueTypeToIPS($type);
        $displayName = $this->BuildVariableDisplayName($traitName, $fieldPath);
        $writableDef = $this->GetWritableDefinition($traitName, $fieldPath);
        $managedActionScriptID = (int) $this->ReadAttributeInteger('ManagedActionScriptID');

        if ($existing !== false) {
            IPS_SetName($existing, $displayName);
            $this->ApplyVariableProfile($existing, $traitName, $fieldPath, $type);

            if ($writableDef !== null && $managedActionScriptID > 0 && IPS_ScriptExists($managedActionScriptID)) {
                IPS_SetVariableCustomAction($existing, $managedActionScriptID);
            } else {
                IPS_SetVariableCustomAction($existing, 0);
            }

            return $existing;
        }

        switch ($variableType) {
            case VARIABLETYPE_BOOLEAN:
                $varID = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
                break;
            case VARIABLETYPE_INTEGER:
                $varID = IPS_CreateVariable(VARIABLETYPE_INTEGER);
                break;
            case VARIABLETYPE_FLOAT:
                $varID = IPS_CreateVariable(VARIABLETYPE_FLOAT);
                break;
            default:
                $varID = IPS_CreateVariable(VARIABLETYPE_STRING);
                break;
        }

        IPS_SetParent($varID, $parentID);
        IPS_SetIdent($varID, $ident);
        IPS_SetName($varID, $displayName);

        $this->ApplyVariableProfile($varID, $traitName, $fieldPath, $type);

        if ($writableDef !== null && $managedActionScriptID > 0 && IPS_ScriptExists($managedActionScriptID)) {
            IPS_SetVariableCustomAction($varID, $managedActionScriptID);
        } else {
            IPS_SetVariableCustomAction($varID, 0);
        }

        return $varID;
    }

    private function WriteValueToVariable(int $varID, $value): void
    {
        $var = IPS_GetVariable($varID);
        $type = $var['VariableType'];

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        switch ($type) {
            case VARIABLETYPE_BOOLEAN:
                SetValueBoolean($varID, (bool) $value);
                break;
            case VARIABLETYPE_INTEGER:
                SetValueInteger($varID, (int) $value);
                break;
            case VARIABLETYPE_FLOAT:
                SetValueFloat($varID, (float) $value);
                break;
            default:
                SetValueString($varID, (string) $value);
                break;
        }
    }

    private function GetDeviceShortId(string $deviceName): string
    {
        $parts = explode('/devices/', $deviceName);
        return $parts[1] ?? md5($deviceName);
    }

    private function BuildDeviceCategoryIdent(string $deviceName): string
    {
        return 'Device_' . $this->SanitizeIdent($this->GetDeviceShortId($deviceName));
    }

    private function GetTraitShortName(string $traitName): string
    {
        $parts = explode('.', $traitName);
        return (string) (end($parts) ?: $traitName);
    }

    private function BuildVariableIdent(string $deviceName, string $traitName, string $fieldPath): string
    {
        $deviceShort = $this->GetDeviceShortId($deviceName);
        $traitShort = $this->GetTraitShortName($traitName);
        $fieldPart = str_replace(['.', ' '], '_', $fieldPath);

        return $this->SanitizeIdent($deviceShort . '__' . $traitShort . '_' . $fieldPart);
    }





    private function SanitizeIdent(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_]/', '_', $value);
        $value = preg_replace('/_+/', '_', (string) $value);
        return trim((string) $value, '_');
    }

    private function FlattenArray(array $input, string $prefix = ''): array
    {
        $result = [];

        foreach ($input as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                if ($this->IsAssoc($value)) {
                    $result += $this->FlattenArray($value, $path);
                } else {
                    $result[$path] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            } else {
                $result[$path] = $value;
            }
        }

        return $result;
    }

    private function IsAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function DetectValueType($value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'float';
        }
        return 'string';
    }

    private function MapValueTypeToIPS(string $type): int
    {
        switch ($type) {
            case 'boolean':
                return VARIABLETYPE_BOOLEAN;
            case 'integer':
                return VARIABLETYPE_INTEGER;
            case 'float':
                return VARIABLETYPE_FLOAT;
            default:
                return VARIABLETYPE_STRING;
        }
    }

    private function ApplyVariableProfile(int $varID, string $traitName, string $fieldPath, string $type): void
    {
        $profile = '';

        if ($fieldPath === 'ambientHumidityPercent') {
            if ($type === 'integer') {
                $profile = '~Humidity';
            } elseif ($type === 'float') {
                $profile = '~Humidity.F';
            }
        }

        if (
            $fieldPath === 'ambientTemperatureCelsius' ||
            $fieldPath === 'coolCelsius' ||
            $fieldPath === 'heatCelsius'
        ) {
            if ($type === 'float') {
                $profile = '~Temperature';
            }
        }
        if ($traitName === 'sdm.devices.traits.ThermostatMode' && $fieldPath === 'mode') {
            $profileName = 'NESTCAM.ThermostatMode';
            if (!IPS_VariableProfileExists($profileName)) {
                IPS_CreateVariableProfile($profileName, VARIABLETYPE_STRING);
                IPS_SetVariableProfileAssociation($profileName, 'HEAT', 'HEAT', '', -1);
                IPS_SetVariableProfileAssociation($profileName, 'COOL', 'COOL', '', -1);
                IPS_SetVariableProfileAssociation($profileName, 'HEATCOOL', 'HEATCOOL', '', -1);
                IPS_SetVariableProfileAssociation($profileName, 'OFF', 'OFF', '', -1);
            }
            $profile = $profileName;
        }
        if ($profile !== '') {
            IPS_SetVariableCustomProfile($varID, $profile);
        } else {
            IPS_SetVariableCustomProfile($varID, '');
        }
    }

    private function GetWritableDefinition(string $traitName, string $fieldPath): ?array
    {
        $key = $traitName . '.' . $fieldPath;
        return self::WRITABLE_FIELDS[$key] ?? null;
    }


    private function GetCachedDevices(): array
    {
        $cachedDevicesJson = $this->ReadAttributeString('CachedDevicesJson');
        if (!is_string($cachedDevicesJson) || $cachedDevicesJson === '') {
            $devices = [];
        } else {
            $devices = json_decode($cachedDevicesJson, true);
            if (!is_array($devices)) {
                $devices = [];
            }
        }
        if (!is_array($devices) || count($devices) === 0) {
            $devices = $this->FetchDevices();
        }

        return is_array($devices) ? $devices : [];
    }

    private function ResolveSelectedDeviceName(array $devices): string
    {
        $selected = $this->ReadPropertyString('SelectedDeviceName');

        if ($selected === '__ALL__') {
            return '__ALL__';
        }

        if ($selected !== '' && isset($devices[$selected])) {
            return $selected;
        }

        return (string) array_key_first($devices);
    }

    private function ResolveRequestDeviceName(): string
    {
        $devices = $this->GetCachedDevices();

        $requested = $_POST['deviceName'] ?? $_GET['deviceName'] ?? '';
        if ($requested !== '' && isset($devices[$requested])) {
            return $requested;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        if (preg_match('#^/hook/([^/?]+)$#', $path, $m)) {
            $calledHookName = (string) $m[1];
            $hookDeviceMapJson = $this->ReadAttributeString('HookDeviceMapJson');
            if (!is_string($hookDeviceMapJson) || $hookDeviceMapJson === '') {
                $hookDeviceMap = [];
            } else {
                $hookDeviceMap = json_decode($hookDeviceMapJson, true);
                if (!is_array($hookDeviceMap)) {
                    $hookDeviceMap = [];
                }
            }

            if (is_array($hookDeviceMap) && isset($hookDeviceMap[$calledHookName])) {
                $mappedDevice = (string) $hookDeviceMap[$calledHookName];
                if (isset($devices[$mappedDevice])) {
                    return $mappedDevice;
                }
            }
        }

        $selected = $this->ResolveSelectedDeviceName($devices);
        if ($selected === '__ALL__') {
            return (string) array_key_first($devices);
        }

        return $selected;
    }



    private function LoadOAuthStaticConfig(): array
    {
        $vaultID = $this->ReadPropertyInteger('VaultInstanceID');
        if ($vaultID <= 0) {
            throw new Exception('VaultInstanceID is not configured');
        }

        $path = trim($this->ReadPropertyString('OAuthConnectionIdent'));
        if ($path === '') {
            throw new Exception('OAuthConnectionIdent is not configured');
        }

        $parts = explode('/', $path);
        if (count($parts) < 2) {
            throw new Exception('OAuthConnectionIdent must include at least one folder and one record');
        }

        $rootIdent = array_shift($parts);

        // local records are written under __LOCAL__
        $json = SEC_GetSecret($vaultID, '__LOCAL__');
        if ($json === '') {
            throw new Exception('Vault returned empty local data');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new Exception('Local vault data is not valid JSON');
        }

        $node = $data;
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                throw new Exception('Local OAuth record not found: ' . $path);
            }

            $node = $node[$segment];
        }

        return $this->NormalizeOAuthStaticConfig($node);
    }

    private function NormalizeOAuthStaticConfig(array $data): array
    {
        return [
            'ConnectionName' => (string) ($data['ConnectionName'] ?? ''),
            'ProjectID'      => (string) ($data['ProjectID'] ?? ''),
            'EnterpriseID'   => (string) ($data['EnterpriseID'] ?? ''),
            'ClientID'       => (string) ($data['ClientID'] ?? ''),
            'ClientSecret'   => (string) ($data['ClientSecret'] ?? ''),
            'RedirectURI'    => (string) ($data['RedirectURI'] ?? ''),
            'Scope'          => (string) ($data['Scope'] ?? 'https://www.googleapis.com/auth/sdm.service'),
            'GoogleAccount'  => (string) ($data['GoogleAccount'] ?? '')
        ];
    }

    public function GetVariableCatalog(): string
    {
        return $this->ReadAttributeString('VariableCatalogJson');
    }

    public function GetDeviceCatalog(): string
    {
        return $this->ReadAttributeString('DeviceCatalogJson');
    }


    public function RequestAction($Ident, $Value): void
    {
        $variableCatalogJson = $this->ReadAttributeString('VariableCatalogJson');
        if (!is_string($variableCatalogJson) || $variableCatalogJson === '') {
            throw new Exception('Variable catalog is invalid');
        }

        $variableCatalog = json_decode($variableCatalogJson, true);
        if (!is_array($variableCatalog)) {
            throw new Exception('Variable catalog is invalid');
        }

        $entry = null;
        foreach ($variableCatalog as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if ((string) ($candidate['variable_ident'] ?? '') === $Ident) {
                $entry = $candidate;
                break;
            }
        }

        if ($entry === null) {
            throw new Exception('Unknown variable ident: ' . $Ident);
        }

        if (!($entry['writable'] ?? false)) {
            throw new Exception('Variable is read-only: ' . $Ident);
        }

        $deviceName = (string) ($entry['device_name'] ?? '');
        $commandKey = (string) ($entry['command_key'] ?? '');
        $traitName  = (string) ($entry['trait'] ?? '');
        $fieldPath  = (string) ($entry['field_path'] ?? '');

        if ($deviceName === '' || $commandKey === '') {
            throw new Exception('Writable mapping is incomplete for: ' . $Ident);
        }

        $payload = $this->BuildCommandPayload($deviceName, $commandKey, $Value);

        $result = $this->GoogleRequest(
            'https://smartdevicemanagement.googleapis.com/v1/' . $deviceName . ':executeCommand',
            'POST',
            $payload
        );

        if (($result['httpCode'] ?? 0) !== 200) {
            throw new Exception('Command failed: ' . (string) ($result['response'] ?? ''));
        }

        $objectID = (int) ($entry['object_id'] ?? 0);
        if ($objectID > 0 && IPS_VariableExists($objectID)) {
            $this->WriteValueToVariable($objectID, $Value);
        }

        $this->LogMessage(
            'RequestAction success ident=' . $Ident .
                ' trait=' . $traitName .
                ' field=' . $fieldPath .
                ' value=' . json_encode($Value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            KL_MESSAGE
        );
    }
    public function SaveOAuthSetupToLocalVault(): void
    {
        $vaultID = $this->ReadPropertyInteger('VaultInstanceID');
        if ($vaultID <= 0) {
            throw new Exception('VaultInstanceID is not configured');
        }

        if (!function_exists('SEC_SetRecordFields')) {
            throw new Exception('SEC_SetRecordFields() is not available');
        }

        $path = trim($this->ReadPropertyString('OAuthConnectionIdent'));
        if ($path === '') {
            throw new Exception('OAuthConnectionIdent is not configured');
        }

        $fields = [
            'ConnectionName' => $this->ReadPropertyString('OAuthSetupConnectionName'),
            'ProjectID'      => $this->ReadPropertyString('OAuthSetupProjectID'),
            'EnterpriseID'   => $this->ReadPropertyString('OAuthSetupEnterpriseID'),
            'ClientID'       => $this->ReadPropertyString('OAuthSetupClientID'),
            'ClientSecret'   => $this->ReadPropertyString('OAuthSetupClientSecret'),
            'RedirectURI'    => $this->ReadPropertyString('OAuthSetupRedirectURI'),
            'Scope'          => $this->ReadPropertyString('OAuthSetupScope'),
            'GoogleAccount'  => ''
        ];

        $ok = SEC_SetRecordFields($vaultID, $path, $fields, 'local');
        if (!$ok) {
            throw new Exception('Saving local OAuth setup to vault failed');
        }

        echo 'Local OAuth setup saved to vault';
    }

    public function GenerateAuthorizationURL(): void
    {
        if (!$this->ReadPropertyBoolean('IsTokenMaster')) {
            throw new Exception('Only the token master may generate the authorization URL');
        }

        $oauth = $this->LoadOAuthStaticConfig();

        $projectId   = trim($oauth['ProjectID']);
        $clientId    = trim($oauth['ClientID']);
        $redirectUri = trim($oauth['RedirectURI']);
        $scope       = trim($oauth['Scope']);

        if ($projectId === '' || $clientId === '' || $redirectUri === '' || $scope === '') {
            throw new Exception('Local OAuth setup is incomplete');
        }

        if (!str_contains($clientId, '.apps.googleusercontent.com')) {
            throw new Exception('OAuth ClientID in local vault is incomplete. The full Google Client ID including .apps.googleusercontent.com is required.');
        }

        $state = bin2hex(random_bytes(16));
        $this->WriteAttributeString('OAuthLastState', $state);

        $params = [
            'redirect_uri'  => $redirectUri,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'client_id'     => $clientId,
            'response_type' => 'code',
            'scope'         => $scope,
            'state'         => $state
        ];

        $authUrl =
            'https://nestservices.google.com/partnerconnections/' .
            rawurlencode($projectId) .
            '/auth?' .
            http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $this->SetValue('AuthorizationURL', $authUrl);

        echo $authUrl;
    }

    public function ExchangeAuthorizationCode(): void
    {
        if (!$this->ReadPropertyBoolean('IsTokenMaster')) {
            throw new Exception('Only the token master may exchange the authorization code');
        }

        $oauth = $this->LoadOAuthStaticConfig();

        $clientId     = trim($oauth['ClientID']);
        $clientSecret = trim($oauth['ClientSecret']);
        $redirectUri  = trim($oauth['RedirectURI']);

        $authorizationInput = trim($this->ReadPropertyString('BootstrapAuthorizationCode'));
        $authorizationCode  = $authorizationInput;

        // Wenn eine komplette URL eingefügt wurde, den code-Parameter extrahieren
        if (str_starts_with($authorizationInput, 'http://') || str_starts_with($authorizationInput, 'https://')) {
            $query = parse_url($authorizationInput, PHP_URL_QUERY);
            if (!is_string($query) || $query === '') {
                throw new Exception('No query string found in bootstrap URL');
            }

            parse_str($query, $params);
            $authorizationCode = isset($params['code']) ? trim((string) $params['code']) : '';

            if ($authorizationCode === '') {
                throw new Exception('No authorization code found in bootstrap URL');
            }
        }

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '' || $authorizationCode === '') {
            throw new Exception('ClientID, ClientSecret, RedirectURI and BootstrapAuthorizationCode are required');
        }

        $postFields = http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $authorizationCode,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlErr !== '') {
            throw new Exception('Token exchange cURL error: ' . $curlErr);
        }

        $json = json_decode($response, true);
        if (!is_array($json) || $httpCode !== 200) {
            throw new Exception('Token exchange failed: ' . $response);
        }

        $refreshToken = (string) ($json['refresh_token'] ?? '');
        $accessToken  = (string) ($json['access_token'] ?? '');
        $expiresIn    = (int) ($json['expires_in'] ?? 3600);

        if ($refreshToken === '') {
            throw new Exception('Token exchange returned no refresh token');
        }

        $this->StoreMasterTokens($refreshToken, $accessToken, $expiresIn);

        echo 'Authorization code exchanged successfully';
    }

    private function BuildCommandPayload(string $deviceName, string $commandKey, $value): array
    {
        switch ($commandKey) {
            case 'ThermostatMode.SetMode':
                return [
                    'command' => 'sdm.devices.commands.ThermostatMode.SetMode',
                    'params'  => [
                        'mode' => (string) $value
                    ]
                ];

            case 'ThermostatTemperatureSetpoint.SetCool':
                return [
                    'command' => 'sdm.devices.commands.ThermostatTemperatureSetpoint.SetCool',
                    'params'  => [
                        'coolCelsius' => (float) $value
                    ]
                ];

            case 'ThermostatTemperatureSetpoint.SetHeat':
                return [
                    'command' => 'sdm.devices.commands.ThermostatTemperatureSetpoint.SetHeat',
                    'params'  => [
                        'heatCelsius' => (float) $value
                    ]
                ];

            default:
                throw new Exception('Unsupported command key: ' . $commandKey);
        }
    }

    private function StoreMasterTokens(string $refreshToken, string $accessToken, int $expiresIn): void
    {
        $expiresAt = time() + $expiresIn;

        $this->SetValue('RefreshToken', $refreshToken);
        $this->SetValue('AccessToken', $accessToken);
        $this->SetValue('AccessTokenExpiresAt', date('c', $expiresAt));

        $this->WriteAttributeString('OAuthBootstrapCompleted', '1');
    }

    private function GetApiAccessToken(): string
    {
        if ($this->ReadPropertyBoolean('IsTokenMaster')) {
            return $this->GetMasterAccessToken();
        }

        return $this->GetExternalAccessToken();
    }


    private function GetExternalAccessToken(): string
    {
        $varID = $this->ReadPropertyInteger('ExternalAccessTokenVariableID');
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            throw new Exception('External access token variable is not configured');
        }

        $token = trim((string) GetValue($varID));

        $this->LogMessage(
            'XXX_GetExternalAccessToken varID=' . $varID .
                ' len=' . strlen($token) .
                ' sha1=' . sha1($token),
            KL_MESSAGE
        );

        if ($token === '') {
            throw new Exception('External access token variable is empty');
        }

        return $token;
    }

    private function GetMasterRefreshToken(): string
    {
        $token = trim((string) GetValue($this->GetIDForIdent('RefreshToken')));
        if ($token === '') {
            throw new Exception('No local refresh token available');
        }

        return $token;
    }

    private function GetMasterAccessToken(): string
    {
        $accessToken = trim((string) GetValue($this->GetIDForIdent('AccessToken')));
        $expiresText = trim((string) GetValue($this->GetIDForIdent('AccessTokenExpiresAt')));
        $expiresAt = $expiresText !== '' ? strtotime($expiresText) : 0;
        $now = time();

        $refreshToken = $this->GetMasterRefreshToken();

        $this->LogMessage(
            'XXX_GetMasterAccessToken len=' . strlen($accessToken) .
                ' sha1=' . sha1($accessToken) .
                ' expires=' . $expiresText,
            KL_MESSAGE
        );

        if ($refreshToken === '') {
            throw new Exception('OAuth bootstrap required: no local refresh token');
        }

        if ($accessToken === '' || $expiresAt === false || $expiresAt <= ($now + 300)) {
            $accessToken = $this->RefreshMasterAccessToken();

            $this->LogMessage(
                'XXX_GetMasterAccessToken refreshed len=' . strlen($accessToken) .
                    ' sha1=' . sha1($accessToken),
                KL_MESSAGE
            );
        }

        if ($accessToken === '') {
            throw new Exception('Unable to obtain valid access token');
        }

        return $accessToken;
    }


    private function RefreshMasterAccessToken(): string
    {
        $oauth = $this->LoadOAuthStaticConfig();

        $clientId = trim($oauth['ClientID']);
        $clientSecret = trim($oauth['ClientSecret']);
        $refreshToken = $this->GetMasterRefreshToken();

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new Exception('OAuth refresh requires ClientID, ClientSecret and local RefreshToken');
        }

        $postFields = http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token'
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlErr !== '') {
            throw new Exception('OAuth refresh cURL error: ' . $curlErr);
        }

        $json = json_decode($response, true);
        if (!is_array($json) || $httpCode !== 200) {
            throw new Exception('OAuth refresh failed: ' . $response);
        }

        $accessToken = (string) ($json['access_token'] ?? '');
        $expiresIn = (int) ($json['expires_in'] ?? 3600);

        if ($accessToken === '') {
            throw new Exception('OAuth refresh returned no access token');
        }

        $expiresAt = time() + $expiresIn;

        $this->SetValue('AccessToken', $accessToken);
        $this->SetValue('AccessTokenExpiresAt', date('c', $expiresAt));

        return $accessToken;
    }



    private function DetectEnterpriseId(): string
    {
        try {
            $oauth = $this->LoadOAuthStaticConfig();
            return trim((string) $oauth['EnterpriseID']);
        } catch (Throwable $e) {
            return '';
        }
    }



    private function GoogleRequest(string $url, string $method, ?array $body = null): array
    {
        $token = $this->GetApiAccessToken();

        $this->LogMessage(
            'XXX_GoogleRequest method=' . $method .
                ' url=' . $url .
                ' token_len=' . strlen($token) .
                ' token_sha1=' . sha1($token),
            KL_MESSAGE
        );

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST  => $method
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);

            $this->LogMessage(
                'XXX_GoogleRequest body=' . json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                KL_MESSAGE
            );
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->LogMessage(
                'XXX_GoogleRequest FAILED method=' . $method .
                    ' url=' . $url .
                    ' curlErr=' . $curlErr,
                KL_ERROR
            );

            return [
                'httpCode' => 0,
                'response' => $curlErr,
                'curlErr'  => $curlErr
            ];
        }

        $this->LogMessage(
            'XXX_GoogleRequest response method=' . $method .
                ' url=' . $url .
                ' httpCode=' . $httpCode .
                ' response=' . $response,
            $httpCode === 200 ? KL_MESSAGE : KL_ERROR
        );

        return [
            'httpCode' => $httpCode,
            'response' => $response,
            'curlErr'  => $curlErr
        ];
    }

    private function ReadPostedOfferSdp(): string
    {
        $offerSdp = $_POST['offerSdp'] ?? '';
        if ($offerSdp !== '') {
            return $offerSdp;
        }

        $raw = @file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            parse_str($raw, $parsedRaw);
            if (is_array($parsedRaw) && isset($parsedRaw['offerSdp'])) {
                return (string) $parsedRaw['offerSdp'];
            }
        }

        return '';
    }

    private function SummarizeSdp(string $sdp): string
    {
        $lines = preg_split('/\r\n|\n/', $sdp);
        if (!is_array($lines)) {
            return '';
        }

        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (
                str_starts_with($line, 'm=') ||
                str_starts_with($line, 'a=group:') ||
                str_starts_with($line, 'a=mid:') ||
                str_starts_with($line, 'a=sctp-port:') ||
                $line === 'a=sendrecv' ||
                $line === 'a=recvonly' ||
                $line === 'a=sendonly' ||
                $line === 'a=inactive'
            ) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    private function GetWebhookLoginUrl(): string
    {
        $vaultID = $this->ReadPropertyInteger('VaultInstanceID');
        if ($vaultID <= 0) {
            throw new Exception('VaultInstanceID is not configured');
        }

        $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
        return '/hook/secrets_' . $vaultID . '?portal=1&return=' . urlencode($currentUrl);
    }

    private function IsWebhookAuthenticated(): bool
    {
        $authMode = $this->ReadPropertyInteger('AuthMode');
        if ($authMode === 0) {
            return true;
        }

        $vaultID = $this->ReadPropertyInteger('VaultInstanceID');
        if ($vaultID <= 0) {
            throw new Exception('VaultInstanceID is not configured');
        }

        if (!function_exists('SEC_IsPortalAuthenticated')) {
            throw new Exception('SecretsManager functions are not available');
        }

        return SEC_IsPortalAuthenticated($vaultID);
    }

    private function EnforceWebhookAuth(): void
    {
        $authMode = $this->ReadPropertyInteger('AuthMode');
        if ($authMode === 0) {
            return;
        }

        if (!$this->IsWebhookAuthenticated()) {
            header('Location: ' . $this->GetWebhookLoginUrl());
            exit;
        }
    }

    private function RequireWebhookAuthForApi(): void
    {
        $authMode = $this->ReadPropertyInteger('AuthMode');
        if ($authMode === 0) {
            return;
        }

        $vaultID = $this->ReadPropertyInteger('VaultInstanceID');
        if ($vaultID <= 0) {
            throw new Exception('VaultInstanceID is not configured');
        }

        if (!function_exists('SEC_IsPortalAuthenticated')) {
            throw new Exception('SecretsManager functions are not available');
        }

        if (!SEC_IsPortalAuthenticated($vaultID)) {
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
            $loginUrl = '/hook/secrets_' . $vaultID . '?portal=1&return=' . urlencode($currentUrl);
            header('Location: ' . $loginUrl);
            exit;
        }
    }

    private function RenderViewerHtml(?string $forcedDeviceName = null): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        $baseHookName = $this->NormalizeHookName($this->ReadPropertyString('HookName'));
        $hookPath = '/hook/' . $baseHookName;
        $showDebug = $this->ReadPropertyBoolean('Debug') ? 'true' : 'false';
        $autoExtend = $this->ReadPropertyBoolean('AutoExtend') ? 'true' : 'false';

        $devices = $this->GetCachedDevices();
        $selectedDeviceName = $forcedDeviceName ?? $this->ReadPropertyString('SelectedDeviceName');

        $hookDeviceMap = json_decode($this->ReadAttributeString('HookDeviceMapJson'), true);
        if (!is_array($hookDeviceMap)) {
            $hookDeviceMap = [];
        }

        if ($forcedDeviceName === null && preg_match('#^/hook/([^/?]+)$#', $path, $m)) {
            $calledHookName = (string) $m[1];
            if (isset($hookDeviceMap[$calledHookName])) {
                $selectedDeviceName = (string) $hookDeviceMap[$calledHookName];
                $hookPath = '/hook/' . $calledHookName;
            }
        }

        $allMode = ($selectedDeviceName === '__ALL__');
        $cameraLinksHtml = '';
        $allModeDisplay = $allMode ? 'block' : 'none';

        if ($allMode) {
            $links = [];
            foreach ($hookDeviceMap as $hook => $deviceName) {
                $label = $devices[$deviceName]['label'] ?? $hook;
                $url = '/hook/' . $hook;
                $links[] = '<div><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" style="color:#9cf;text-decoration:none;">' .
                    htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') .
                    '</a></div>';
            }
            $cameraLinksHtml = implode('', $links);
        }

        return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Nest Camera Viewer</title>
  <style>
    html, body {
      margin: 0;
      padding: 0;
      width: 100%;
      height: 100%;
      background: #000;
      overflow: hidden;
      font-family: Arial, sans-serif;
    }
    #wrap {
      position: relative;
      width: 100%;
      height: 100%;
      background: #000;
    }
    video {
      width: 100%;
      height: 100%;
      object-fit: contain;
      background: #000;
      display: block;
    }
    #debugBox {
      display: none;
      position: absolute;
      left: 10px;
      right: 10px;
      bottom: 10px;
      max-height: 35%;
      overflow: auto;
      white-space: pre-wrap;
      font-size: 12px;
      color: #eee;
      background: rgba(0,0,0,0.75);
      border: 1px solid #444;
      padding: 8px;
      box-sizing: border-box;
    }
  </style>
</head>
<body>
  <div id="wrap">
    <div id="allModeLinks" style="display: {$allModeDisplay}; padding: 12px; color:#eee;">
      {$cameraLinksHtml}
    </div>
    <video id="video" autoplay playsinline muted></video>
    <div id="debugBox"></div>
  </div>

  <script>
    const backendBaseUrl = '{$hookPath}';
    const initialDebug = {$showDebug};
    const autoExtendEnabled = {$autoExtend};
    const selectedDeviceName = '{$selectedDeviceName}';
    const allMode = selectedDeviceName === '__ALL__';

    const videoEl = document.getElementById('video');
    const debugBox = document.getElementById('debugBox');

    if (allMode) {
      videoEl.style.display = 'none';
    }

    let pc = null;
    let currentMediaSessionId = '';
    let extendTimer = null;
    let lastBackendData = null;
    let logLines = [];

    function setDebug(msg) {
      if (!initialDebug) {
        return;
      }

      debugBox.style.display = 'block';
      logLines.push(msg);
      if (logLines.length > 20) {
        logLines.shift();
      }
      debugBox.textContent = logLines.join('\\n\\n');
      console.log(msg);
    }

    function summarizeSdp(sdp) {
      if (!sdp) return '(empty)';
      const lines = sdp.split(/\\r\\n|\\n/).filter(Boolean);
      return lines.filter(line =>
        line.startsWith('m=') ||
        line === 'a=sendrecv' ||
        line === 'a=recvonly' ||
        line === 'a=sendonly' ||
        line === 'a=inactive' ||
        line.startsWith('a=group:') ||
        line.startsWith('a=mid:') ||
        line.startsWith('a=sctp-port:')
      ).join('\\n');
    }

    function mungeNestAnswerSdp(answerSdp) {
      const sections = answerSdp.split(/\\r\\nm=/);
      if (sections.length === 0) {
        return answerSdp;
      }

      const rebuilt = sections.map((section, index) => {
        let s = index === 0 ? section : 'm=' + section;

        const isAudio = s.includes('\\nm=audio') || s.startsWith('m=audio');
        const isVideo = s.includes('\\nm=video') || s.startsWith('m=video');

        if (isAudio || isVideo) {
          s = s.replace(/\\r?\\na=sendrecv(\\r?\\n)/g, '\\r\\na=sendonly$1');
        }

        return s;
      });

      return rebuilt.join('\\r\\n');
    }

    async function parseJsonResponse(res) {
      const text = await res.text();

      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        throw new Error('Backend did not return JSON: ' + text);
      }

      if (!data.ok) {
        throw new Error(JSON.stringify(data, null, 2));
      }

      return data;
    }

    async function callBackendGet(action) {
      let url = backendBaseUrl + '?action=' + encodeURIComponent(action);
      if (selectedDeviceName && !allMode) {
        url += '&deviceName=' + encodeURIComponent(selectedDeviceName);
      }

      const res = await fetch(url, {
        method: 'GET',
        cache: 'no-store',
        credentials: 'same-origin'
      });

      return await parseJsonResponse(res);
    }

    async function callBackendPost(payload) {
      const body = new URLSearchParams();
      Object.keys(payload).forEach((key) => body.append(key, payload[key]));

      if (selectedDeviceName && !payload.deviceName && !allMode) {
        body.append('deviceName', selectedDeviceName);
      }

      const res = await fetch(backendBaseUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString(),
        credentials: 'same-origin'
      });

      return await parseJsonResponse(res);
    }

    async function ensureAuthenticated() {
      const data = await callBackendGet('authcheck');
      if (!data.authenticated) {
        window.location.href = data.loginUrl;
        return false;
      }
      return true;
    }

    async function waitForIceComplete(peer) {
      await new Promise((resolve) => {
        if (peer.iceGatheringState === 'complete') {
          resolve();
          return;
        }

        function checkState() {
          if (peer.iceGatheringState === 'complete') {
            peer.removeEventListener('icegatheringstatechange', checkState);
            resolve();
          }
        }

        peer.addEventListener('icegatheringstatechange', checkState);
      });
    }

    function clearExtendTimer() {
      if (extendTimer) {
        clearInterval(extendTimer);
        extendTimer = null;
      }
    }

    function startExtendTimer() {
      if (!autoExtendEnabled) {
        return;
      }

      clearExtendTimer();

      extendTimer = setInterval(async () => {
        if (!currentMediaSessionId) {
          return;
        }

        try {
          const data = await callBackendPost({
            action: 'extend',
            mediaSessionId: currentMediaSessionId
          });
          setDebug('Extended stream. New expiry: ' + (data.expiresAt || 'unknown'));
        } catch (e) {
          setDebug('Auto-extend failed: ' + e.message);
        }
      }, 240000);
    }

    async function stopStream() {
      try {
        clearExtendTimer();

        if (currentMediaSessionId) {
          try {
            await callBackendPost({
              action: 'stop',
              mediaSessionId: currentMediaSessionId
            });
          } catch (e) {
          }
        }

        currentMediaSessionId = '';
        lastBackendData = null;

        if (pc) {
          try {
            pc.close();
          } catch (e) {
          }
          pc = null;
        }

        videoEl.pause();
        videoEl.srcObject = null;
      } catch (e) {
        setDebug('Stop failed: ' + e.message);
      }
    }

    async function startStream() {
      try {
        if (allMode) {
          return;
        }

        const ok = await ensureAuthenticated();
        if (!ok) {
          return;
        }

        await stopStream();

        setDebug('Creating peer connection...');

        pc = new RTCPeerConnection({
          iceServers: [{ urls: 'stun:stun.l.google.com:19302' }]
        });

        pc.createDataChannel('nest');

        if (initialDebug) {
          pc.onconnectionstatechange = () => setDebug('connectionState: ' + pc.connectionState);
          pc.oniceconnectionstatechange = () => setDebug('iceConnectionState: ' + pc.iceConnectionState);
          pc.onicegatheringstatechange = () => setDebug('iceGatheringState: ' + pc.iceGatheringState);
          pc.onsignalingstatechange = () => setDebug('signalingState: ' + pc.signalingState);
        }

        pc.ontrack = async (event) => {
          setDebug('Track received: ' + event.track.kind);

          const stream = (event.streams && event.streams[0])
            ? event.streams[0]
            : new MediaStream([event.track]);

          videoEl.srcObject = stream;
          videoEl.muted = true;
          videoEl.autoplay = true;
          videoEl.playsInline = true;

          event.track.onunmute = async () => {
            try {
              await videoEl.play();
              setDebug('Playback active. Resolution: ' + videoEl.videoWidth + 'x' + videoEl.videoHeight);
            } catch (err) {
              setDebug('video.play() failed: ' + err.message);
            }
          };
        };

        const offer = await pc.createOffer({
          offerToReceiveAudio: true,
          offerToReceiveVideo: true
        });

        await pc.setLocalDescription(offer);
        await waitForIceComplete(pc);

        let offerSdp = pc.localDescription?.sdp || '';
        if (!offerSdp) {
          throw new Error('Local offer SDP is empty.');
        }

        if (!offerSdp.endsWith('\\n')) {
          offerSdp += '\\n';
        }

        const data = await callBackendPost({
          action: 'generate',
          offerSdp: offerSdp
        });

        lastBackendData = data;
        currentMediaSessionId = data.mediaSessionId || '';

        const mungedAnswerSdp = mungeNestAnswerSdp(data.answerSdp);

        if (initialDebug) {
          setDebug('Offer summary:\\n' + (data.offerSummary || summarizeSdp(offerSdp)));
          setDebug('Original answer summary:\\n' + (data.answerSummary || '(none)'));
          setDebug('Munged answer summary:\\n' + summarizeSdp(mungedAnswerSdp));
        }

        await pc.setRemoteDescription({
          type: 'answer',
          sdp: mungedAnswerSdp
        });

        startExtendTimer();
      } catch (e) {
        if (initialDebug) {
          let extra = '';
          if (pc?.localDescription?.sdp) {
            extra += '\\n\\nCurrent local SDP summary:\\n' + summarizeSdp(pc.localDescription.sdp);
          }
          if (lastBackendData) {
            extra += '\\n\\nLast backend data:\\n' + JSON.stringify({
              offerSummary: lastBackendData.offerSummary || '',
              answerSummary: lastBackendData.answerSummary || '',
              mediaSessionId: lastBackendData.mediaSessionId || '',
              expiresAt: lastBackendData.expiresAt || ''
            }, null, 2);
          }
          setDebug('Start failed: ' + e.message + extra);
        }
      }
    }

    startStream();
  </script>
</body>
</html>
HTML;
    }

    private function BuildPlaceholderHtml(string $message): string
    {
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return '<div style="font-family:Arial,sans-serif;padding:12px;background:#111;color:#eee;border:1px solid #444;">' . $safe . '</div>';
    }

    private function NormalizeHookName(string $hookName): string
    {
        $hookName = trim($hookName);

        if ($hookName === '') {
            $hookName = 'nestcam_' . $this->InstanceID;
        }

        $hookName = strtolower($hookName);
        $hookName = preg_replace('/[^a-z0-9_-]+/', '_', $hookName);
        $hookName = trim((string) $hookName, '_');

        if ($hookName === '') {
            $hookName = 'nestcam_' . $this->InstanceID;
        }

        return $hookName;
    }

    private function NormalizeCameraHookName(string $label): string
    {
        $name = trim($label);
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9_-]+/', '_', $name);
        $name = trim((string) $name, '_');

        if ($name === '') {
            $name = 'camera_' . md5($label);
        }

        return $name;
    }
    //ttt
    private function UnregisterGeneratedCameraHooks(): void
    {
        $registered = json_decode($this->ReadAttributeString('RegisteredCameraHooksJson'), true);
        if (!is_array($registered)) {
            $registered = [];
        }

        foreach ($registered as $hookName) {
            if (is_string($hookName) && $hookName !== '') {
                $this->UnregisterHook($hookName);
            }
        }

        $this->WriteAttributeString('RegisteredCameraHooksJson', '[]');
        $this->WriteAttributeString('HookDeviceMapJson', '{}');
    }

    private function RegisterGeneratedCameraHooks(array $devices): void
    {
        $registeredHooks = [];
        $hookDeviceMap = [];

        foreach ($devices as $deviceName => $device) {
            $label = (string) ($device['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $hookName = $this->NormalizeCameraHookName($label);
            $this->RegisterHook($hookName);

            $registeredHooks[] = $hookName;
            $hookDeviceMap[$hookName] = $deviceName;
        }

        $this->WriteAttributeString('RegisteredCameraHooksJson', json_encode($registeredHooks));
        $this->WriteAttributeString('HookDeviceMapJson', json_encode($hookDeviceMap));
    }

    private function SendJson(array $payload, int $httpCode = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpCode);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
