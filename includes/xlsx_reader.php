<?php
if(!function_exists('crossroadXlsxColumnIndex')){
    function crossroadXlsxColumnIndex($reference){
        preg_match('/^[A-Z]+/i', (string)$reference, $matches);
        $letters = strtoupper($matches[0] ?? "");
        $index = 0;
        for($i = 0; $i < strlen($letters); $i++){
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }
}

if(!function_exists('crossroadXlsxXml')){
    function crossroadXlsxXml($archive, $path){
        if(!isset($archive[$path])){
            throw new RuntimeException("Required workbook file is missing: $path");
        }
        $xml = simplexml_load_string($archive[$path]->getContent());
        if(!$xml){
            throw new RuntimeException("Unable to read workbook XML: $path");
        }
        return $xml;
    }
}

if(!function_exists('crossroadXlsxReadSheet')){
    function crossroadXlsxReadSheet($filePath, $requiredSheetName){
        if(!class_exists('PharData')){
            throw new RuntimeException("Spreadsheet import is unavailable because PharData is not installed.");
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'crossroad_xlsx_');
        $zipFile = $zipPath . '.zip';
        @unlink($zipPath);

        if(!copy($filePath, $zipFile)){
            throw new RuntimeException("Unable to prepare the uploaded workbook.");
        }

        try{
            $archive = new PharData($zipFile);
            $workbook = crossroadXlsxXml($archive, 'xl/workbook.xml');
            $relations = crossroadXlsxXml($archive, 'xl/_rels/workbook.xml.rels');
            $relationTargets = [];

            foreach($relations->Relationship as $relation){
                $relationTargets[(string)$relation['Id']] = (string)$relation['Target'];
            }

            $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $sheetPath = "";
            foreach($workbook->sheets->sheet as $sheet){
                if(strcasecmp(trim((string)$sheet['name']), trim((string)$requiredSheetName)) !== 0){
                    continue;
                }
                $attributes = $sheet->attributes('r', true);
                $relationId = (string)($attributes['id'] ?? "");
                $target = $relationTargets[$relationId] ?? "";
                if($target !== ""){
                    $sheetPath = 'xl/' . ltrim(str_replace('\\', '/', $target), '/');
                }
                break;
            }

            if($sheetPath === ""){
                throw new RuntimeException("The workbook does not contain a Project List sheet.");
            }

            $sharedStrings = [];
            if(isset($archive['xl/sharedStrings.xml'])){
                $sharedXml = crossroadXlsxXml($archive, 'xl/sharedStrings.xml');
                foreach($sharedXml->si as $item){
                    $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
                    $sharedStrings[] = implode('', array_map('strval', $parts));
                }
            }

            $sheetXml = crossroadXlsxXml($archive, $sheetPath);
            $rows = [];
            foreach($sheetXml->sheetData->row as $row){
                $rowNumber = (int)$row['r'];
                $values = [];
                foreach($row->c as $cell){
                    $columnIndex = crossroadXlsxColumnIndex((string)$cell['r']);
                    $type = (string)$cell['t'];
                    $value = "";
                    if($type === 's'){
                        $value = $sharedStrings[(int)$cell->v] ?? "";
                    }elseif($type === 'inlineStr'){
                        $parts = $cell->xpath('.//*[local-name()="t"]') ?: [];
                        $value = implode('', array_map('strval', $parts));
                    }elseif($type === 'b'){
                        $value = ((string)$cell->v === '1') ? '1' : '0';
                    }else{
                        $value = (string)($cell->v ?? "");
                    }
                    $values[$columnIndex] = trim($value);
                }
                $rows[$rowNumber] = $values;
            }

            return $rows;
        }finally{
            @unlink($zipFile);
        }
    }
}

if(!function_exists('crossroadXlsxExcelDate')){
    function crossroadXlsxExcelDate($value){
        $value = trim((string)$value);
        if($value === ""){ return null; }
        if(is_numeric($value)){
            $serial = (float)$value;
            if($serial <= 0){ return null; }
            return gmdate('Y-m-d', (int)round(($serial - 25569) * 86400));
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
?>
