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
$brightpearlAppRef = $_ENV['BRIGHTPEARL_APP_REF'];
$brightpearlDevRef = $_ENV['BRIGHTPEARL_DEV_REF'];

$servername = $_ENV['DBSERVER'];
$username = $_ENV['DBUSER'];
$password = $_ENV['DBPW'];
$dbname = $_ENV['DBNAME'];

$shopifyBaseUrl = "https://" . $shopifyStoreName . ".myshopify.com/admin/api/2024-04";
$brightpearlBaseUrl = "https://use1.brightpearlconnect.com/public-api/".$brightpearlAccount;

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
    
    global $servername;
    global $username;
    global $password;
    global $dbname;

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Loop through orders and insert into database
    foreach ($orders as $order) {
        $order_id = $order['id'];
        $created_at = $order['created_at'];
        $total_price = $order['total_price'];
        $financial_status = $order['financial_status'];
        $fulfillment_status = $order['fulfillment_status'];
        $sync_to_brightpearl = ''
    
        $sql = "INSERT INTO {$shopifyStoreName}_orders (order_id, created_at, total_price, financial_status, fulfillment_status, sync_to_brightpearl) VALUES ({$order_id}, {$created_at}, {$total_price}, {$financial_status}, {$fulfillment_status}, {$sync_to_brightpearl})";
        
        if ($conn->query($sql) !== TRUE) {
            echo "Error: " . $sql . "<br>" . $conn->error;
        }
    }
    
    $conn->close();

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
                    <td class="Polaris-IndexTable__TableCell"><?php echo (check_sync($client, $order['name'])) ?></td>
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

function check_sync($client, $order_id){

    global $brightpearlDevRef;
    global $brightpearlAppRef;
    global $brightpearlBaseUrl;
    global $brightpearlApiToken;

    $url = $brightpearlBaseUrl."/order-service/order-search";
    $queryParams = http_build_query(['customerRef' => $order_id]);

    try {
        $response = $client->request(
            'GET',
            "{$url}",
            [
                'headers' => [
                    'Authorization' => "Bearer ".$brightpearlApiToken,
                    'Content-Type' => 'application/json',
                    'brightpearl-dev-ref' => $brightpearlDevRef,
                    'brightpearl-app-ref' => $brightpearlAppRef
                ]
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        // Filter out orders and return the custom field "PCF_ECOMSHIP"
        return count($body['response']);
        // $orders = [];
        // foreach ($body['response'] as $order) {
        //     if (isset($order['order']['customFields']['PCF_ECOMSHIP'])) {
        //         $orders[] = [
        //             'orderId' => $order['order']['id'],
        //             'PCF_ECOMSHIP' => $order['order']['customFields']['PCF_ECOMSHIP']
        //         ];
        //     }
        // }

        // return count($orders);
    }
    catch(Exception $e){
         echo "Error finding order #:" . $order_id . " in BrightPearl.\n",  $e->getMessage(), "\n";
    }

    return true;
}

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
