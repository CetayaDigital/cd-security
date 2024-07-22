<?php
header('Content-Type: application/json');

// Example data for the latest version
$latest_version_info = array(
    'version' => '1.2', // Update this to your latest plugin version
    'download_url' => 'https://drive.google.com/uc?export=download&id=1NqIwcVlJE93ps_evNpzFrmqlpvDBau9m', // Direct download link from Google Drive
);

echo json_encode($latest_version_info);
?>

