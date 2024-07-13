<?php

require 'vendor/autoload.php';
use GuzzleHttp\Client;
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$cssPath = './css/style.css';
echo '<link rel="stylesheet" type="text/css" href="' . $cssPath . '">';

// Brightpearl connection parameters
$brightpearlApiToken = $_ENV['BRIGHTPEARL_API_TOKEN'];
$brightpearlRefreshToken = $_ENV['BRIGHTPEARL_REFRESH_TOKEN'];
$brightpearlAccount = $_ENV['BRIGHTPEARL_ACCOUNT'];
$brightpearlWarehouseId = $_ENV['BRIGHTPEARL_WAREHOUSE_ID'];
$brightpearlAppRef = $_ENV['BRIGHTPEARL_APP_REF'];
$brightpearlDevRef = $_ENV['BRIGHTPEARL_DEV_REF'];

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

$shopifyApiKey = $_ENV['SHOPIFY_API_KEY'];
$shopifyPassword = $_ENV['SHOPIFY_PASSWORD'];
$shopifyStoreName = $_ENV['SHOPIFY_STORE_NAME'];

// $headers = getallheaders();
// if (isset($headers['X-Shopify-Shop-Domain'])) {
//     $shopifyStoreName = $headers['X-Shopify-Shop-Domain'];
//     echo "Shop name: " . $shopifyStoreName;
// } else {
//     echo "Shop domain header is missing.";
// }

// $shopifyStoreName = $_SESSION['shop'];
// $shopifyStoreName = getStoreNameFromUrl($_SERVER['REQUEST_URI']);

if ($shopifyStoreName !== null && $shopifyStoreName !== '') {
    // echo "Store name: " . $shopifyStoreName;
    $shopifyToken = getAccessToken($conn, $shopifyStoreName);
    $tableName = $shopifyStoreName . "_orders";
} else {
    // echo "Store name not found.";
}


$shopifyBaseUrl = "https://" . $shopifyStoreName . ".myshopify.com/admin/api/2024-04";
$brightpearlBaseUrl = "https://use1.brightpearlconnect.com/public-api/".$brightpearlAccount;

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
            shipped_at TIMESTAMP,
            total_price DECIMAL(10, 2) NOT NULL,
            financial_status VARCHAR(255) NOT NULL,
            fulfillment_status VARCHAR(255),
            brightpearl_ECOMSHIP DATE,
            brightpearl_GoodsOutNote VARCHAR(255)
        )";
        
        if ($conn->query($createTableSql) === TRUE) {
            echo "Table '".$tableName."' created successfully.";
        } else {
            echo "Error creating table: " . $mysqli->error;
        }
    } else {
        $sql = "TRUNCATE TABLE `".$tableName."`";

        if ($conn->query($sql) === TRUE) {
            // echo "Table $tableName truncated successfully.";
        } else {
            echo "Error truncating table: " . $conn->error;
        }
    }
}

function sync_orders_to_database($client, $shopifyBaseUrl, $shopifyToken)
{
    global $conn, $shopifyStoreName;

    $orders = [];
    $page = 1;
    $limit = 250;  // You can adjust the limit to the maximum allowed by Shopify (which is 250)
    $created_at_min = '2023-01-01T00:00:00Z'; 
    $created_at_max = date('Y-m-d H:i:s'); 

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
    
    // Loop through orders and insert into database
    foreach ($orders as $order) {
        $order_id = $order['name'];
        $financial_status = $order['financial_status'];
        $fulfillment_status = $order['fulfillment_status'];
        $created_at = $order['created_at'];
        $shipped_at = isset($order['fulfillments'][0]['created_at']) ? $order['fulfillments'][0]['created_at'] : NULL;
        $total_price = $order['total_price'];
    
        if ($fulfillment_status == 'fulfilled') {
            list($brightpearl_ECOMSHIP, $brightpearl_GoodsOutNote) = update_brightpearl_fields($client, $order_id, $shipped_at);
        } else {
            $fulfillment_status = 'unfulfilled';
            $brightpearl_ECOMSHIP = NULL;
            $brightpearl_GoodsOutNote = NULL;
        }
    
        global $conn;
    
        $stmt = $conn->prepare("INSERT INTO `" . $shopifyStoreName . "_orders` (
            order_id, created_at, shipped_at, total_price, financial_status, fulfillment_status, brightpearl_ECOMSHIP, brightpearl_GoodsOutNote
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
        $stmt->bind_param(
            "ssssssss",
            $order_id,
            $created_at,
            $shipped_at,
            $total_price,
            $financial_status,
            $fulfillment_status,
            $brightpearl_ECOMSHIP,
            $brightpearl_GoodsOutNote
        );
    
        if (!$stmt->execute()) {
            echo "Error: " . $stmt->error;
        }
    
        $stmt->close();
    }
    
    // $conn->close();
}

function get_orders_from_database(){

    global $conn, $tableName;
    $result = $conn->query("SELECT * FROM `" . $tableName . "`");
    
    if ($result->num_rows > 0) {
        // Start the HTML table
        echo '<div class="table-top-container"><p>Total orders fetched: ' . $result->num_rows . "</p>";
        echo '<form action="index.php" method="post"><button type="submit" name="resync_orders">Sync All Orders</button></form></div>';

        echo "<table border='1' id='leadstbl'>";
        echo "<tr><th>ID</th><th>Order ID</th><th>Created At</th><th>Ship Date</th><th>Total Price</th><th>Payment Status</th><th>Fulfillment Status</th><th>Brightpearl PCF_ECOMSHIP</th><th>Brightpearl_GoodsOutNote</th></tr>";
        
        // Output data of each row
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . $row['order_id'] . "</td>";
            echo "<td>" . $row['created_at'] . "</td>";
            echo "<td>" . $row['shipped_at'] . "</td>";
            echo "<td>" . $row['total_price'] . "</td>";
            echo "<td>" . $row['financial_status'] . "</td>";
            echo "<td>" . $row['fulfillment_status'] . "</td>";
            echo "<td>" . $row['brightpearl_ECOMSHIP'] . "</td>";
            echo "<td>" . $row['brightpearl_GoodsOutNote'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo '<div class="table-top-container"><p>Total orders fetched: ' . $result->num_rows . "</p>";
        echo '<form action="index.php" method="post"><button type="submit" name="resync_orders">Sync All Orders</button></form></div>';
    }
    
    
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
            return [NULL, NULL];
        }
    }
    catch(Exception $e){
        $responseBody = $e->getResponse()->getBody()->getContents();
        error_log("Error finding order #:" . $order_id . " in BrightPearl.\n");
        error_log($e->getMessage(). "\n");
        if (strpos($responseBody, 'Authorization token expired') !== false) {
            // Token expired, refresh the token
            refreshBrightpearlToken($client);
            
            // Restart the app
            createOrderTable();
            get_orders_from_database();
        }
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

        $result = json_decode($response->getBody()->getContents(), true);

        // Check if PCF_ECOMSHIP was updated successfully
        if (isset($result['response']['PCF_ECOMSHIP'])) {
            return $result['response']['PCF_ECOMSHIP'];
        } else {
            error_log("Error updating PCF_ECOMSHIP for order #:" . $orderId . " in BrightPearl.\n");
            error_log($e->getMessage() . "\n");
            return null; // Handle if PCF_ECOMSHIP wasn't updated
        }

    } catch (Exception $e) {
        error_log("Error patching PCF_ECOMSHIP for order #: " . $orderId . " in BrightPearl.\n");
        error_log($e->getMessage() . "\n");
        return null; // Handle the error gracefully, possibly log it
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

        // Check if PCF_ECOMSHIP was updated successfully
        $notes = "";

        foreach($result['response'] as $noteId=>$res){
            $notes = $notes . 'Note ID: ' . $noteId . "\n";
            if (isset($res['status']['shipped'])) {
                $notes = $notes . 'shipped: ' . $res['status']['shipped'] . "\n";
            } 
            if (isset($res['status']['packed'])) {
                $notes = $notes . 'packed: ' . $res['status']['packed'] . "\n";
            }
            if (isset($res['status']['picked'])) {
                $notes = $notes . 'picked: ' . $res['status']['picked'] . "\n";
            }
            if (isset($res['status']['printed'])) {
                $notes = $notes . 'printed: ' . $res['status']['printed'] . "\n";
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

function getStoreNameFromUrl($url) {
    // Use parse_url to get the path component of the URL
    $parsedUrl = parse_url($url);
    $path = $parsedUrl['path'];

    // Use a regular expression to extract the store name
    preg_match('/\/store\/([^\/]+)\//', $path, $matches);

    if (isset($matches[1])) {
        return $matches[1];
    } else {
        return null;
    }
}

function getAccessToken($conn, $shopifyStoreName) {
    // Prepare the SQL statement
    $result = $conn->query("SELECT access_token FROM `shop_details` WHERE shopify_store_name = '" . $shopifyStoreName.".myshopify.com'");
    if ($result) {
        $row = $result->fetch_assoc();
        return $row['access_token'];
    }
    else{
        die("Connection failed: " . $conn->error);
        return null;
    }
}

createOrderTable();
get_orders_from_database();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resync_orders'])) {
    // Call the PHP function
    ob_clean();
    createOrderTable();
    sync_orders_to_database($client, $shopifyBaseUrl, $shopifyToken);
    get_orders_from_database();
}

$conn->close();