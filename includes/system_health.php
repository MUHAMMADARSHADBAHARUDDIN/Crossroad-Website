<?php
require_once __DIR__ . '/env.php';

if(!function_exists('crossroadHealthResult')){
    function crossroadHealthResult($key, $label, $status, $summary, $details = [], $icon = 'fa-circle-info'){
        return [
            'key' => (string)$key,
            'label' => (string)$label,
            'status' => in_array($status, ['healthy', 'warning', 'error'], true) ? $status : 'warning',
            'summary' => (string)$summary,
            'details' => is_array($details) ? $details : [],
            'icon' => (string)$icon
        ];
    }
}

if(!function_exists('crossroadHealthHttpJson')){
    function crossroadHealthHttpJson($url, $timeout = 7){
        $url = trim((string)$url);
        if($url === ''){
            return ['success' => false, 'http_code' => 0, 'data' => null, 'error' => 'Endpoint is not configured.'];
        }

        $response = false;
        $httpCode = 0;
        $error = '';

        if(function_exists('curl_init')){
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(5, (int)$timeout),
                CURLOPT_TIMEOUT => (int)$timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'Crossroad-System-Health/1.0'
            ]);
            $response = curl_exec($curl);
            $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = (string)curl_error($curl);
            curl_close($curl);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: Crossroad-System-Health/1.0\r\n",
                'timeout' => (int)$timeout,
                'ignore_errors' => true
            ]]);
            $response = @file_get_contents($url, false, $context);
            foreach($http_response_header ?? [] as $header){
                if(preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)){
                    $httpCode = (int)$match[1];
                }
            }
            if($response === false){ $error = 'HTTP request failed.'; }
        }

        $data = json_decode((string)$response, true);
        return [
            'success' => $response !== false && $httpCode >= 200 && $httpCode < 300 && is_array($data),
            'http_code' => $httpCode,
            'data' => is_array($data) ? $data : null,
            'error' => $error !== '' ? $error : ($response === false ? 'No response received.' : '')
        ];
    }
}

if(!function_exists('crossroadHealthRealtimeUrl')){
    function crossroadHealthRealtimeUrl(){
        $publishUrl = trim((string)(getenv('CROSSROAD_REALTIME_PUBLISH_URL') ?: ''));
        if($publishUrl !== ''){
            $parts = parse_url($publishUrl);
            if(is_array($parts) && isset($parts['scheme'], $parts['host'])){
                $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
                return $parts['scheme'] . '://' . $parts['host'] . $port . '/health';
            }
        }

        $publicUrl = trim((string)(getenv('CROSSROAD_REALTIME_PUBLIC_URL') ?: ''));
        if($publicUrl !== ''){
            $parts = parse_url($publicUrl);
            if(is_array($parts) && isset($parts['scheme'], $parts['host'])){
                $scheme = strtolower($parts['scheme']) === 'wss' ? 'https' : 'http';
                $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
                $path = (string)($parts['path'] ?? '');
                $basePath = preg_replace('#/ws/?$#', '', $path);
                return $scheme . '://' . $parts['host'] . $port . rtrim((string)$basePath, '/') . '/health';
            }
        }

        $port = max(1, (int)(getenv('CROSSROAD_REALTIME_PORT') ?: 8081));
        return 'http://127.0.0.1:' . $port . '/health';
    }
}

if(!function_exists('crossroadPlannerCronStatePath')){
    function crossroadPlannerCronStatePath(){
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'planner_cron_status.json';
    }
}

if(!function_exists('crossroadWritePlannerCronStatus')){
    function crossroadWritePlannerCronStatus($status){
        $status = is_array($status) ? $status : [];
        $path = crossroadPlannerCronStatePath();
        $directory = dirname($path);
        if(!is_dir($directory) && !@mkdir($directory, 0775, true)){ return false; }
        $status['recorded_at'] = date(DATE_ATOM);
        $encoded = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) && @file_put_contents($path, $encoded, LOCK_EX) !== false;
    }
}

if(!function_exists('crossroadReadPlannerCronStatus')){
    function crossroadReadPlannerCronStatus(){
        $path = crossroadPlannerCronStatePath();
        if(!is_readable($path)){ return null; }
        $decoded = json_decode((string)@file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }
}

if(!function_exists('crossroadFindLatestBackup')){
    function crossroadFindLatestBackup(){
        $configured = trim((string)(getenv('CROSSROAD_BACKUP_PATH') ?: ''));
        $projectRoot = dirname(__DIR__);
        $candidates = array_values(array_unique(array_filter([
            $configured,
            $projectRoot . DIRECTORY_SEPARATOR . 'backups',
            dirname($projectRoot) . DIRECTORY_SEPARATOR . 'backup_inventory'
        ])));

        $latest = null;
        foreach($candidates as $candidate){
            if(!is_dir($candidate) || !is_readable($candidate)){ continue; }
            $candidateLatest = ['path' => $candidate, 'modified_at' => (int)@filemtime($candidate), 'name' => basename($candidate)];
            try {
                foreach(new DirectoryIterator($candidate) as $item){
                    if($item->isDot()){ continue; }
                    $modifiedAt = (int)$item->getMTime();
                    if($latest === null || $modifiedAt > $latest['modified_at']){
                        $latest = ['path' => $item->getPathname(), 'modified_at' => $modifiedAt, 'name' => $item->getFilename()];
                    }
                }
            } catch(Throwable $error){
                continue;
            }
            if($latest === null){ $latest = $candidateLatest; }
        }
        return $latest;
    }
}

if(!function_exists('crossroadRunSystemHealthChecks')){
    function crossroadRunSystemHealthChecks($mysqli){
        $checks = [];

        try {
            $query = $mysqli->query('SELECT 1 AS connected');
            if($query && (int)$query->fetch_assoc()['connected'] === 1){
                $checks[] = crossroadHealthResult('database', 'Database', 'healthy', 'Connected', [
                    'Server' => (string)$mysqli->server_info,
                    'Character set' => (string)$mysqli->character_set_name()
                ], 'fa-database');
            } else {
                $checks[] = crossroadHealthResult('database', 'Database', 'error', 'Query failed', [
                    'Error' => (string)$mysqli->error
                ], 'fa-database');
            }
        } catch(Throwable $error){
            $checks[] = crossroadHealthResult('database', 'Database', 'error', 'Connection check failed', [
                'Error' => $error->getMessage()
            ], 'fa-database');
        }

        $realtimeUrl = crossroadHealthRealtimeUrl();
        $realtime = crossroadHealthHttpJson($realtimeUrl, 4);
        $realtimePublicConfigured = trim((string)(getenv('CROSSROAD_REALTIME_PUBLIC_URL') ?: '')) !== '';
        if($realtime['success'] && !empty($realtime['data']['ok'])){
            $realtimeStatus = $realtimePublicConfigured ? 'healthy' : 'warning';
            $realtimeSummary = $realtimePublicConfigured ? 'Realtime service is running' : 'Service is running, but the browser WebSocket URL is not configured';
            $checks[] = crossroadHealthResult('realtime', 'WebSocket', $realtimeStatus, $realtimeSummary, [
                'Connected clients' => (string)(int)($realtime['data']['clients'] ?? 0),
                'Health endpoint' => $realtimeUrl,
                'Public URL' => $realtimePublicConfigured ? 'Configured' : 'Missing CROSSROAD_REALTIME_PUBLIC_URL'
            ], 'fa-bolt');
        } else {
            $checks[] = crossroadHealthResult('realtime', 'WebSocket', 'error', 'Realtime service is unreachable', [
                'Health endpoint' => $realtimeUrl,
                'Response' => $realtime['error'] !== '' ? $realtime['error'] : 'HTTP ' . (int)$realtime['http_code']
            ], 'fa-bolt');
        }

        if(function_exists('crossroadMailMissingSettings') && function_exists('crossroadCreateMailer')){
            $missing = crossroadMailMissingSettings();
            if($missing){
                $checks[] = crossroadHealthResult('email', 'Email', 'error', 'SMTP configuration is incomplete', [
                    'Missing settings' => implode(', ', $missing)
                ], 'fa-envelope');
            } else {
                $mailConfig = crossroadMailConfig();
                try {
                    $mailer = crossroadCreateMailer();
                    $mailer->Timeout = 8;
                    $connected = $mailer->smtpConnect();
                    if($connected){ $mailer->smtpClose(); }
                    $checks[] = crossroadHealthResult('email', 'Email', $connected ? 'healthy' : 'error', $connected ? 'SMTP authentication succeeded' : 'SMTP authentication failed', [
                        'Server' => $mailConfig['host'] . ':' . $mailConfig['port'],
                        'Sender' => $mailConfig['from']
                    ], 'fa-envelope');
                } catch(Throwable $error){
                    $checks[] = crossroadHealthResult('email', 'Email', 'error', 'SMTP authentication failed', [
                        'Server' => $mailConfig['host'] . ':' . $mailConfig['port'],
                        'Error' => $error->getMessage()
                    ], 'fa-envelope');
                }
            }
        } else {
            $checks[] = crossroadHealthResult('email', 'Email', 'error', 'Mailer health check is unavailable', [], 'fa-envelope');
        }

        $telegramToken = function_exists('crossroadTelegramBotToken') ? crossroadTelegramBotToken() : '';
        if($telegramToken === ''){
            $checks[] = crossroadHealthResult('telegram', 'Telegram', 'error', 'Bot token is not configured', [], 'fa-paper-plane');
        } elseif(!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $telegramToken)){
            $checks[] = crossroadHealthResult('telegram', 'Telegram', 'error', 'Bot token format is invalid', [], 'fa-paper-plane');
        } else {
            $telegram = crossroadHealthHttpJson('https://api.telegram.org/bot' . $telegramToken . '/getWebhookInfo', 8);
            $data = $telegram['data']['result'] ?? null;
            if($telegram['success'] && !empty($telegram['data']['ok']) && is_array($data)){
                $webhookUrl = trim((string)($data['url'] ?? ''));
                $lastError = trim((string)($data['last_error_message'] ?? ''));
                $status = $webhookUrl === '' || $lastError !== '' ? 'warning' : 'healthy';
                $summary = $webhookUrl === '' ? 'Bot works, but no webhook is configured' : ($lastError !== '' ? 'Webhook reported an error' : 'Webhook is active');
                $details = [
                    'Webhook URL' => $webhookUrl !== '' ? $webhookUrl : 'Not set',
                    'Pending updates' => (string)(int)($data['pending_update_count'] ?? 0)
                ];
                if($lastError !== ''){ $details['Last webhook error'] = $lastError; }
                if(!empty($data['last_error_date'])){ $details['Error time'] = date('d M Y, g:i a', (int)$data['last_error_date']); }
                $checks[] = crossroadHealthResult('telegram', 'Telegram', $status, $summary, $details, 'fa-paper-plane');
            } else {
                $message = (string)($telegram['data']['description'] ?? $telegram['error'] ?? 'Telegram API request failed.');
                $checks[] = crossroadHealthResult('telegram', 'Telegram', 'error', 'Telegram API check failed', ['Error' => $message], 'fa-paper-plane');
            }
        }

        $cron = crossroadReadPlannerCronStatus();
        if(!$cron){
            $checks[] = crossroadHealthResult('planner_cron', 'Planner Cron', 'warning', 'No run has been recorded yet', [
                'Expected schedule' => 'Every minute',
                'Status file' => crossroadPlannerCronStatePath()
            ], 'fa-clock');
        } else {
            $recordedAt = strtotime((string)($cron['recorded_at'] ?? $cron['checked_at'] ?? '')) ?: 0;
            $ageSeconds = $recordedAt > 0 ? time() - $recordedAt : PHP_INT_MAX;
            $runSuccess = !empty($cron['success']);
            $fresh = $ageSeconds <= 180;
            $status = $fresh && $runSuccess ? 'healthy' : ($fresh ? 'warning' : 'error');
            $summary = !$fresh ? 'Last run is overdue' : ($runSuccess ? 'Running on schedule' : 'Last run completed with errors');
            $details = [
                'Last run' => $recordedAt > 0 ? date('d M Y, g:i:s a', $recordedAt) : 'Unknown',
                'Next expected' => $recordedAt > 0 ? date('d M Y, g:i:s a', $recordedAt + 60) : 'Unknown',
                'Expected schedule' => 'Every minute'
            ];
            if(!empty($cron['dry_run'])){ $details['Mode'] = 'Dry run'; }
            if(!empty($cron['error'])){ $details['Last error'] = (string)$cron['error']; }
            if(isset($cron['sent'])){ $details['Emails sent'] = (string)(int)$cron['sent']; }
            if(isset($cron['telegram_sent'])){ $details['Telegram sent'] = (string)(int)$cron['telegram_sent']; }
            $checks[] = crossroadHealthResult('planner_cron', 'Planner Cron', $status, $summary, $details, 'fa-clock');
        }

        $uploadRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
        $itemUpload = $uploadRoot . DIRECTORY_SEPARATOR . 'item_receive';
        $uploadRootOkay = is_dir($uploadRoot) && is_writable($uploadRoot);
        $itemUploadOkay = is_dir($itemUpload) && is_writable($itemUpload);
        $uploadStatus = $uploadRootOkay && $itemUploadOkay ? 'healthy' : 'error';
        $checks[] = crossroadHealthResult('uploads', 'Upload Directory', $uploadStatus, $uploadStatus === 'healthy' ? 'Upload folders are writable' : 'One or more upload folders are not writable', [
            'Uploads' => $uploadRootOkay ? 'Writable' : 'Not writable or missing',
            'Item Receive' => $itemUploadOkay ? 'Writable' : 'Not writable or missing',
            'PHP upload limit' => (string)ini_get('upload_max_filesize'),
            'PHP post limit' => (string)ini_get('post_max_size')
        ], 'fa-folder-open');

        $latestBackup = crossroadFindLatestBackup();
        if($latestBackup){
            $modifiedAt = (int)$latestBackup['modified_at'];
            $ageDays = $modifiedAt > 0 ? (time() - $modifiedAt) / 86400 : PHP_INT_MAX;
            $backupStatus = $ageDays <= 7 ? 'healthy' : 'warning';
            $checks[] = crossroadHealthResult('backup', 'Backup', $backupStatus, $backupStatus === 'healthy' ? 'Recent backup found' : 'Latest visible backup is older than 7 days', [
                'Latest item' => (string)$latestBackup['name'],
                'Modified' => $modifiedAt > 0 ? date('d M Y, g:i a', $modifiedAt) : 'Unknown',
                'Location' => dirname((string)$latestBackup['path'])
            ], 'fa-box-archive');
        } else {
            $checks[] = crossroadHealthResult('backup', 'Backup', 'warning', 'No application-visible backup was found', [
                'How to configure' => 'Set CROSSROAD_BACKUP_PATH to a readable backup directory.',
                'Plesk note' => 'Plesk Backup Manager backups may not be visible to PHP.'
            ], 'fa-box-archive');
        }

        return $checks;
    }
}
?>
