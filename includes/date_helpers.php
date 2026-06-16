<?php
if(!function_exists('appNormalizeDateInput')){
    function appNormalizeDateInput($value){
        $value = trim((string)($value ?? ""));

        if($value === "" || $value === "0000-00-00"){
            return null;
        }

        $date = DateTime::createFromFormat("Y-m-d", $value);

        if(!$date || $date->format("Y-m-d") !== $value){
            return null;
        }

        return $value;
    }
}

if(!function_exists('appDateInputValue')){
    function appDateInputValue($value){
        return appNormalizeDateInput($value) ?? "";
    }
}

if(!function_exists('appSqlDateValue')){
    function appSqlDateValue($columnSql){
        return "STR_TO_DATE(NULLIF(CAST($columnSql AS CHAR), '0000-00-00'), '%Y-%m-%d')";
    }
}
?>
