<?php
if(!function_exists('officeInventoryDocumentUploadDir')){
    function officeInventoryDocumentUploadDir(){
        return __DIR__ . "/../uploads/office_inventory";
    }
}

if(!function_exists('officeInventoryDocumentDiskPath')){
    function officeInventoryDocumentDiskPath($fileName){
        return officeInventoryDocumentUploadDir() . "/" . basename((string)$fileName);
    }
}

if(!function_exists('officeInventoryDocumentMaxUploadBytes')){
    function officeInventoryDocumentMaxUploadBytes(){
        return 100 * 1024 * 1024;
    }
}

if(!function_exists('officeInventoryDocumentMaxUploadLabel')){
    function officeInventoryDocumentMaxUploadLabel(){
        return "100MB";
    }
}

if(!function_exists('officeInventoryDocumentAllowedExtensions')){
    function officeInventoryDocumentAllowedExtensions(){
        return ["pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "txt", "csv", "jpg", "jpeg", "png", "zip"];
    }
}

if(!function_exists('officeInventoryDocumentEnsureUploadDir')){
    function officeInventoryDocumentEnsureUploadDir(){
        $dir = officeInventoryDocumentUploadDir();

        if(!is_dir($dir)){
            mkdir($dir, 0777, true);
        }

        return $dir;
    }
}

if(!function_exists('officeInventoryDocumentUploadErrorMessage')){
    function officeInventoryDocumentUploadErrorMessage($errorCode){
        if($errorCode === UPLOAD_ERR_OK){
            return "";
        }

        if($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE){
            return "Document is too large. Maximum office inventory document size is " . officeInventoryDocumentMaxUploadLabel() . ".";
        }

        if($errorCode === UPLOAD_ERR_PARTIAL){
            return "Document upload was incomplete. Please try again.";
        }

        if($errorCode === UPLOAD_ERR_NO_FILE){
            return "";
        }

        return "File upload error.";
    }
}

if(!function_exists('officeInventoryDocumentValidateUpload')){
    function officeInventoryDocumentValidateUpload($file){
        if(!is_array($file)){
            return "";
        }

        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $errorMessage = officeInventoryDocumentUploadErrorMessage($error);

        if($errorMessage !== ""){
            return $errorMessage;
        }

        if($error === UPLOAD_ERR_NO_FILE){
            return "";
        }

        $size = (int)($file['size'] ?? 0);

        if($size > officeInventoryDocumentMaxUploadBytes()){
            return "Document is too large. Maximum office inventory document size is " . officeInventoryDocumentMaxUploadLabel() . ".";
        }

        $originalName = (string)($file['name'] ?? "");
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if(!in_array($extension, officeInventoryDocumentAllowedExtensions(), true)){
            return "Invalid document type. Allowed types: " . implode(", ", officeInventoryDocumentAllowedExtensions()) . ".";
        }

        return "";
    }
}

if(!function_exists('officeInventoryDocumentStoredFileName')){
    function officeInventoryDocumentStoredFileName($originalName){
        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename((string)$originalName));
        $safeName = trim($safeName, "._");

        if($safeName === ""){
            $safeName = "document";
        }

        return time() . "_" . bin2hex(random_bytes(4)) . "_" . $safeName;
    }
}

if(!function_exists('officeInventoryDocumentDisplayName')){
    function officeInventoryDocumentDisplayName($row){
        $original = trim((string)($row['document_original_name'] ?? ''));

        if($original !== ""){
            return $original;
        }

        return preg_replace('/^\d+_[a-f0-9]+_/', '', basename((string)($row['document_file_name'] ?? '')));
    }
}
?>
