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
$client = new Client();
ob_start();

$shopifyApiKey = $_ENV['SHOPIFY_API_KEY'];
$shopifyPassword = $_ENV['SHOPIFY_PASSWORD'];
$shopifyStoreName = $_GET['shop'];
$brightpearlApiToken = $_ENV['BRIGHTPEARL_API_TOKEN'];
$brightpearlRefreshToken = $_ENV['BRIGHTPEARL_REFRESH_TOKEN'];


// if ($shopifyStoreName !== null && $shopifyStoreName !== '') {
//     echo "Store name: " . $shopifyStoreName . "<br>";
//     $shopifyToken = getAccessToken($conn, $shopifyStoreName);
//     $tableName = $shopifyStoreName . "_orders";
// } else {
//     echo "Store name not found in URL parameter.";
// }

$pattern = '/^[a-zA-Z0-9\-]+\.myshopify\.com$/';

if (preg_match($pattern, $shopifyStoreName)) {
    echo "Store name: " . $shopifyStoreName . "<br>";
    $shopifyToken = getAccessToken($conn, $shopifyStoreName);
    $tableName = $shopifyStoreName . "_orders";
    $shopifyBaseUrl = "https://" . $shopifyStoreName . "/admin/api/2024-04";
    createOrderTable($tableName);
    sync_orders_to_database($client, $shopifyBaseUrl, $shopifyToken, $shopifyStoreName, $tableName);

} else if ($shopifyStoreName == "all") {
    echo "Syncing all stores.<br>";
    $sql = "SELECT shopify_store_name FROM shop_details";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $shopifyStoreName = $row['shopify_store_name'];
            $shopifyBaseUrl = "https://" . $shopifyStoreName . "/admin/api/2024-04";
            echo "Syncing store: " . $shopifyStoreName . "\n";
            $shopifyToken = getAccessToken($conn, $shopifyStoreName);
            $tableName = $shopifyStoreName . "_orders";
            createOrderTable($tableName);
            sync_orders_to_database($client, $shopifyBaseUrl, $shopifyToken, $shopifyStoreName, $tableName);
        }
    } else {
        echo "No stores found in the 'shop_details' table.";
    }

} else {
    echo "Store name not found in URL parameter or invalid format.";
}

function createOrderTable($tableName)
{
    global $conn;

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

function sync_orders_to_database($client, $shopifyBaseUrl, $shopifyToken, $shopifyStoreName, $tableName)
{   
    global $conn;

    $orders = [];
    $page = 1;
    $limit = 250;  // You can adjust the limit to the maximum allowed by Shopify (which is 250)

    date_default_timezone_set('UTC');
    $utcDate = new DateTime('now', new DateTimeZone('UTC'));
    $utcDate->setTimezone(new DateTimeZone('America/Chicago'));
    $utcDate->setTime(3, 0, 0);

    $created_at_max = $utcDate->format('Y-m-d H:i:s');
    $created_at_max_utc = ($utcDate->setTimezone(new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    if ($_GET['date'] !== null && $_GET['date'] !== '') {
        $utcDate->modify('-'.$_GET['date'].' days');
        $created_at_min_utc = ($utcDate->setTimezone(new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $created_at_min = ($utcDate->setTimezone(new DateTimeZone('America/Chicago')))->format('Y-m-d H:i:s');
    }
    else{
        $created_at_min_utc = '2023-01-01T05:00:00Z';
        $sql = "TRUNCATE TABLE `".$tableName."`";

        if ($conn->query($sql) === TRUE) {
            echo "Table $tableName truncated successfully.";
        } else {
            echo "Error truncating table: " . $conn->error;
        }
    }

    // $url = $shopifyBaseUrl . "/orders.json?status=any&limit={$limit}&created_at_min={$created_at_min}&created_at_max={$created_at_max}";
    $url = $shopifyBaseUrl . "/orders.json?status=any&fulfillment_status=shipped&limit={$limit}&created_at_min={$created_at_min_utc}&created_at_max={$created_at_max_utc}";

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
    
    // Prepare the DELETE statement to remove records from the specific date and later
    $stmt = $conn->prepare("DELETE FROM `" . $shopifyStoreName . "_orders` WHERE `created_at` >= ?");

    // Bind the date parameter to the statement
    $stmt->bind_param("s", $created_at_min_utc);

    // Execute the statement
    if ($stmt->execute()) {
        echo "Records with created_at date " . $created_at_min_utc . " and later have been removed.";
    } else {
        echo "Error removing records: " . $stmt->error;
    }

    // Close the statement
    $stmt->close();


    // Loop through orders and insert into database
    foreach ($orders as $order) {
        // if ($order['fulfillment_status'] != 'fulfilled') {
        //     continue; // Skip the order if it is not fulfilled
        // }
        $order_id = $order['name'];
        $financial_status = $order['financial_status'];
        $fulfillment_status = $order['fulfillment_status'];
        $created_at = $order['created_at'];
        $shipped_at = isset($order['fulfillments'][0]) ? $order['fulfillments'][0]['created_at'] : NULL;
        $total_price = $order['total_price'];
        $synced_at = $created_at_max;

        if ($fulfillment_status == 'fulfilled') {
            list($brightpearl_ECOMSHIP, $brightpearl_GoodsOutNote) = update_brightpearl_fields($client, $order_id, $shipped_at, $shopifyStoreName, $tableName);
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
    // $sql = "UPDATE `" . $tableName. "` SET `synced_at` = '" . $created_at_max . "'";

    // // Execute the query
    // if ($conn->query($sql) === TRUE) {
    //     echo "All rows updated successfully!<br>";
    // } else {
    //     echo "Error updating rows: " . $conn->error . "<br>";
    // }
    // $conn->close();
}


function update_brightpearl_fields($client, $order_id, $shipped_at, $shopifyStoreName, $tableName){

    global $brightpearlDevRef;
    global $brightpearlAppRef;
    global $brightpearlBaseUrl;
    global $brightpearlApiToken;
    global $shopifyBaseUrl;
    global $shopifyToken;

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
        // Extract response headers
        check_brightpearl_response($response);

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
            createOrderTable($tableName);
            sync_orders_to_database($client, $shopifyBaseUrl, $shopifyToken, $shopifyStoreName, $tableName);
        }
        return [$responseBody, $responseBody];
    }

}

function check_brightpearl_response($response){
    $headers = $response->getHeaders();
    $requestsRemaining = isset($headers['brightpearl-requests-remaining'][0]) ? (int)$headers['brightpearl-requests-remaining'][0] : null;
    $nextThrottlePeriod = isset($headers['brightpearl-next-throttle-period'][0]) ? (int)$headers['brightpearl-next-throttle-period'][0] : null;

    // Check if requests remaining is 0 and if so, pause the app
    if ($requestsRemaining < 10 && $nextThrottlePeriod !== null) {
        sleep($nextThrottlePeriod / 1000 + 1); // Convert milliseconds to seconds
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
        check_brightpearl_response($response);

        return (new DateTime($shipped_at))->format('Y-m-d');        
    } catch (Exception $e) {
        // error_log("Error patching PCF_ECOMSHIP for order #: " . $orderId . " in BrightPearl.\n");
        // error_log($e->getMessage() . "\n");
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
                check_brightpearl_response($response);
        
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
        check_brightpearl_response($response);

        $result = json_decode($response->getBody()->getContents(), true);

        $notes = "";

        if (empty($result['response'])){
            $notes = "No GON fields";
            return $notes;
        }
        else{
            foreach($result['response'] as $noteId=>$res){
                $url = $brightpearlBaseUrl."/warehouse-service/goods-note/goods-out/" . $noteId . "/event";
                $body = [
                    "events" => [
                        [
                            "eventCode" => "SHW",
                            "occured" => date('Y-m-d\TH:i:s.000P', strtotime($shipped_at)),
                            "eventOwnerId" => (int)$noteId
                        ]
                    ]
                ];
                try {
                    $response = $client->request(
                        'POST',
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
                    check_brightpearl_response($response);

                    $statusCode = $response->getStatusCode();
                    $responseBody = $response->getBody()->getContents();
                
                    // Log the status and response for debugging
                    error_log("Response Status Code: " . $statusCode);
                    error_log("Response Body: " . $responseBody);

                    $notes .= "Note ID: " . $noteId . ' shipped: 1 packed: 1 picked: 1 printed: 1';
                } catch (Exception $e) {
                    error_log("Error updating Goods-Out Note for Note ID:" . $orderId . " in BrightPearl.\n");
                    error_log($e->getMessage() . "\n");
                    if (strpos($e->getMessage(), '409 Conflict') !== false){
                        $notes .= "Note ID: " . $noteId . ' shipped: 1 packed: 1 picked: 1 printed: 1';
                    }
                    else{
                        $notes .= "Note ID: " . $noteId . "\n" . $e->getMessage(); 
                    }
                }

            }
            return $notes;
        }
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
