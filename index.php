<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brightpear Ship Date Updater</title>
    <!-- Include your JavaScript file -->
    <script src="/brightpearl/js/script.js" defer></script>
</head>
<?php

    require 'vendor/autoload.php';
    require 'includes/database.php';

    use GuzzleHttp\Client;
    $cssPath = './css/style.css?v=' . time();
    echo '<link rel="stylesheet" type="text/css" href="' . $cssPath . '">';
    $jsPath = './js/script.js?v=' . time();
    echo '<link rel="" type="text/css" href="' . $cssPath . '">';

    $shopifyStoreName = $_GET['shop'];

    if ($shopifyStoreName !== null && $shopifyStoreName !== '') {
        $tableName = $shopifyStoreName . "_orders";
    } else {
        echo "Store data is not in database.";
    }

    function get_orders_from_database(){

        global $conn, $tableName, $shopifyStoreName;
        $result = $conn->query("SELECT * FROM `" . $tableName . "`");
        
        if ($result->num_rows > 0) {
            // Start the HTML table
            echo '<div class="content"><div class="table-top-container"><p>Store name: ' . $shopifyStoreName . '<br><p>Total orders fetched: ' . $result->num_rows . '</p>';
            echo '<form id="syncForm" action="https://soodletech.com/brightpearl-ship-date-updater/sync_orders.php?shop=' . $shopifyStoreName . '" method="post">
                    <button id="syncButton" type="submit" name="resync_orders">Sync All Orders</button>
                  </form></div>';
        
            echo "<table border='1' id='leadstbl'>";
            echo "<tr><th>ID</th><th>Order ID</th><th>Created At</th><th>Ship Date</th><th>Total Price</th><th>Payment Status</th><th>Fulfillment Status</th><th>Brightpearl PCF_ECOMSHIP</th><th>Brightpearl_GoodsOutNote</th><th>Synced at</th></tr>";
            
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
                echo "<td>" . $row['synced_at'] . "</td>";
                echo "</tr>";
            }
            echo "</table></div>";
        } else {
            echo '<div class="content"><div class="table-top-container"><p>Total orders fetched: ' . $result->num_rows . '</p>';
            echo '<form id="syncForm" action="https://soodletech.com/brightpearl-ship-date-updater/sync_orders.php?shop=' . $shopifyStoreName . '" method="post">
                <button id="syncButton" type="submit" name="resync_orders">Sync All Orders</button>
              </form></div>';
        }
        if (!$result){
            echo '<div class="content"><div class="table-top-container"><p>Store is not connected to database. </p>';
            echo '<form action="https://soodletech.com/brightpearl-ship-date-updater/sync_orders.php?shop='.$shopifyStoreName.'" method="post"><button type="submit" name="resync_orders">Sync All Orders</button></form></div></div>';

        }
        
        
    }

    $storeNames = fetchStoreNames($conn);
    echo '<div class="sidebar"><h2>Stores</h2><ul>';
    foreach ($storeNames as $storeName): 
        echo '<li '; 
        echo ($storeName == $shopifyStoreName) ? 'class="active"' : '';
        echo '>';
        echo '<a href="https://soodletech.com/brightpearl-ship-date-updater/index.php?shop=' . $storeName . '">';
        echo htmlspecialchars($storeName); 
        echo '</a>';
        echo '</li>';
    endforeach; 
    echo '</ul></div>';
    
    get_orders_from_database();

    $conn->close();

?>