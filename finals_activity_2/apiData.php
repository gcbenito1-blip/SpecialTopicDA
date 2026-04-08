<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Database connection
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

// Get date filter parameters
$startDate = isset($_GET['startDate']) ? $_GET['startDate'] : null;
$endDate = isset($_GET['endDate']) ? $_GET['endDate'] : null;
$report = isset($_GET['report']) ? $_GET['report'] : 'trips';

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    switch ($report) {
        case 'minDate':
            // Get earliest date from database
            $sql = "SELECT MIN(departure) as min_date FROM boattrip WHERE departure IS NOT NULL AND departure != '0000-00-00 00:00:00' AND departure != ''";
            $stmt = $pdo->query($sql);
            $row = $stmt->fetch();
            $minDate = $row['min_date'] ? substr($row['min_date'], 0, 10) : null;
            echo json_encode(['minDate' => $minDate]);
            break;
        
        case 'trips':
            // Report 1: Trips per Boat (Bar Chart)
            if ($startDate && $endDate) {
                $sql = "SELECT COUNT(*) as trip_count, b.boatid, b.boatno 
                        FROM boattrip bt 
                        JOIN boat b ON b.boatid = bt.boatid 
                        WHERE bt.boatid > 0 AND departure >= :startDate AND departure <= :endDate
                        GROUP BY b.boatid, b.boatno 
                        ORDER BY trip_count DESC 
                        LIMIT 10";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['startDate' => $startDate, 'endDate' => $endDate . ' 23:59:59']);
            } elseif ($startDate) {
                $sql = "SELECT COUNT(*) as trip_count, b.boatid, b.boatno 
                        FROM boattrip bt 
                        JOIN boat b ON b.boatid = bt.boatid 
                        WHERE bt.boatid > 0 AND departure >= :startDate
                        GROUP BY b.boatid, b.boatno 
                        ORDER BY trip_count DESC 
                        LIMIT 10";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['startDate' => $startDate]);
            } else {
                $sql = "SELECT COUNT(*) as trip_count, b.boatid, b.boatno 
                        FROM boattrip bt 
                        JOIN boat b ON b.boatid = bt.boatid 
                        WHERE bt.boatid > 0
                        GROUP BY b.boatid, b.boatno 
                        ORDER BY trip_count DESC 
                        LIMIT 10";
                $stmt = $pdo->query($sql);
            }
            $rows = $stmt->fetchAll();
            
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['boatno'];
                $data[] = (int)$row['trip_count'];
            }
            
            echo json_encode([
                'report' => 'trips_per_boat',
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Trips per Boat',
                    'data' => $data,
                    'backgroundColor' => 'rgba(54, 162, 235, 0.7)',
                    'borderColor' => 'rgba(54, 162, 235, 1)',
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        case 'availability':
            // Report 2: Boat Availability Status (Pie Chart)
            $sql = "SELECT 
                    SUM(CASE WHEN isavailable = 1 THEN 1 ELSE 0 END) as available,
                    SUM(CASE WHEN isavailable = 0 THEN 1 ELSE 0 END) as not_available
                    FROM boat";
            
            $stmt = $pdo->query($sql);
            $row = $stmt->fetch();
            
            $labels = ['Available', 'Not Available'];
            $data = [(int)$row['available'], (int)$row['not_available']];
            
            echo json_encode([
                'report' => 'boat_availability',
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Boat Status',
                    'data' => $data,
                    'backgroundColor' => [
                        'rgba(75, 192, 192, 0.7)',
                        'rgba(255, 99, 132, 0.7)'
                    ],
                    'borderColor' => [
                        'rgba(75, 192, 192, 1)',
                        'rgba(255, 99, 132, 1)'
                    ],
                    'borderWidth' => 2
                ]]
            ]);
            break;
            
        case 'size':
            // Report 3: Boat Distribution by Size (Doughnut Chart)
            $sql = "SELECT boatsize, COUNT(*) as count 
                    FROM boat 
                    WHERE boatsize IS NOT NULL AND boatsize != ''
                    GROUP BY boatsize 
                    ORDER BY count DESC";
            
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();
            
            $labels = [];
            $data = [];
            $colors = [
                'rgba(255, 99, 132, 0.7)',
                'rgba(54, 162, 235, 0.7)',
                'rgba(255, 206, 86, 0.7)',
                'rgba(75, 192, 192, 0.7)',
                'rgba(153, 102, 255, 0.7)'
            ];
            $borderColors = [
                'rgba(255, 99, 132, 1)',
                'rgba(54, 162, 235, 1)',
                'rgba(255, 206, 86, 1)',
                'rgba(75, 192, 192, 1)',
                'rgba(153, 102, 255, 1)'
            ];
            
            foreach ($rows as $i => $row) {
                $labels[] = $row['boatsize'];
                $data[] = (int)$row['count'];
            }
            
            echo json_encode([
                'report' => 'boat_size_distribution',
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Boats by Size',
                    'data' => $data,
                    'backgroundColor' => array_slice($colors, 0, count($data)),
                    'borderColor' => array_slice($borderColors, 0, count($data)),
                    'borderWidth' => 2
                ]]
            ]);
            break;
            
        case 'duration':
            // Report 4: Average Trip Duration (Linear Chart)
            // Exclude invalid dates and only include valid durations (arrival > departure)
            if ($startDate && $endDate) {
                $sql = "SELECT 
                        DATE(departure) as trip_date,
                        AVG(TIMESTAMPDIFF(MINUTE, departure, arrival)) as avg_duration
                        FROM boattrip
                        WHERE arrival IS NOT NULL 
                        AND departure IS NOT NULL
                        AND departure != '0000-00-00 00:00:00'
                        AND arrival != '0000-00-00 00:00:00'
                        AND arrival > departure
                        AND departure >= :startDate 
                        AND departure <= :endDate
                        GROUP BY DATE(departure)
                        ORDER BY trip_date ASC
                        LIMIT 60";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['startDate' => $startDate, 'endDate' => $endDate . ' 23:59:59']);
            } elseif ($startDate) {
                $sql = "SELECT 
                        DATE(departure) as trip_date,
                        AVG(TIMESTAMPDIFF(MINUTE, departure, arrival)) as avg_duration
                        FROM boattrip
                        WHERE arrival IS NOT NULL 
                        AND departure IS NOT NULL
                        AND departure != '0000-00-00 00:00:00'
                        AND arrival != '0000-00-00 00:00:00'
                        AND arrival > departure
                        AND departure >= :startDate
                        GROUP BY DATE(departure)
                        ORDER BY trip_date ASC
                        LIMIT 60";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['startDate' => $startDate]);
            } else {
                // Show all available data by default (no date restriction for historical data)
                $sql = "SELECT 
                        DATE(departure) as trip_date,
                        AVG(TIMESTAMPDIFF(MINUTE, departure, arrival)) as avg_duration
                        FROM boattrip
                        WHERE arrival IS NOT NULL 
                        AND departure IS NOT NULL
                        AND departure != '0000-00-00 00:00:00'
                        AND arrival != '0000-00-00 00:00:00'
                        AND arrival > departure
                        GROUP BY DATE(departure)
                        ORDER BY trip_date ASC
                        LIMIT 60";
                $stmt = $pdo->query($sql);
            }
            $rows = $stmt->fetchAll();
            
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['trip_date'];
                // Convert minutes to hours for better readability
                $duration = round($row['avg_duration'] / 60, 2);
                $data[] = $duration;
            }
            
            echo json_encode([
                'report' => 'avg_trip_duration',
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Avg Trip Duration (hours)',
                    'data' => $data,
                    'borderColor' => 'rgba(75, 192, 192, 1)',
                    'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                    'tension' => 0.3,
                    'fill' => true
                ]]
            ]);
            break;
            
        default:
            echo json_encode(['error' => 'Invalid report type']);
            break;
    }

} catch (\PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
