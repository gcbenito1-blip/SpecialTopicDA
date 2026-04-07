<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$host = 'localhost';
$db   = 'iot';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Fetch data
    $stmt = $pdo->query("SELECT count(*) as tboat, bt.boatid, boatno FROM `boattrip` as bt JOIN boat as b ON b.boatid=bt.boatid where bt.boatid <> 0 group by bt.boatid order by tboat desc limit 0,10;");
    $rows = $stmt->fetchAll();

    // Prepare arrays for Chart.js
    $labels = [];
    $salesData = [];
    $tripsData = [];

    foreach ($rows as $row) {
        $labels[] = $row['boatno'];
        $salesData[] = (int)$row['tboat'];
        $tripsData[] = (int)$row['tboat'] * 1200;
    }

    // Structure the JSON
    echo json_encode([
        "labels" => $labels,
        "datasets" => [
            [
                "type" => "bar",
                "label" => "Sales (Bar)",
                "data" => $salesData,
                "backgroundColor" => "rgba(54, 162, 235, 0.6)",
                "borderColor" => "rgb(54, 162, 235)",
                "borderWidth" => 1
            ],
            [
                "type" => "line",
                "label" => "Revenue (Line)",
                "data" => $tripsData,
                "borderColor" => "rgb(255, 99, 132)",
                "backgroundColor" => "transparent",
                "tension" => 0.3,
                "fill" => false
            ]
        ]
    ]);

} catch (\PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>
