<?php

$google = "https://docs.google.com/presentation/d/1MZqMQoeWdeCJhlNw-l9Uqrg9UBNXmW5yjboW5h1dmFQ/edit?usp=sharing";
$google = "https://docs.google.com/presentation/d/1mEqRIc8nzIj_iEtLcNBM0oHEQXdmEkWqf_okT_46xLo/edit?usp=sharing";

$parts = explode('/', $google); 
$id = $parts[5];
$url = "https://docs.google.com/presentation/d/" . $id . "/export/jpeg?pageid=";

if (!file_exists($id)) {
    mkdir($id); // , true, 0774);
}

$contents = file_get_contents($google);
$parts = explode('DOCS_modelChunkParseStart = new Date().getTime();', $contents);
$slides = [];
$count = 0;

$jpeg = file_get_contents($url . "p");
file_put_contents("{$id}/{$count}.jpg", $jpeg);
$slides[] = "{$id}/{$count}.jpg";
$count++;

foreach ($parts as $part) {
    if (($id_start = strpos($part, 'DOCS_modelChunk = [[12,"')) !== false) {
        $id_end = strpos($part, '",', $id_start);
        $pageid = substr($part, $id_start + 24, $id_end - $id_start - 24);

        $jpeg = @file_get_contents($url . $pageid);

        if (!empty($jpeg)) {
            if (!file_exists("{$id}/{$count}.jpg")) {
                file_put_contents("{$id}/{$count}.jpg", $jpeg);
            }
            $slides[] = "{$id}/{$count}.jpg";
            $count++;
        }
    }
}
echo "<ol>";
foreach ($slides as $slide) {
    echo "<li><img src='{$slide}' /></li>";
}
echo "</ol>";
