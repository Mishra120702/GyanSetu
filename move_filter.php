<?php
$file = 'c:\xampp\htdocs\_public_html (4)\content\upload_content.php';
$content = file_get_contents($file);

$startMarker = '<!-- Search and Filters -->';
$endMarker = '<!-- Upload/Edit Form -->';

// Find start and end of the block
$startPos = strpos($content, $startMarker);
$endPos = strpos($content, $endMarker);

if ($startPos !== false && $endPos !== false) {
    // We want to capture up to <?php endif; ?> before Upload/Edit Form
    // Actually just string matching:
    $searchBlock = substr($content, $startPos, $endPos - $startPos);
    
    // Remove the block from the original content
    $content = substr_replace($content, '', $startPos, $endPos - $startPos);
    
    // Find where to insert: before <!-- Uploaded Content Table -->
    $tableMarker = '<!-- Uploaded Content Table -->';
    // We want to insert it right before <?php if (!$content_to_edit): ?> which is before tableMarker
    $tablePos = strpos($content, $tableMarker);
    
    // Find the <?php if (!$content_to_edit): ?> right before it
    $insertPos = strrpos(substr($content, 0, $tablePos), '<?php if (!$content_to_edit): ?>');
    
    if ($insertPos !== false) {
        $content = substr_replace($content, $searchBlock, $insertPos, 0);
        file_put_contents($file, $content);
        echo "Successfully moved block.\n";
    } else {
        echo "Could not find insert position.\n";
    }
} else {
    echo "Could not find start or end marker.\n";
}
?>
