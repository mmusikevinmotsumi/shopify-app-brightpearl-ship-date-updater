<?php

require 'vendor/autoload.php';
use GuzzleHttp\Client;
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$cssPath = './css/style.css';
echo '<link rel="stylesheet" type="text/css" href="' . $cssPath . '">';

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
    $orders = [];
    $page = 1;
    $limit = 250;  // You can adjust the limit to the maximum allowed by Shopify (which is 250)
    $created_at_min = '2023-01-01T00:00:00Z'; // Start of January 2023
    $created_at_max = '2024-06-24T23:59:59Z'; // End of Jun 24, 2024

    $url = $shopifyBaseUrl . "/orders.json?status=any&limit={$limit}&created_at_min={$created_at_min}&created_at_max={$created_at_max}";

    do {
        $response = $client->request(
            'GET',
            $url,
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $shopifyToken,
                    'Content-Type' => 'application/json'
                ]
            ]
        );
    
        // Decode the JSON response
        $data = json_decode($response->getBody()->getContents(), true);
        $orders = array_merge($orders, $data['orders']);
    
        // Get pagination link headers
        $linkHeader = $response->getHeader('Link');
    
        if (!empty($linkHeader)) {
            // Parse the Link header
            $links = explode(',', $linkHeader[0]);
            $nextPageUrl = null;
    
            foreach ($links as $link) {
                if (strpos($link, 'rel="next"') !== false) {
                    $nextPageUrl = trim(explode(';', $link)[0], '<> ');
                    break;
                }
            }
    
            // If there is a next page URL, set it for the next request
            if ($nextPageUrl) {
                $url = $nextPageUrl;
            } else {
                break;
            }
        } else {
            break;
        }
    
        $page++;
    } while (!empty($data['orders']));
    
    // Now $orders contains all the orders from January 2023
    echo 'Total orders fetched: ' . count($orders) . "\n";
    ?>
    <table id="leadstbl" style="width:100%;" class="Polaris-IndexTable__Table Polaris-IndexTable__Table--sortable Polaris-IndexTable__Table--sticky"">
        <thead>
            <th class="Polaris-IndexTable__TableHeading Polaris-IndexTable__TableHeading--sortable Polaris-IndexTable__TableHeading--first">Order #</th>
            <th class="Polaris-IndexTable__TableHeading Polaris-IndexTable__TableHeading--second Polaris-IndexTable__TableHeading--sortable">Fulfillment Status</th>
            <th class="Polaris-IndexTable__TableHeading Polaris-IndexTable__TableHeading--sortable">Ship Date</th>
            <th class="Polaris-IndexTable__TableHeading Polaris-IndexTable__TableHeading--sortable">BrightPearl Status</th>
        </thead>
        <?php
        foreach ($orders as $order) {
            if (isset($order['fulfillments'][0])) {
                $fulfillment = $order['fulfillments'][0];
                $shipDate = substr($fulfillment['created_at'], 0, 10); // Get only the date part
            ?>
                <tr>
                    <td class="Polaris-IndexTable__TableCell"><?php echo $order['name'] ?></td>
                    <td class="Polaris-IndexTable__TableCell"><?php echo $fulfillment['status'] ?></td>
                    <td class="Polaris-IndexTable__TableCell"><?php echo $shipDate ?></td>
                    <td class="Polaris-IndexTable__TableCell"><?php echo (check_sync($order['id'])) ? 'Synced' : '--'; ?></td>
                </tr>
            <?php
                // echo "Order ID: " . $order['id'] . "\n" . "Ship Date: " . $shipDate . "\n";
                // updateBrightPearlOrder($client, $brightpearlBaseUrl, $brightpearlApiToken, $brightpearlWarehouseId, $order['id'], $shipDate);

            }
            else{
            }
        }
    ?>
    </table>
    <?php
    // print_r($orders);
}

function check_sync($order_id){
    return true;
}
// function getNumberOfOrders($client, $shopifyBaseUrl, $shopifyToken)
// {
//     try {
//         $response = $client->request(
//             'GET',
//             $shopifyBaseUrl . "/orders/count.json",
//             [
//                 'headers' => [
//                     'X-Shopify-Access-Token' => $shopifyToken,
//                     'Content-Type' => 'application/json'
//                 ]
//             ]
//         );

//         $body = $response->getBody()->getContents();
//         $data = json_decode($body, true); // Parse the JSON response

//         if (isset($data['count'])) {
//             echo "Total Shopify Orders: " . $data['count']."\n";
//         } else {
//             echo "Count not found in the response";
//         }

//     } catch (Exception $e) {
//         echo 'Error fetching orders from Shopify: ',  $e->getMessage(), "\n";
//         return [];
//     }
// }

function updateOrders($client, $shopifyBaseUrl, $brightpearlBaseUrl, $brightpearlApiToken, $brightpearlWarehouseId) {

    $orders = getFulfilledOrders($client, $shopifyBaseUrl);

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

// getNumberOfOrders($client, $shopifyBaseUrl, $shopifyToken);

getFulfilledOrders($client, $shopifyBaseUrl, $shopifyToken);
