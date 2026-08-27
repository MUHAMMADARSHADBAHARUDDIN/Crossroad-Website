<?php

if(!function_exists('receivingAttachmentDirectory')){
    function receivingAttachmentDirectory(){
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'item_receive';
    }
}

if(!function_exists('receivingAttachmentMimeMap')){
    function receivingAttachmentMimeMap(){
        return [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain'
        ];
    }
}

if(!function_exists('receivingAttachmentPath')){
    function receivingAttachmentPath($storedName){
        $storedName = basename(trim((string)$storedName));
        return $storedName === '' ? '' : receivingAttachmentDirectory() . DIRECTORY_SEPARATOR . $storedName;
    }
}

if(!function_exists('receivingDeleteAttachment')){
    function receivingDeleteAttachment($storedName){
        $path = receivingAttachmentPath($storedName);
        return $path === '' || !is_file($path) || @unlink($path);
    }
}

if(!function_exists('receivingStoreAttachment')){
    function receivingStoreAttachment($file){
        $empty = [
            'success' => false,
            'error' => '',
            'stored_name' => '',
            'original_name' => '',
            'mime' => '',
            'size' => 0
        ];

        if(!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE){
            $empty['success'] = true;
            return $empty;
        }

        if((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK){
            $empty['error'] = 'Attachment upload failed.';
            return $empty;
        }

        $size = (int)($file['size'] ?? 0);
        if($size <= 0 || $size > 104857600){
            $empty['error'] = 'Attachment must be a non-empty file and must not exceed 100 MB.';
            return $empty;
        }

        $original = basename((string)($file['name'] ?? ''));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $mimeMap = receivingAttachmentMimeMap();
        if(!isset($mimeMap[$extension])){
            $empty['error'] = 'Unsupported attachment type.';
            return $empty;
        }

        $mime = '';
        $temporaryPath = (string)($file['tmp_name'] ?? '');
        if(class_exists('finfo') && defined('FILEINFO_MIME_TYPE')){
            $detector = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $detector->file($temporaryPath);
            $mime = is_string($detectedMime) ? $detectedMime : '';
        } elseif(function_exists('mime_content_type')){
            $detectedMime = @mime_content_type($temporaryPath);
            $mime = is_string($detectedMime) ? $detectedMime : '';
        }

        if($mime === '' || $mime === 'application/octet-stream' || $mime === 'application/zip'){
            $mime = $mimeMap[$extension];
        }

        if(!in_array($mime, array_values(array_unique($mimeMap)), true)){
            $empty['error'] = 'The attachment content does not match an allowed file type.';
            return $empty;
        }

        $directory = receivingAttachmentDirectory();
        if(!is_dir($directory) && !mkdir($directory, 0775, true)){
            $empty['error'] = 'Unable to create the attachment folder.';
            return $empty;
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        if(!move_uploaded_file($temporaryPath, $directory . DIRECTORY_SEPARATOR . $storedName)){
            $empty['error'] = 'Unable to save attachment.';
            return $empty;
        }

        return [
            'success' => true,
            'error' => '',
            'stored_name' => $storedName,
            'original_name' => $original,
            'mime' => $mime,
            'size' => $size
        ];
    }
}

