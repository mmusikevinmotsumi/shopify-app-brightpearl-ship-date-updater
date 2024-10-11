<?php
    // Include required files
    require 'vendor/autoload.php';
    require 'includes/database.php';

    use GuzzleHttp\Client;

    // Get the Shopify store name from the URL query string
    $shopifyStoreName = $_GET['shop'] ?? null;

    // Check if shopifyStoreName exists and is valid
    if ($shopifyStoreName !== null && $shopifyStoreName !== '') {
        $tableName = $shopifyStoreName . "_orders";
    } else {
        echo "Store data is not in database.";
        exit();
    }

    // Function to export orders to CSV
    function export_orders_to_csv($conn, $tableName) {
        // Query to fetch all orders
        $result = $conn->query("SELECT * FROM `" . $tableName . "`");

        if ($result->num_rows > 0) {
            // Set headers for the CSV download
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="orders_export--'.$tableName.'.csv";');

            // Open output stream to browser
            $output = fopen('php://output', 'w');

            // Set the column headers for the CSV file
            fputcsv($output, array('ID', 'Order ID', 'Created At', 'Ship Date', 'Total Price', 'Payment Status', 'Fulfillment Status', 'Brightpearl PCF_ECOMSHIP', 'Brightpearl_GoodsOutNote', 'Synced At'));

                    // Output each row of the data
            // Output each row of the data
            while ($row = $result->fetch_assoc()) {
                // Clean Brightpearl_GoodsOutNote field for multiline data
                if (!empty($row['brightpearl_GoodsOutNote'])) {
                    // Convert any "\r\n" (Windows style) or "\r" (old Mac style) to "\n" (Linux style)
                    $row['brightpearl_GoodsOutNote'] = preg_replace("/\r\n|\r/", "\n", $row['brightpearl_GoodsOutNote']);
                }

                // Output the row to CSV
                fputcsv($output, $row);
            }

            // Close the output stream and stop script execution
            fclose($output);
            exit(); // Stop further execution after CSV export
        } else {
            echo 'No data available to export.';
        }
    }

    // Check if the Export to CSV button was clicked
    if (isset($_POST['export_to_csv'])) {
        export_orders_to_csv($conn, $tableName);
    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brightpear Ship Date Updater</title>
    <!-- Include your JavaScript file -->
    <script src="/brightpearl/js/script.js" defer></script>
    <?php
        // Cache-busting for CSS and JS
        $cssPath = './css/style.css?v=' . time();
        echo '<link rel="stylesheet" type="text/css" href="' . $cssPath . '">';
        $jsPath = './js/script.js?v=' . time();
    ?>
</head>
<body>
<?php

    // Function to get orders from the database and display them in HTML
    function get_orders_from_database(){
        global $conn, $tableName, $shopifyStoreName;
        $result = $conn->query("SELECT * FROM `" . $tableName . "`");

        if ($result->num_rows > 0) {
            // Display order details in a table
            echo '<div class="content"><div class="table-top-container"><p>Store name: ' . $shopifyStoreName . '<br><p>Total orders fetched: ' . $result->num_rows . '</p>';
            // echo '<form id="syncForm" action="https://localhost/brightpearl/sync_orders.php?shop=' . $shopifyStoreName . '" method="post">
            //         <button id="syncButton" type="submit" name="resync_orders">Sync All Orders</button>
            //       </form>';

            // Add the Export to CSV button
            echo '<form action="" method="post">
            <button id="exportButton" type="submit" name="export_to_csv">Export to CSV</button>
            </form></div>';

            // Start the table for orders
            echo "<table border='1' id='leadstbl'>";
            echo "<tr><th>ID</th><th>Order ID</th><th>Created At</th><th>Ship Date</th><th>Total Price</th><th>Payment Status</th><th>Fulfillment Status</th><th>Brightpearl PCF_ECOMSHIP</th><th>Brightpearl_GoodsOutNote</th><th>Synced at</th></tr>";

            // Output data for each row
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
            // Handle case where no orders are found
            echo '<div class="content"><div class="table-top-container"><p>Total orders fetched: ' . $result->num_rows . '</p>';
            echo '<form id="syncForm" action="https://localhost/brightpearl/sync_orders.php?shop=' . $shopifyStoreName . '" method="post">
                <button id="syncButton" type="submit" name="resync_orders">Sync All Orders</button>
              </form></div>';
        }

        if (!$result){
            echo '<div class="content"><div class="table-top-container"><p>Store is not connected to the database.</p>';
            echo '<form action="https://localhost/brightpearl/sync_orders.php?shop='.$shopifyStoreName.'" method="post"><button type="submit" name="resync_orders">Sync All Orders</button></form></div></div>';
        }               
    }

    // Fetch and display the store names
    $storeNames = fetchStoreNames($conn);
    echo '<div class="sidebar"><h2>Stores</h2><ul>';
    foreach ($storeNames as $storeName): 
        echo '<li '; 
        echo ($storeName == $shopifyStoreName) ? 'class="active"' : '';
        echo '>';
        echo '<a href="https://localhost/brightpearl/index.php?shop=' . $storeName . '">';
        echo htmlspecialchars($storeName); 
        echo '</a>';
        echo '</li>';
    endforeach; 
    echo '</ul></div>';

    // Display the orders in the table
    get_orders_from_database();

    // Close the database connection
    $conn->close();
?>
</body>
</html>
