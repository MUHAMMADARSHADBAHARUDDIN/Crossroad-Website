<?php
/*
|--------------------------------------------------------------------------
| Crossroad security helpers
|--------------------------------------------------------------------------
| Drop-in helpers used by existing pages. They harden sessions, add security
| headers and protect unsafe POST requests with a CSRF token without changing
| existing page routes or database logic.
*/

if(!function_exists('securityIsHttps')){
    function securityIsHttps(){
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        );
    }
}

if(!function_exists('sendSecurityHeaders')){
    function sendSecurityHeaders(){
        if(headers_sent()){
            return;
        }

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('X-Permitted-Cross-Domain-Policies: none');
    }
}

if(!function_exists('startSecureSession')){
    function startSecureSession($enforceCsrf = null){
        sendSecurityHeaders();

        if(session_status() !== PHP_SESSION_ACTIVE){
            $secure = securityIsHttps();

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $secure ? '1' : '0');
            ini_set('session.cookie_samesite', 'Lax');

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            session_start();
        }

        if(empty($_SESSION['_created_at'])){
            $_SESSION['_created_at'] = time();
        }

        if(empty($_SESSION['_last_regenerated_at'])){
            $_SESSION['_last_regenerated_at'] = time();
        }
        elseif(time() - (int)$_SESSION['_last_regenerated_at'] > 1800){
            session_regenerate_id(true);
            $_SESSION['_last_regenerated_at'] = time();
        }

        ensureCsrfToken();

        if($enforceCsrf === null){
            $enforceCsrf = shouldEnforceCsrfForCurrentRequest();
        }

        if($enforceCsrf){
            enforceCsrfForUnsafeRequest();
        }
    }
}

if(!function_exists('ensureCsrfToken')){
    function ensureCsrfToken(){
        if(session_status() !== PHP_SESSION_ACTIVE){
            startSecureSession();
        }

        if(empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])){
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if(!function_exists('csrfTokenField')){
    function csrfTokenField(){
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if(!function_exists('getRequestCsrfToken')){
    function getRequestCsrfToken(){
        if(isset($_POST['csrf_token'])){
            return (string)$_POST['csrf_token'];
        }

        if(isset($_GET['csrf_token'])){
            return (string)$_GET['csrf_token'];
        }

        if(isset($_GET['_csrf'])){
            return (string)$_GET['_csrf'];
        }

        if(isset($_SERVER['HTTP_X_CSRF_TOKEN'])){
            return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        if(isset($_SERVER['HTTP_X_XSRF_TOKEN'])){
            return (string)$_SERVER['HTTP_X_XSRF_TOKEN'];
        }

        return '';
    }
}

if(!function_exists('isUnsafeRequestMethod')){
    function isUnsafeRequestMethod(){
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}

if(!function_exists('csrfExemptPath')){
    function csrfExemptPath(){
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $phpSelf = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
        $requestUri = strtok(str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? ''), '?');

        $paths = array_filter([$scriptName, $phpSelf, $requestUri]);

        /*
            Public unauthenticated forms must not depend on JavaScript-injected
            CSRF fields. Login CSRF should not block real users, and the token is
            regenerated immediately after a successful login.
        */
        $exemptSuffixes = [
            '/backend/login.php',
            '/backend/forgot_request.php',
            '/includes/security_client.php'
        ];

        foreach($paths as $path){
            foreach($exemptSuffixes as $suffix){
                if(substr($path, -strlen($suffix)) === $suffix){
                    return true;
                }
            }
        }

        return false;
    }
}

if(!function_exists('shouldEnforceCsrfForCurrentRequest')){
    function shouldEnforceCsrfForCurrentRequest(){
        if(!isUnsafeRequestMethod()){
            return false;
        }

        if(csrfExemptPath()){
            return false;
        }

        return true;
    }
}

if(!function_exists('enforceCsrfForUnsafeRequest')){
    function enforceCsrfForUnsafeRequest(){
        static $checked = false;

        if($checked || !isUnsafeRequestMethod()){
            return;
        }

        $checked = true;
        $expected = ensureCsrfToken();
        $received = getRequestCsrfToken();

        if($received === '' || !hash_equals($expected, $received)){
            http_response_code(419);
            exit('Security check failed. Please refresh the page and try again.');
        }
    }
}



if(!function_exists('requireCsrfTokenForRequest')){
    function requireCsrfTokenForRequest(){
        $expected = ensureCsrfToken();
        $received = getRequestCsrfToken();

        if($received === '' || !hash_equals($expected, $received)){
            http_response_code(419);
            exit('Security check failed. Please refresh the page and try again.');
        }
    }
}

if(!function_exists('safeRedirect')){
    function safeRedirect($location){
        header('Location: ' . $location);
        exit();
    }
}

if(!function_exists('cleanUploadedFileName')){
    function cleanUploadedFileName($fileName){
        $fileName = basename((string)$fileName);
        $fileName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $fileName);
        $fileName = preg_replace('/\s+/', ' ', $fileName);
        $fileName = trim($fileName, '. ');

        return $fileName !== '' ? $fileName : 'uploaded_file';
    }
}
?>
