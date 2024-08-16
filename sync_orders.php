<?php
require 'vendor/autoload.php';
require 'includes/database.php';
require 'includes/brightpearl.php';
ini_set('memory_limit', '4096M'); // 4GB
ini_set('max_execution_time', '0'); // unlimited
ini_set('max_input_time', '0');     // 1 hour


use GuzzleHttp\Client;
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$shopifyApiKey = $_ENV['SHOPIFY_API_KEY'];
$shopifyPassword = $_ENV['SHOPIFY_PASSWORD'];
// $shopifyStoreName = $_ENV['SHOPIFY_STORE_NAME'];
$shopifyStoreName = $_GET['shop'];

if ($shopifyStoreName !== null && $shopifyStoreName !== '') {
    echo "Store name: " . $shopifyStoreName . "<br>";
    $shopifyToken = getAccessToken($conn, $shopifyStoreName);
    $tableName = $shopifyStoreName . "_orders";
} else {
    echo "Store name not found in URL parameter.";
}


$shopifyBaseUrl = "https://" . $shopifyStoreName . "/admin/api/2024-04";

$client = new Client();

ob_start();

function createOrderTable()
{
    global $conn, $tableName;

    // Create table
    $result = $conn->query("SHOW TABLES LIKE '".$tableName."'");

    if ($result->num_rows == 0) {
        // Create the table if it does not exist
        $createTableSql = "CREATE TABLE `".$tableName."` (
            id INT(10) AUTO_INCREMENT PRIMARY KEY,
            order_id VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL,
            shipped_at TIMESTAMP NULL DEFAULT NULL,
            total_price DECIMAL(10, 2) NOT NULL,
            financial_status VARCHAR(255) NOT NULL,
            fulfillment_status VARCHAR(255),
            brightpearl_ECOMSHIP VARCHAR(255),
            brightpearl_GoodsOutNote VARCHAR(255),
            synced_at TIMESTAMP NULL DEFAULT NULL
        )";
        
        if ($conn->query($createTableSql) === TRUE) {
            // echo "Table '".$tableName."' created successfully.";
        } else {
            echo "Error creating table: " . $mysqli->error;
        }        
    }
}

function sync_orders_to_database($client, $shopifyBaseUrl, $shopifyToken)
{   
    global $conn, $shopifyStoreName, $tableName;

    $sql = "TRUNCATE TABLE `".$tableName."`";

    if ($conn->query($sql) === TRUE) {
        // echo "Table $tableName truncated successfully.";
    } else {
        echo "Error truncating table: " . $conn->error;
    }

    $orders = [];
    $page = 1;
    $limit = 250;  // You can adjust the limit to the maximum allowed by Shopify (which is 250)
    $created_at_min = '2022-12-31T18:00:00Z'; 
    date_default_timezone_set('UTC');
    $utcDate = new DateTime('now', new DateTimeZone('UTC'));
    $utcDate->setTimezone(new DateTimeZone('America/Chicago'));
    $utcDate->setTime(2, 30, 0);
    $created_at_max = $utcDate->format('Y-m-d H:i:s');

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
    
    usort($orders, function($a, $b) {
        return strcmp($a['created_at'], $b['created_at']);
    });

    // Loop through orders and insert into database
    foreach ($orders as $order) {
        if ($order['fulfillment_status'] != 'fulfilled') {
            continue; // Skip the order if it is not fulfilled
        }
        $order_id = $order['name'];
        $financial_status = $order['financial_status'];
        $fulfillment_status = $order['fulfillment_status'];
        $created_at = $order['created_at'];
        $shipped_at = isset($order['fulfillments'][0]) ? $order['fulfillments'][0]['created_at'] : NULL;
        $total_price = $order['total_price'];
        $synced_at = $created_at_max;

        if ($fulfillment_status == 'fulfilled') {
            usleep(300000); 
            list($brightpearl_ECOMSHIP, $brightpearl_GoodsOutNote) = update_brightpearl_fields($client, $order_id, $shipped_at);
        } 
        
        $stmt = $conn->prepare("INSERT INTO `" . $shopifyStoreName . "_orders` (
            order_id, created_at, shipped_at, total_price, financial_status, fulfillment_status, brightpearl_ECOMSHIP, brightpearl_GoodsOutNote, synced_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
        $stmt->bind_param(
            "sssssssss",
            $order_id,
            $created_at,
            $shipped_at,
            $total_price,
            $financial_status,
            $fulfillment_status,
            $brightpearl_ECOMSHIP,
            $brightpearl_GoodsOutNote,
            $synced_at
        );
    
        if (!$stmt->execute()) {
            echo "Error: " . $stmt->error;
        }
    
        $stmt->close();
    }
    
    // $conn->close();
}


function update_brightpearl_fields($client, $order_id, $shipped_at){

    global $brightpearlDevRef;
    global $brightpearlAppRef;
    global $brightpearlBaseUrl;
    global $brightpearlApiToken;

    $url = $brightpearlBaseUrl."/order-service/order-search";
    $queryParams = http_build_query(['customerRef' => $order_id]);

    try {
        $response = $client->request(
            'GET',
            "{$url}?{$queryParams}",
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
        if(isset($body['response']['results']) && !empty($body['response']['results'])) {
            foreach($body['response']['results'] as $order) {
                // The order ID is the first element in the array
                $orderId = $order[0];
                return [update_brightpearl_shipdate($client, $orderId, $shipped_at), update_brightpearl_goodsout($client, $orderId, $shipped_at)];
            }
        } else {
            return ['Error finding order #', 'Error finding order #'];
        }
    }
    catch(Exception $e){
        $responseBody = $e->getResponse()->getBody()->getContents();
        error_log("Error finding order #:" . $order_id . " in BrightPearl.\n");
        error_log($e->getMessage(). "\n");
        if (strpos($responseBody, 'Authorization token expired') !== false) {
            refreshBrightpearlToken($client);
            createOrderTable();
            sync_orders_to_database($client, $shopifyBaseUrl, $shopifyToken);
        }
        return [$responseBody, $responseBody];
    }

}

function update_brightpearl_shipdate($client, $orderId, $shipped_at) {
    
    global $brightpearlDevRef;
    global $brightpearlAppRef;
    global $brightpearlBaseUrl;
    global $brightpearlApiToken;

    $url = $brightpearlBaseUrl."/order-service/order/".$orderId."/custom-field";
    $body = [
        [
            "op" => "replace",
            "path" => "/PCF_ECOMSHIP",
            "value" => (new DateTime($shipped_at))->format('Y-m-d') . "T00:00:00.000-05:00" 
        ]
    ];

    try {
        $response = $client->request(
            'PATCH',
            $url,
            [
                'headers' => [
                    'Authorization' => "Bearer ".$brightpearlApiToken,
                    'Content-Type' => 'application/json',
                    'brightpearl-dev-ref' => $brightpearlDevRef,
                    'brightpearl-app-ref' => $brightpearlAppRef
                ],
                'json' => $body
            ]
        );

        return (new DateTime($shipped_at))->format('Y-m-d');        
    } catch (Exception $e) {
        error_log("Error patching PCF_ECOMSHIP for order #: " . $orderId . " in BrightPearl.\n");
        error_log($e->getMessage() . "\n");
        $responseBody = $e->getResponse()->getBody()->getContents();
        if (strpos($responseBody, 'Authorization token expired') !== false) {
            refreshBrightpearlToken($client);
            return update_brightpearl_shipdate($client, $orderId, $shipped_at);
        }
        if (strpos($responseBody, 'no such path') !== false) {
            $body = [
                [
                    "op" => "add",
                    "path" => "/PCF_ECOMSHIP",
                    "value" => (new DateTime($shipped_at))->format('Y-m-d') . "T00:00:00.000-05:00" 
                ]
            ];
            try {
                $response = $client->request(
                    'PATCH',
                    $url,
                    [
                        'headers' => [
                            'Authorization' => "Bearer ".$brightpearlApiToken,
                            'Content-Type' => 'application/json',
                            'brightpearl-dev-ref' => $brightpearlDevRef,
                            'brightpearl-app-ref' => $brightpearlAppRef
                        ],
                        'json' => $body
                    ]
                );
        
                return (new DateTime($shipped_at))->format('Y-m-d');        
            }
            catch (Exception $e) {
                return $e->getMessage();   
            }
        }
    }
}

function update_brightpearl_goodsout($client, $orderId, $shipped_at) {
    
    global $brightpearlDevRef;
    global $brightpearlAppRef;
    global $brightpearlBaseUrl;
    global $brightpearlApiToken;

    $url = $brightpearlBaseUrl."/warehouse-service/order/" . $orderId . "/goods-note/goods-out";

    try {
        $response = $client->request(
            'GET',
            $url,
            [
                'headers' => [
                    'Authorization' => "Bearer " . $brightpearlApiToken,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'brightpearl-dev-ref' => $brightpearlDevRef,
                    'brightpearl-app-ref' => $brightpearlAppRef
                ]
            ]
        );

        $result = json_decode($response->getBody()->getContents(), true);

        $notes = "";

        if (empty($result['response'])){
            $notes = "Error: No GON fields";
        }
        else{
            foreach($result['response'] as $noteId=>$res){
                $notes = $notes . 'Note ID: ' . $noteId . "\n";
                if (isset($res['status']['shipped']) && $res['status']['shipped'] == 1) {
                    $notes = $notes . 'shipped: ' . $res['status']['shipped'] . "\n";
                } 
                if (isset($res['status']['packed']) && $res['status']['packed'] == 1) {
                    $notes = $notes . 'packed: ' . $res['status']['packed'] . "\n";
                }
                if (isset($res['status']['picked']) && $res['status']['picked'] == 1) {
                    $notes = $notes . 'picked: ' . $res['status']['picked'] . "\n";
                }
                if (isset($res['status']['printed']) && $res['status']['printed'] == 1) {
                    $notes = $notes . 'printed: ' . $res['status']['printed'] . "\n";
                }
                if (!isset($res['status']['shipped']) && !isset($res['status']['packed']) && !isset($res['status']['picked']) && !isset($res['status']['printed'])) {
                    $notes = "Error: None of packed, printed, picked, shipped is set.";
                }
                if ($res['status']['shipped'] == 0 && $res['status']['packed'] == 0 && $res['status']['picked'] == 0 && $res['status']['printed'] ==0) {
                    $notes = "packed:0, printed:0, packed:0, shipped:0";
                }
            }
        }

        return $notes;
        // else {
            
        //     $url = $brightpearlBaseUrl."/warehouse-service/goods-note/goods-out/" . $orderId . "/event";
        //     $body = [
        //         "events" => [
        //             [
        //                 "eventCode" => "SHW",
        //                 "occured" => $shipped_at,
        //                 "eventOwnerId" => 74672
        //             ]
        //         ]
        //     ];
        //     try {
        //         $response = $client->request(
        //             'POST',
        //             $url,
        //             [
        //                 'headers' => [
        //                     'Authorization' => "Bearer ".$brightpearlApiToken,
        //                     'Content-Type' => 'application/json',
        //                     'brightpearl-dev-ref' => $brightpearlDevRef,
        //                     'brightpearl-app-ref' => $brightpearlAppRef
        //                 ],
        //                 'json' => $body
        //             ]
        //         );

        //         return 'shipped';
        //     } catch (Exception $e) {
        //         error_log("Error updating Goods-Out Note for order #:" . $orderId . " in BrightPearl.\n");
        //         error_log($e->getMessage() . "\n");
        //         return NULL; 
        //     }
        // }

    } catch (Exception $e) {
        error_log("Error fetching Goods-Out Note for order #: " . $orderId . " in BrightPearl.\n");
        error_log($e->getMessage() . "\n");
        return "Error fetching GON"; 
    }
}

function refreshBrightpearlToken($client) {
    global $brightpearlApiToken, $brightpearlRefreshToken, $brightpearlAccount, $brightpearlAppRef;

    try {
        $response = $client->post('https://oauth.brightpearlapp.com/token/'.$brightpearlAccount, [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $brightpearlRefreshToken,
                'client_id' => $brightpearlAppRef
                // Include any other necessary parameters here
            ]
        ]);

        $data = json_decode($response->getBody(), true);
        $newApiToken = $data['access_token'];
        $newRefreshToken = $data['refresh_token'];

        // Update the .env file with the new tokens
        updateEnvFile($newApiToken, $newRefreshToken);
    } catch (ClientException $e) {
        $responseBody = $e->getResponse()->getBody()->getContents();
        error_log('Error refreshing token: ' . $e->getMessage() . "\nResponse: " . $responseBody);
    }
}

function updateEnvFile($apiToken, $refreshToken) {
    $envFilePath = __DIR__ . '/.env';
    $envContent = file_get_contents($envFilePath);

    $envContent = preg_replace('/^BRIGHTPEARL_API_TOKEN=.*$/m', 'BRIGHTPEARL_API_TOKEN=' . $apiToken, $envContent);
    $envContent = preg_replace('/^BRIGHTPEARL_REFRESH_TOKEN=.*$/m', 'BRIGHTPEARL_REFRESH_TOKEN=' . $refreshToken, $envContent);

    file_put_contents($envFilePath, $envContent);
}

function getAccessToken($conn, $shopifyStoreName) {
    // Prepare the SQL statement
    $result = $conn->query("SELECT access_token FROM `shop_details` WHERE shopify_store_name = '" . $shopifyStoreName."'");
    if ($result) {
        $row = $result->fetch_assoc();
        return $row['access_token'];
    }
    else{
        die("Connection failed: " . $conn->error);
        return null;
    }
}

function getBaseUrl() {
    // Check if HTTPS is being used
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    
    // Get the server name
    $host = $_SERVER['HTTP_HOST'];
    
    // Get the request URI and remove the script name from it
    $requestUri = $_SERVER['REQUEST_URI'];
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $baseDir = str_replace(basename($scriptName), '', $scriptName);
    
    // Construct the base URL
    $baseUrl = $protocol . $host . $baseDir;
    
    return $baseUrl;
}

createOrderTable();
sync_orders_to_database($client, $shopifyBaseUrl, $shopifyToken);
header("Location: " . getBaseUrl() . 'index.php?shop=' . $shopifyStoreName);
 