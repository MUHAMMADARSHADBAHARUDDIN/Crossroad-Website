<?php
if(!function_exists('crossroadLoadEnv')){
    function crossroadLoadEnv(){
        static $loaded = false;

        if($loaded){
            return;
        }

        $loaded = true;
        $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

        if(!is_readable($envPath)){
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach($lines as $line){
            $line = trim($line);

            if($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false){
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");

            if($key !== '' && getenv($key) === false){
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}

crossroadLoadEnv();
?>
