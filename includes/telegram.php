<?php
if(!function_exists('crossroadTelegramBotToken')){
    function crossroadTelegramBotToken(){
        return trim((string)(getenv('CROSSROAD_TELEGRAM_BOT_TOKEN') ?: ''));
    }
}

if(!function_exists('crossroadSendTelegramMessage')){
    function crossroadSendTelegramMessage($chatId, $text, $options = []){
        $token = crossroadTelegramBotToken();
        $chatId = trim((string)$chatId);
        $text = trim((string)$text);

        if($token === '' || $chatId === '' || $text === ''){
            return ['success' => false, 'response' => 'Telegram bot token, chat ID, or message is missing.'];
        }
        if(!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token)){
            return ['success' => false, 'response' => 'Telegram bot token format is invalid.'];
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $requestData = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true
        ];

        if(in_array(($options['parse_mode'] ?? ''), ['HTML', 'MarkdownV2'], true)){
            $requestData['parse_mode'] = $options['parse_mode'];
        }

        $payload = http_build_query($requestData, '', '&');

        $response = false;
        $httpCode = 0;

        if(function_exists('curl_init')){
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            $response = curl_exec($curl);
            $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);

            if($response === false){
                return ['success' => false, 'response' => $curlError !== '' ? $curlError : 'Telegram request failed.'];
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 20,
                    'ignore_errors' => true
                ]
            ]);
            $response = @file_get_contents($url, false, $context);
            foreach($http_response_header ?? [] as $header){
                if(preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)){ $httpCode = (int)$match[1]; }
            }
        }

        $decoded = json_decode((string)$response, true);
        $success = $httpCode >= 200 && $httpCode < 300 && is_array($decoded) && !empty($decoded['ok']);
        return [
            'success' => $success,
            'response' => (string)($response !== false ? $response : 'Telegram request failed.')
        ];
    }
}
?>
