<?php
// Set variables for our request

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$shop = $_GET['shop'];

$api_key = $_ENV['SHOPIFY_API_KEY'];
$scopes = "read_all_orders,read_orders,write_orders";
$redirect_uri = 'https://soodletech.com/brightpearl-ship-date-updater/generate_token.php';

// Build install/approval URL to redirect to
$install_url = "https://" . $shop . "/admin/oauth/authorize?client_id=" . $api_key . "&scope=" . $scopes . "&redirect_uri=" . urlencode($redirect_uri);

// Redirect
header("Location: " . $install_url);
die();