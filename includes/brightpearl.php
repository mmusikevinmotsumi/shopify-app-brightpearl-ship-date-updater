<?php
require './vendor/autoload.php';

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable('/home2/soodlete/public_html/brightpearl-ship-date-updater');
$dotenv->load();

// Brightpearl connection parameters
$brightpearlApiToken = $_ENV['BRIGHTPEARL_API_TOKEN'];
$brightpearlRefreshToken = $_ENV['BRIGHTPEARL_REFRESH_TOKEN'];
$brightpearlAccount = $_ENV['BRIGHTPEARL_ACCOUNT'];
$brightpearlWarehouseId = $_ENV['BRIGHTPEARL_WAREHOUSE_ID'];
$brightpearlAppRef = $_ENV['BRIGHTPEARL_APP_REF'];
$brightpearlDevRef = $_ENV['BRIGHTPEARL_DEV_REF'];

$brightpearlBaseUrl = "https://use1.brightpearlconnect.com/public-api/".$brightpearlAccount;
