<?php
date_default_timezone_set('Asia/Manila');

$host = "localhost";
$username = "root";
$password = "";
$dbname = "sdo_gapan_supply";

$conn = new mysqli($host, $username, $password, $dbname);

try {
    $conn = new mysqli($host, $username, $password, $dbname);
} catch (Exception $e) {
    echo "Connection failed ";
}

// Only declare the function if it doesn't already exist
if (!function_exists('time_elapsed_string')) {
    function time_elapsed_string($datetime, $full = false) {
        $now = new DateTime;
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        );
        
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }
}
?>