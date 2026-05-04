<?php
function buildCommaSearchWhere($search, $columns, &$params, &$types)
{
    $searchTerms = [];

    if(trim($search) !== ""){
        $rawTerms = explode(",", $search);

        foreach($rawTerms as $term){
            $term = trim($term);

            if($term !== ""){
                $searchTerms[] = $term;
            }
        }
    }

    $whereParts = [];

    foreach($searchTerms as $term){
        $searchLike = "%" . $term . "%";

        $singleTermParts = [];

        foreach($columns as $column){
            $singleTermParts[] = "$column LIKE ?";
            $params[] = $searchLike;
            $types .= "s";
        }

        $whereParts[] = "(" . implode(" OR ", $singleTermParts) . ")";
    }

    if(count($whereParts) > 0){
        return " WHERE " . implode(" AND ", $whereParts);
    }

    return "";
}
?>