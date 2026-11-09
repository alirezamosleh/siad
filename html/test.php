
<?php
echo "=== MOODLE REVERSE PROXY DEBUG ===\n\n";

echo "1. SERVER VARIABLES:\n";
echo "REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'NOT SET') . "\n";
echo "SERVER_ADDR: " . ($_SERVER['SERVER_ADDR'] ?? 'NOT SET') . "\n";
echo "SERVER_PORT: " . ($_SERVER['SERVER_PORT'] ?? 'NOT SET') . "\n";
echo "HTTPS: " . ($_SERVER['HTTPS'] ?? 'NOT SET') . "\n";
echo "REQUEST_SCHEME: " . ($_SERVER['REQUEST_SCHEME'] ?? 'NOT SET') . "\n\n";

echo "2. PROXY HEADERS:\n";
echo "HTTP_X_FORWARDED_FOR: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'NOT SET') . "\n";
echo "HTTP_X_FORWARDED_PROTO: " . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'NOT SET') . "\n";
echo "HTTP_X_FORWARDED_PORT: " . ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? 'NOT SET') . "\n";
echo "HTTP_X_FORWARDED_HOST: " . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? 'NOT SET') . "\n";
echo "HTTP_X_FORWARDED_SSL: " . ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? 'NOT SET') . "\n";
echo "HTTP_X_REAL_IP: " . ($_SERVER['HTTP_X_REAL_IP'] ?? 'NOT SET') . "\n\n";

echo "3. IP ADDRESS ANALYSIS:\n";
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
echo "Nginx container IP: $remote_ip\n";

// Check if IP is in Docker ranges
function ip_in_range($ip, $range) {
    list($subnet, $mask) = explode('/', $range);
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask_long = ~((1 << (32 - $mask)) - 1);
    return ($ip_long & $mask_long) == ($subnet_long & $mask_long);
}

$docker_ranges = array(
    '10.0.0.0/8',
    '172.16.0.0/12',
    '192.168.0.0/16',
    '127.0.0.1/32'
);

echo "\nChecking if $remote_ip is in trusted ranges:\n";
foreach ($docker_ranges as $range) {
    $in_range = ip_in_range($remote_ip, $range) ? 'YES ✓' : 'NO ✗';
    echo "  $range: $in_range\n";
}

echo "\n4. DOCKER NETWORK INFO:\n";
echo "Container hostname: " . gethostname() . "\n";

echo "\n5. ALL HTTP HEADERS:\n";
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        echo "$key: $value\n";
    }
}

echo "\n6. MOODLE CONFIG CHECK:\n";
if (file_exists(__DIR__ . '/config.php')) {
    echo "config.php exists: YES\n";

    // Try to load and check settings
    include_once(__DIR__ . '/config.php');

    if (isset($CFG)) {
        echo "CFG->reverseproxy: " . ($CFG->reverseproxy ? 'TRUE' : 'FALSE') . "\n";
        echo "CFG->sslproxy: " . ($CFG->sslproxy ? 'TRUE' : 'FALSE') . "\n";
        echo "CFG->wwwroot: " . ($CFG->wwwroot ?? 'NOT SET') . "\n";

        if (isset($CFG->trusted_proxies)) {
            echo "CFG->trusted_proxies:\n";
            foreach ($CFG->trusted_proxies as $proxy) {
                echo "  - $proxy\n";
            }
        } else {
            echo "CFG->trusted_proxies: NOT SET ❌\n";
        }
    }
} else {
    echo "config.php: NOT FOUND ❌\n";
}

echo "\n=== END DEBUG ===\n";
?>
