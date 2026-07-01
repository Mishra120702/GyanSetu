<?php
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__)
);

// Matches `function toggleSidebar() { ... }` with no nested braces inside
$regex = '/\s*(?:\/\/[^\n]*\n)?\s*function toggleSidebar(?:Mobile)?\(\)\s*\{[^{}]*\}/s';

$count = 0;
foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        // skip the main sidebar files where we WANT the function
        if (strpos($path, 's_sidebar.php') !== false || 
            strpos($path, 'sidebar.php') !== false ||
            strpos($path, 'cleanup_toggle.php') !== false) {
            continue;
        }
        
        $content = file_get_contents($path);
        $original = $content;
        
        $content = preg_replace($regex, '', $content);
        
        if ($content !== $original) {
            file_put_contents($path, $content);
            echo "Cleaned up: $path\n";
            $count++;
        }
    }
}
echo "Done. Cleaned $count files.\n";
