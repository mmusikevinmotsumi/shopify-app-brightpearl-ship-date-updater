<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$shopifyApiKey = $_ENV['SHOPIFY_API_KEY'];
$shopifyPassword = $_ENV['SHOPIFY_PASSWORD'];
$shopifyStoreName = $_ENV['SHOPIFY_STORE_NAME'];
$brightpearlApiToken = $_ENV['BRIGHTPEARL_API_TOKEN'];
$brightpearlAccount = $_ENV['BRIGHTPEARL_ACCOUNT'];
// $brightpearlWarehouseId = $_ENV['BRIGHTPEARL_WAREHOUSE_ID'];

$shopifyBaseUrl = "https://$shopifyApiKey:$shopifyPassword@$shopifyStoreName.myshopify.com/admin/api/2024-04";
$brightpearlBaseUrl = "https://ws-eu1.brightpearl.com/$brightpearlAccount";

$client = new Client();

function getFulfilledOrders($client, $shopifyBaseUrl) {
    try {
        $response = $client->request('GET', "$shopifyBaseUrl/orders.json");
        return json_decode($response->getBody()->getContents(), true)['orders'];
    } catch (Exception $e) {
        echo 'Error fetching orders from Shopify: ',  $e->getMessage(), "\n";
        return [];
    }
}

getFulfilledOrders($client, $shopifyBaseUrl);

// function updateBrightPearlOrder($client, $brightpearlBaseUrl, $brightpearlApiToken, $brightpearlWarehouseId, $orderId, $shipDate) {
//     try {
//         // Update custom field
//         $response = $client->request('PATCH', "$brightpearlBaseUrl/order-service/order/$orderId/custom-field/", [
//             'json' => [
//                 'customFieldId' => 'eCommerce Ship Date',
//                 'value' => $shipDate
//             ],
//             'headers' => [
//                 'Authorization' => "Bearer $brightpearlApiToken",
//                 'Content-Type' => 'application/json'
//             ]
//         ]);

//         // Update Goods Out Note as shipped
//         $response = $client->request('POST', "$brightpearlBaseUrl/warehouse-service/goods-out-note-event", [
//             'json' => [
//                 'goodsOutNoteId' => $orderId,
//                 'status' => 'shipped',
//                 'warehouseId' => $brightpearlWarehouseId
//             ],
//             'headers' => [
//                 'Authorization' => "Bearer $brightpearlApiToken",
//                 'Content-Type' => 'application/json'
//             ]
//         ]);

//     } catch (Exception $e) {
//         echo "Error updating BrightPearl for order $orderId: ",  $e->getMessage(), "\n";
//     }
// }

// function updateOrders($client, $shopifyBaseUrl, $brightpearlBaseUrl, $brightpearlApiToken, $brightpearlWarehouseId) {
//     $orders = getFulfilledOrders($client, $shopifyBaseUrl);
//     foreach ($orders as $order) {
//         if (isset($order['fulfillments'][0])) {
//             $fulfillment = $order['fulfillments'][0];
//             $shipDate = substr($fulfillment['created_at'], 0, 10); // Get only the date part
//             updateBrightPearlOrder($client, $brightpearlBaseUrl, $brightpearlApiToken, $brightpearlWarehouseId, $order['id'], $shipDate);
//         }
//     }
// }

// // Update orders immediately
// updateOrders($client, $shopifyBaseUrl, $brightpearlBaseUrl, $brightpearlApiToken, $brightpearlWarehouseId);
