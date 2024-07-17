<?php

require './vendor/autoload.php';

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable('/home2/soodlete/public_html/brightpearl-ship-date-updater');

$dotenv->load();

// Database connection parameters
$servername = $_ENV['DBSERVER'];
$username = $_ENV['DBUSER'];
$password = $_ENV['DBPW'];
$dbname = $_ENV['DBNAME'];

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function fetchStoreNames($conn) {
    $stores = [];
    $result = $conn->query("SELECT shopify_store_name FROM `shop_details`");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $stores[] = $row['shopify_store_name'];
        }
    } else {
        die("Error fetching store names: " . $conn->error);
    }
    return $stores;
}