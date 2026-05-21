<?php
$zipFile = 'gradesphere_upload.zip';
$extractPath = './';

if (!file_exists($zipFile)) {
    die("Error: $zipFile not found. Please upload it to this folder first.");
}

$zip = new ZipArchive;
$res = $zip->open($zipFile);
if ($res === TRUE) {
    $zip->extractTo($extractPath);
    $zip->close();
    echo "<h2 style='color:green;'>Success! All files have been extracted perfectly.</h2>";
    echo "<p>You can now delete both this file (unzip.php) and the zip file to save space.</p>";
} else {
    echo "<h2 style='color:red;'>Extraction failed! Error code: $res</h2>";
}
?>
