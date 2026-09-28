<?php
$servername = "mysql";
$username = ("root");
$password = ("password");

try {
    $conn = new mysqli($servername, $username, $password, "circuleather");
    if ($conn->connect_error) {
        error_log($conn->connect_error);
        exit("Connection DB failed");
    }
} catch (Exception $e) {
    error_log($e);
    echo $e;
    exit("Connection DB failed 2");
    
}

return $conn;
