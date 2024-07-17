<?php

// Get our helper functions
// require_once("inc/functions.php");

// Set variables for our request
require 'vendor/autoload.php';
use GuzzleHttp\Client;
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$api_key = $_ENV['SHOPIFY_API_KEY'];;
$shared_secret = $_ENV['SHOPIFY_PASSWORD'];
$params = $_GET; // Retrieve all request parameters
$hmac = $_GET['hmac']; // Retrieve HMAC request parameter

$params = array_diff_key($params, array('hmac' => '')); // Remove hmac from params
ksort($params); // Sort params lexicographically
$computed_hmac = hash_hmac('sha256', http_build_query($params), $shared_secret);

$servername = $_ENV['DBSERVER'];
$username = $_ENV['DBUSER'];
$password = $_ENV['DBPW'];
$dbname = $_ENV['DBNAME'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Use hmac data to check that the response is from Shopify or not
if (hash_equals($hmac, $computed_hmac)) {
	// Set variables for our request
	$query = array(
		"client_id" => $api_key, // Your API key
		"client_secret" => $shared_secret, // Your app credentials (secret key)
		"code" => $params['code'] // Grab the access key from the URL
	);

	// Generate access token URL
	$access_token_url = "https://" . $params['shop'] . "/admin/oauth/access_token";

	// Configure curl client and execute request
	$ch = curl_init();

	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_URL, $access_token_url);
	curl_setopt($ch, CURLOPT_POST, count($query));
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($query));

	$result = curl_exec($ch);

	curl_close($ch);

	// Store the access token

	$result = json_decode($result, true);
	$access_token = $result['access_token'];
	$shopifyStoreName = $_GET['shop'];
	$tableName = 'shop_details';

	// Show the access token (don't do this in production!)
    //Here is the place where you can store the access token to the database

	session_start();

	echo 'App Successfully Installed!<br>';
	echo 'Access Token:' . $access_token;
    $_SESSION['shop'] = $shopifyStoreName;

	sync_token_to_database($tableName, $shopifyStoreName, $access_token);

} else {
	// Someone is trying to be shady!
	die('This request is NOT from Shopify!');
}

function sync_token_to_database($tableName, $shopifyStoreName, $access_token)
{
    global $conn;
	$updated_at = date('Y-m-d H:i:s');

	$stmt = $conn->prepare("SELECT * FROM `$tableName` WHERE shopify_store_name = ?");
    $stmt->bind_param('s', $shopifyStoreName);
    $stmt->execute();
    $result = $stmt->get_result();

	if ($result->num_rows > 0) {
        // Update the existing record
        $update_stmt = $conn->prepare("UPDATE `$tableName` SET access_token = ?, updated_at = ? WHERE shopify_store_name = ?");
        $update_stmt->bind_param('sss', $access_token, $updated_at, $shopifyStoreName);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert a new record
        $insert_stmt = $conn->prepare("INSERT INTO `" . $tableName . "` (shopify_store_name, access_token, updated_at) VALUES (?, ?, ?)");
        $insert_stmt->bind_param('sss', $shopifyStoreName, $access_token, $updated_at);
        $insert_stmt->execute();
        $insert_stmt->close();
    }

    $stmt->close();
    $conn->close();
}
