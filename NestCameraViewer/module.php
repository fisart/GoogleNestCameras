<?php

declare(strict_types=1);

class NestCameraViewer extends IPSModuleStrict
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
        $this->RegisterPropertyString('EnterpriseID', 'f779e361-a2e1-43ef-8ffd-dfd46d3e69ab');
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
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $hookName = $this->NormalizeHookName($this->ReadPropertyString('HookName'));
        $this->RegisterHook($hookName);

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
            $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('Google SDM request failed'));
            return;
        }

        if (count($devices) === 0) {
            $this->SetStatus(self::STATUS_NO_CAMERAS);
            $this->SetValue('StreamStatus', 'No compatible WEB_RTC cameras found');
            $this->SetValue('ViewerHTML', $this->BuildPlaceholderHtml('No compatible WEB_RTC cameras found'));
            return;
        }

        $selected = $this->ResolveSelectedDeviceName($devices);
        $this->SetValue('SelectedCameraLabel', $devices[$selected]['label']);
        $this->SetValue('StreamStatus', 'Ready');
        $this->SetValue('ViewerHTML', $this->RenderViewerHtml());
        $this->SetStatus(self::STATUS_ACTIVE);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            $form = [
                'elements' => [],
                'actions'  => [],
                'status'   => []
            ];
        }

        $tokenVarID = $this->ReadPropertyInteger('TokenVariableID');
        $currentSelectedDevice = $this->ReadPropertyString('SelectedDeviceName');

        if ($tokenVarID > 0 && IPS_VariableExists($tokenVarID)) {
            $devices = $this->GetCachedDevices();
        } else {
            $devices = [];
        }

        $deviceOptions = [
            [
                'caption' => '',
                'value'   => ''
            ]
        ];

        foreach ($devices as $deviceName => $device) {
            $deviceOptions[] = [
                'caption' => $device['label'],
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

        $form['elements'] = [
            [
                'type'    => 'SelectVariable',
                'name'    => 'TokenVariableID',
                'caption' => 'Token Variable'
            ],
            [
                'type'    => 'ValidationTextBox',
                'name'    => 'EnterpriseID',
                'caption' => 'Enterprise ID'
            ],
            [
                'type'    => 'ValidationTextBox',
                'name'    => 'HookName',
                'caption' => 'Hook Name'
            ],
            [
                'type'    => 'Select',
                'name'    => 'SelectedDeviceName',
                'caption' => 'Camera',
                'options' => $deviceOptions
            ],
            [
                'type'    => 'CheckBox',
                'name'    => 'AutoExtend',
                'caption' => 'Auto Extend Stream'
            ],
            [
                'type'    => 'CheckBox',
                'name'    => 'Debug',
                'caption' => 'Show Debug in Viewer'
            ],
            [
                'type'    => 'Label',
                'caption' => 'Hook URL: ' . $hookPath
            ]
        ];

        $form['actions'] = [
            [
                'type'    => 'Button',
                'caption' => 'Refresh device list',
                'onClick' => 'NESTCAM_RefreshDevices($id);'
            ],
            [
                'type'    => 'Button',
                'caption' => 'Rebuild viewer HTML',
                'onClick' => 'NESTCAM_RebuildViewer($id);'
            ]
        ];

        return json_encode($form);
    }

    public function RefreshDevices(): void
    {
        $devices = $this->FetchDevices();
        if ($devices === null) {
            $this->SetStatus(self::STATUS_GOOGLE_ERROR);
            echo 'Google SDM request failed';
            return;
        }

        if (count($devices) === 0) {
            $this->SetStatus(self::STATUS_NO_CAMERAS);
            echo 'No compatible WEB_RTC cameras found';
            return;
        }

        $selected = $this->ResolveSelectedDeviceName($devices);
        $this->SetValue('SelectedCameraLabel', $devices[$selected]['label']);
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
        $hookName = $this->NormalizeHookName($this->ReadPropertyString('HookName'));
        $hookPath = '/hook/' . $hookName;

        if (strpos($uri, $hookPath) !== 0) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $action = $_GET['action'] ?? $_POST['action'] ?? 'page';

        try {
            switch ($action) {
                case 'page':
                    header('Content-Type: text/html; charset=utf-8');
                    echo $this->RenderViewerHtml();
                    return;

                case 'ping':
                    $this->SendJson([
                        'ok' => true,
                        'scriptID' => 0,
                        'instanceID' => $this->InstanceID,
                        'message' => 'Backend reached'
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
                    $deviceName = $this->ResolveRequestDeviceName();
                    $offerSdp = $this->ReadPostedOfferSdp();
                    if ($offerSdp === '') {
                        $this->SendJson([
                            'ok' => false,
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

    private function GetToken(): string
    {
        $varID = $this->ReadPropertyInteger('TokenVariableID');
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return '';
        }
        return trim((string) GetValue($varID));
    }

    private function FetchDevices(): ?array
    {
        $token = $this->GetToken();
        if ($token === '') {
            return null;
        }

        $enterpriseId = $this->DetectEnterpriseId();
        if ($enterpriseId === '') {
            return null;
        }

        $url = 'https://smartdevicemanagement.googleapis.com/v1/enterprises/' . $enterpriseId . '/devices';
        $result = $this->GoogleRequest($url, 'GET');
        if ($result['httpCode'] !== 200) {
            return null;
        }

        $json = json_decode($result['response'], true);
        if (!is_array($json) || !isset($json['devices']) || !is_array($json['devices'])) {
            return [];
        }

        $devices = [];
        foreach ($json['devices'] as $device) {
            $name = (string) ($device['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $traits = $device['traits'] ?? [];
            $live = $traits['sdm.devices.traits.CameraLiveStream'] ?? null;
            if (!is_array($live)) {
                continue;
            }

            $protocols = $live['supportedProtocols'] ?? [];
            if (!is_array($protocols) || !in_array('WEB_RTC', $protocols, true)) {
                continue;
            }

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

    private function GetCachedDevices(): array
    {
        $devices = json_decode($this->ReadAttributeString('CachedDevicesJson'), true);
        if (!is_array($devices) || count($devices) === 0) {
            $devices = $this->FetchDevices();
        }
        return is_array($devices) ? $devices : [];
    }

    private function ResolveSelectedDeviceName(array $devices): string
    {
        $selected = $this->ReadPropertyString('SelectedDeviceName');
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
        return $this->ResolveSelectedDeviceName($devices);
    }

    private function DetectEnterpriseId(): string
    {
        return trim($this->ReadPropertyString('EnterpriseID'));
    }

    private function GoogleRequest(string $url, string $method, ?array $body = null): array
    {
        $token = $this->GetToken();
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
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'httpCode' => 0,
                'response' => $curlErr,
                'curlErr'  => $curlErr
            ];
        }

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

    private function RenderViewerHtml(): string
    {
        $hookPath = '/hook/' . $this->NormalizeHookName($this->ReadPropertyString('HookName'));
        $showDebug = $this->ReadPropertyBoolean('Debug') ? 'true' : 'false';
        $autoExtend = $this->ReadPropertyBoolean('AutoExtend') ? 'true' : 'false';

        return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Nest Camera Viewer</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 12px; background: #111; color: #eee; }
    h2 { margin-top: 0; font-size: 20px; }
    .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 12px; }
    select, button, label { font-size: 14px; }
    select, button { padding: 8px 12px; }
    #status { margin: 10px 0 15px 0; white-space: pre-wrap; font-size: 13px; background: #1b1b1b; border: 1px solid #444; padding: 10px; max-height: 420px; overflow: auto; }
    video { width: 100%; max-width: 960px; background: #000; border: 1px solid #444; margin-top: 10px; }
  </style>
</head>
<body>
  <h2>Nest Camera Viewer</h2>
  <div class="toolbar">
    <select id="cameraSelect"></select>
    <button id="btnStart">Start</button>
    <button id="btnStop">Stop</button>
    <button id="btnInfo">Info</button>
    <label><input type="checkbox" id="chkDebug"> Show Debug</label>
  </div>
  <div id="status">Loading cameras...</div>
  <video id="video" autoplay playsinline muted></video>
  <script>
    const backendBaseUrl = '{$hookPath}';
    const initialDebug = {$showDebug};
    const autoExtendEnabled = {$autoExtend};
    const statusEl = document.getElementById('status');
    const videoEl = document.getElementById('video');
    const cameraSelect = document.getElementById('cameraSelect');
    const chkDebug = document.getElementById('chkDebug');
    chkDebug.checked = initialDebug;

    let pc = null;
    let currentMediaSessionId = '';
    let currentDeviceName = '';
    let extendTimer = null;
    let lastBackendData = null;
    let logLines = [];

    function setStatus(msg) {
      statusEl.textContent = msg;
      console.log(msg);
    }

    function appendStatus(msg, force = false) {
      if (!chkDebug.checked && !force) return;
      logLines.push(msg);
      if (!chkDebug.checked && logLines.length > 8) logLines.shift();
      statusEl.textContent = logLines.join('\n\n');
      console.log(msg);
    }

    function summarizeSdp(sdp) {
      if (!sdp) return '(empty)';
      const lines = sdp.split(/\r\n|\n/).filter(Boolean);
      return lines.filter(line =>
        line.startsWith('m=') ||
        line === 'a=sendrecv' ||
        line === 'a=recvonly' ||
        line === 'a=sendonly' ||
        line === 'a=inactive' ||
        line.startsWith('a=group:') ||
        line.startsWith('a=mid:') ||
        line.startsWith('a=sctp-port:')
      ).join('\n');
    }

    function mungeNestAnswerSdp(answerSdp) {
      const sections = answerSdp.split(/\r\nm=/);
      if (sections.length === 0) return answerSdp;
      const rebuilt = sections.map((section, index) => {
        let s = index === 0 ? section : 'm=' + section;
        const isAudio = s.includes('\nm=audio') || s.startsWith('m=audio');
        const isVideo = s.includes('\nm=video') || s.startsWith('m=video');
        if (isAudio || isVideo) {
          s = s.replace(/\r?\na=sendrecv(\r?\n)/g, '\r\na=sendonly$1');
        }
        return s;
      });
      return rebuilt.join('\r\n');
    }

    async function parseJsonResponse(res) {
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); } catch (e) { throw new Error('Backend did not return JSON: ' + text); }
      if (!data.ok) throw new Error(JSON.stringify(data, null, 2));
      return data;
    }

    async function callBackendGet(action, deviceName = '') {
      let url = backendBaseUrl + '?action=' + encodeURIComponent(action);
      if (deviceName) url += '&deviceName=' + encodeURIComponent(deviceName);
      const res = await fetch(url, { method: 'GET', cache: 'no-store' });
      return await parseJsonResponse(res);
    }

    async function callBackendPost(payload) {
      const body = new URLSearchParams();
      Object.keys(payload).forEach((key) => body.append(key, payload[key]));
      const res = await fetch(backendBaseUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString()
      });
      return await parseJsonResponse(res);
    }

    async function loadDevices() {
      const data = await callBackendGet('devices');
      cameraSelect.innerHTML = '';
      data.devices.forEach(device => {
        const opt = document.createElement('option');
        opt.value = device.name;
        opt.textContent = device.label;
        if (device.name === data.default) opt.selected = true;
        cameraSelect.appendChild(opt);
      });
      currentDeviceName = cameraSelect.value;
      setStatus('Ready.');
    }

    async function loadInfo() {
      try {
        const data = await callBackendGet('info', cameraSelect.value);
        setStatus(JSON.stringify(data.device, null, 2));
      } catch (e) {
        setStatus('Info failed:\n' + e.message);
      }
    }

    async function waitForIceComplete(peer) {
      await new Promise((resolve) => {
        if (peer.iceGatheringState === 'complete') { resolve(); return; }
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
      if (extendTimer) { clearInterval(extendTimer); extendTimer = null; }
    }

    function startExtendTimer() {
      if (!autoExtendEnabled) return;
      clearExtendTimer();
      extendTimer = setInterval(async () => {
        if (!currentMediaSessionId || !currentDeviceName) return;
        try {
          const data = await callBackendPost({ action: 'extend', deviceName: currentDeviceName, mediaSessionId: currentMediaSessionId });
          appendStatus('Extended stream. New expiry: ' + (data.expiresAt || 'unknown'));
        } catch (e) {
          appendStatus('Auto-extend failed: ' + e.message);
        }
      }, 240000);
    }

    async function stopStream() {
      try {
        clearExtendTimer();
        if (currentMediaSessionId && currentDeviceName) {
          try {
            await callBackendPost({ action: 'stop', deviceName: currentDeviceName, mediaSessionId: currentMediaSessionId });
          } catch (e) {}
        }
        currentMediaSessionId = '';
        lastBackendData = null;
        if (pc) { try { pc.close(); } catch (e) {} pc = null; }
        videoEl.pause();
        videoEl.srcObject = null;
        setStatus('Stream stopped.');
      } catch (e) {
        setStatus('Stop failed:\n' + e.message);
      }
    }

    async function startStream() {
      try {
        await stopStream();
        logLines = [];
        currentDeviceName = cameraSelect.value;
        appendStatus('Creating peer connection...');

        pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
        pc.createDataChannel('nest');

        if (chkDebug.checked) {
          pc.onconnectionstatechange = () => appendStatus('connectionState: ' + pc.connectionState);
          pc.oniceconnectionstatechange = () => appendStatus('iceConnectionState: ' + pc.iceConnectionState);
          pc.onicegatheringstatechange = () => appendStatus('iceGatheringState: ' + pc.iceGatheringState);
          pc.onsignalingstatechange = () => appendStatus('signalingState: ' + pc.signalingState);
        }

        pc.ontrack = async (event) => {
          appendStatus('Track received: ' + event.track.kind);
          let stream = event.streams && event.streams[0] ? event.streams[0] : new MediaStream([event.track]);
          videoEl.srcObject = stream;
          videoEl.muted = true;
          videoEl.autoplay = true;
          videoEl.playsInline = true;
          event.track.onunmute = async () => {
            try {
              await videoEl.play();
              appendStatus('Playback active. Resolution: ' + videoEl.videoWidth + 'x' + videoEl.videoHeight);
            } catch (err) {
              appendStatus('video.play() failed: ' + err.message);
            }
          };
        };

        const offer = await pc.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: true });
        await pc.setLocalDescription(offer);
        await waitForIceComplete(pc);

        let offerSdp = pc.localDescription?.sdp || '';
        if (!offerSdp) throw new Error('Local offer SDP is empty.');
        if (!offerSdp.endsWith('\n')) offerSdp += '\n';

        const data = await callBackendPost({ action: 'generate', deviceName: currentDeviceName, offerSdp: offerSdp });
        lastBackendData = data;
        currentMediaSessionId = data.mediaSessionId || '';
        const mungedAnswerSdp = mungeNestAnswerSdp(data.answerSdp);

        if (chkDebug.checked) {
          appendStatus('Offer summary:\n' + (data.offerSummary || summarizeSdp(offerSdp)));
          appendStatus('Original answer summary:\n' + (data.answerSummary || '(none)'));
          appendStatus('Munged answer summary:\n' + summarizeSdp(mungedAnswerSdp));
        }

        await pc.setRemoteDescription({ type: 'answer', sdp: mungedAnswerSdp });
        setStatus('Stream started.\nExpires: ' + (data.expiresAt || 'unknown'));
        startExtendTimer();
      } catch (e) {
        let extra = '';
        if (chkDebug.checked && pc?.localDescription?.sdp) {
          extra += '\n\nCurrent local SDP summary:\n' + summarizeSdp(pc.localDescription.sdp);
        }
        if (chkDebug.checked && lastBackendData) {
          extra += '\n\nLast backend data:\n' + JSON.stringify({
            offerSummary: lastBackendData.offerSummary || '',
            answerSummary: lastBackendData.answerSummary || '',
            mediaSessionId: lastBackendData.mediaSessionId || '',
            expiresAt: lastBackendData.expiresAt || ''
          }, null, 2);
        }
        setStatus('Start failed:\n' + e.message + extra);
      }
    }

    cameraSelect.addEventListener('change', () => { currentDeviceName = cameraSelect.value; });
    document.getElementById('btnInfo').addEventListener('click', loadInfo);
    document.getElementById('btnStart').addEventListener('click', startStream);
    document.getElementById('btnStop').addEventListener('click', stopStream);
    loadDevices().catch(err => setStatus('Failed to load cameras:\n' + err.message));
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
        return ltrim($hookName, '/');
    }

    private function SendJson(array $payload, int $httpCode = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpCode);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
