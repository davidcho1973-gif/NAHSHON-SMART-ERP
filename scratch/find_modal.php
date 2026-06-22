<?php
$content = file_get_contents('c:/Users/handy/OneDrive/Desktop/NAHSHON-SMART-ERP/resources/views/smart-company/index.blade.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'ai-scanner-modal') !== false || strpos($line, 'ai-scan-target-category') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
