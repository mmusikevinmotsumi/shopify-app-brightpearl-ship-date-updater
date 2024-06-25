<?php

require 'vendor/autoload.php';
use GuzzleHttp\Client;
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$shopifyApiKey = $_ENV['SHOPIFY_API_KEY'];
$shopifyPassword = $_ENV['SHOPIFY_PASSWORD'];
$shopifyStoreName = $_ENV['SHOPIFY_STORE_NAME'];
$shopifyToken = $_ENV['SHOPIFY_TOKEN'];
$brightpearlApiToken = $_ENV['BRIGHTPEARL_API_TOKEN'];
$brightpearlAccount = $_ENV['BRIGHTPEARL_ACCOUNT'];
$brightpearlWarehouseId = $_ENV['BRIGHTPEARL_WAREHOUSE_ID'];


$shopifyBaseUrl = "https://" . $shopifyStoreName . ".myshopify.com/admin/api/2024-04";
$brightpearlBaseUrl = "https://ws-eu1.brightpearl.com/".$brightpearlAccount;

$client = new Client();

function getFulfilledOrders($client, $shopifyBaseUrl, $shopifyToken)
{
    try {
        $response = $client->request(
            'GET',
            $shopifyBaseUrl . "/orders.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $shopifyToken,
                    'Content-Type' => 'application/json'
                ]
            ]
        );

        file_put_contents('order-log.txt', print_r($response->getBody()->getContents(), true), FILE_APPEND);
        return json_decode($response->getBody()->getContents(), true)['orders'];

    } catch (Exception $e) {
        echo 'Error fetching orders from Shopify: ',  $e->getMessage(), "\n";
        return [];
    }
}

function getNumberOfOrders($client, $shopifyBaseUrl, $shopifyToken)
{
    try {
        $response = $client->request(
            'GET',
            $shopifyBaseUrl . "/orders/count.json",
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $shopifyToken,
                    'Content-Type' => 'application/json'
                ]
            ]
        );

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true); // Parse the JSON response

        if (isset($data['count'])) {
            echo "Total Shopify Orders: " . $data['count'];
        } else {
            echo "Count not found in the response";
        }

    } catch (Exception $e) {
        echo 'Error fetching orders from Shopify: ',  $e->getMessage(), "\n";
        return [];
    }
}

function updateOrders($client, $shopifyBaseUrl, $brightpearlBaseUrl, $brightpearlApiToken, $brightpearlWarehouseId) {

    $orders = getFulfilledOrders($client, $shopifyBaseUrl, $shopifyToken);

    foreach ($orders as $order) {
        if (isset($order['fulfillments'][0])) {
            $fulfillment = $order['fulfillments'][0];
            $shipDate = substr($fulfillment['created_at'], 0, 10); // Get only the date part
            echo "Order ID: " . $order['id'] . "\n" . "Ship Date: " . $shipDate;
            // updateBrightPearlOrder($client, $brightpearlBaseUrl, $brightpearlApiToken, $brightpearlWarehouseId, $order['id'], $shipDate);

        }
    }
}

// getFulfilledOrders($client, $shopifyBaseUrl, $shopifyToken);

getNumberOfOrders($client, $shopifyBaseUrl, $shopifyToken);

updateOrders($client, $shopifyBaseUrl, $brightpearlBaseUrl, $brightpearlApiToken, $brightpearlWarehouseId);

