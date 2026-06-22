<?php
require_once __DIR__ . "/contract_task_schema.php";

if(!function_exists('contractTaskDocumentEscape')){
    function contractTaskDocumentEscape($value){
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if(!function_exists('contractTaskDocumentUploadDir')){
    function contractTaskDocumentUploadDir(){
        return __DIR__ . "/../uploads/contract_tasks";
    }
}

if(!function_exists('contractTaskDocumentPublicPath')){
    function contractTaskDocumentPublicPath($fileName){
        return "../uploads/contract_tasks/" . basename($fileName);
    }
}

if(!function_exists('contractTaskDocumentDiskPath')){
    function contractTaskDocumentDiskPath($fileName){
        return contractTaskDocumentUploadDir() . "/" . basename($fileName);
    }
}

if(!function_exists('contractTaskDocumentMaxUploadBytes')){
    function contractTaskDocumentMaxUploadBytes(){
        return 100 * 1024 * 1024;
    }
}

if(!function_exists('contractTaskDocumentMaxUploadLabel')){
    function contractTaskDocumentMaxUploadLabel(){
        return "100MB";
    }
}

if(!function_exists('contractTaskDocumentUploadErrorMessage')){
    function contractTaskDocumentUploadErrorMessage($errorCode){
        if($errorCode === UPLOAD_ERR_OK){
            return "";
        }

        if($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE){
            return "Document is too large. Maximum checklist document size is " . contractTaskDocumentMaxUploadLabel() . ".";
        }

        if($errorCode === UPLOAD_ERR_PARTIAL){
            return "Document upload was incomplete. Please try again.";
        }

        if($errorCode === UPLOAD_ERR_NO_FILE){
            return "Please choose a document.";
        }

        return "File upload error.";
    }
}

if(!function_exists('contractTaskDocumentValidateUpload')){
    function contractTaskDocumentValidateUpload($file){
        if(!is_array($file)){
            return "Please choose a document.";
        }

        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $errorMessage = contractTaskDocumentUploadErrorMessage($error);

        if($errorMessage !== ""){
            return $errorMessage;
        }

        $size = (int)($file['size'] ?? 0);

        if($size > contractTaskDocumentMaxUploadBytes()){
            return "Document is too large. Maximum checklist document size is " . contractTaskDocumentMaxUploadLabel() . ".";
        }

        return "";
    }
}

if(!function_exists('contractTaskDocumentStoredFileName')){
    function contractTaskDocumentStoredFileName($originalName){
        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename((string)$originalName));
        $safeName = trim($safeName, "._");

        if($safeName === ""){
            $safeName = "document";
        }

        return time() . "_" . bin2hex(random_bytes(4)) . "_" . $safeName;
    }
}

if(!function_exists('contractTaskDocumentTaskIdColumn')){
    function contractTaskDocumentTaskIdColumn($mysqli){
        if(contractTaskSchemaColumnExists($mysqli, "contract_tasks", "id")){
            return "id";
        }

        if(contractTaskSchemaColumnExists($mysqli, "contract_tasks", "no")){
            return "no";
        }

        return "";
    }
}

if(!function_exists('contractTaskDocumentTextColumn')){
    function contractTaskDocumentTextColumn($mysqli){
        foreach(["task_text", "task_name", "title", "description"] as $column){
            if(contractTaskSchemaColumnExists($mysqli, "contract_tasks", $column)){
                return $column;
            }
        }

        return "";
    }
}

if(!function_exists('contractTaskDocumentFetchTask')){
    function contractTaskDocumentFetchTask($mysqli, $taskId){
        ensureContractTaskDocumentSchema($mysqli);

        if(
            $taskId <= 0 ||
            !contractTaskSchemaTableExists($mysqli, "contract_tasks") ||
            !contractTaskSchemaColumnExists($mysqli, "contract_tasks", "contract_id")
        ){
            return null;
        }

        $idColumn = contractTaskDocumentTaskIdColumn($mysqli);
        $textColumn = contractTaskDocumentTextColumn($mysqli);

        if($idColumn === ""){
            return null;
        }

        $taskTextSelect = $textColumn !== "" ? "ct.`$textColumn` AS task_text" : "'' AS task_text";

        $stmt = $mysqli->prepare("
            SELECT
                ct.`$idColumn` AS task_id,
                ct.contract_id,
                $taskTextSelect,
                pi.created_by,
                pi.project_name,
                pi.contract_no
            FROM contract_tasks ct
            INNER JOIN project_inventory pi ON pi.no = ct.contract_id
            WHERE ct.`$idColumn` = ?
            LIMIT 1
        ");

        if(!$stmt){
            return null;
        }

        $stmt->bind_param("i", $taskId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}

if(!function_exists('contractTaskDocumentFetchDocument')){
    function contractTaskDocumentFetchDocument($mysqli, $documentId){
        ensureContractTaskDocumentSchema($mysqli);

        if($documentId <= 0){
            return null;
        }

        $stmt = $mysqli->prepare("
            SELECT
                d.id,
                d.contract_id,
                d.task_id,
                d.file_name,
                d.original_file_name,
                d.uploaded_by,
                d.created_at,
                pi.created_by,
                pi.project_name,
                pi.contract_no
            FROM contract_task_documents d
            INNER JOIN project_inventory pi ON pi.no = d.contract_id
            WHERE d.id = ?
            LIMIT 1
        ");

        if(!$stmt){
            return null;
        }

        $stmt->bind_param("i", $documentId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}

if(!function_exists('contractTaskDocumentDisplayName')){
    function contractTaskDocumentDisplayName($document){
        $original = trim((string)($document['original_file_name'] ?? ''));

        if($original !== ""){
            return $original;
        }

        return preg_replace('/^\d+_/', '', basename($document['file_name'] ?? ''));
    }
}

if(!function_exists('contractTaskDocumentEnsureUploadDir')){
    function contractTaskDocumentEnsureUploadDir(){
        $dir = contractTaskDocumentUploadDir();

        if(!is_dir($dir)){
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
?>
