<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');

global $DB;

echo "🔍 Comparing working vs broken BBB records\n";
echo "==========================================\n\n";

// یک BBB دستی که کار می‌کند (ID 1772 یا 887)
$working = $DB->get_record('bigbluebuttonbn', ['id' => 1772]);

// یک BBB خراب (ID 1771)
$broken = $DB->get_record('bigbluebuttonbn', ['id' => 1771]);

if (!$working || !$broken) {
    echo "❌ Records not found\n";
    exit;
}

echo "🟢 Working BBB (ID: {$working->id}):\n";
echo "-----------------------------------\n";
foreach ($working as $key => $value) {
    if ($value === null) {
        echo "$key: NULL\n";
    } else if ($value === '') {
        echo "$key: (empty string)\n";
    } else {
        echo "$key: $value\n";
    }
}

echo "\n🔴 Broken BBB (ID: {$broken->id}):\n";
echo "-----------------------------------\n";
foreach ($broken as $key => $value) {
    if ($value === null) {
        echo "$key: NULL\n";
    } else if ($value === '') {
        echo "$key: (empty string)\n";
    } else {
        echo "$key: $value\n";
    }
}

echo "\n📊 DIFFERENCES:\n";
echo "-----------------------------------\n";
foreach ($working as $key => $value) {
    if ($key === 'id' || $key === 'course' || $key === 'name' || $key === 'timemodified') {
        continue; // Skip obvious different fields
    }
    
    $broken_value = $broken->$key ?? 'MISSING';
    
    if ($value !== $broken_value) {
        $w = ($value === null) ? 'NULL' : (($value === '') ? 'EMPTY' : $value);
        $b = ($broken_value === null) ? 'NULL' : (($broken_value === '') ? 'EMPTY' : $broken_value);
        echo "⚠️  $key: Working=[$w] vs Broken=[$b]\n";
    }
}

