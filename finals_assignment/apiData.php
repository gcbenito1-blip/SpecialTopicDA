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
    
    $report = isset($_GET['report']) ? $_GET['report'] : '';
    $startDate = isset($_GET['startDate']) ? $_GET['startDate'] : '';
    $endDate = isset($_GET['endDate']) ? $_GET['endDate'] : '';
    
    // Build date condition
    $dateCondition = '';
    $params = [];
    if ($startDate && $endDate) {
        $dateCondition = " AND departure BETWEEN ? AND ? ";
        $params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
    } elseif ($startDate) {
        $dateCondition = " AND departure >= ? ";
        $params = [$startDate . ' 00:00:00'];
    } elseif ($endDate) {
        $dateCondition = " AND departure <= ? ";
        $params = [$endDate . ' 23:59:59'];
    }
    
    switch ($report) {
        // 1. Revenue & Passenger Insights
        
        case 'monthly_revenue':
            $sql = "SELECT DATE_FORMAT(departure, '%Y-%m') as month, SUM(boatamount) as revenue 
                    FROM boattrip 
                    WHERE boatamount IS NOT NULL AND boatamount > 0 $dateCondition
                    GROUP BY DATE_FORMAT(departure, '%Y-%m') 
                    ORDER BY month";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['month'];
                $data[] = floatval($row['revenue']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Monthly Revenue',
                    'data' => $data,
                    'borderColor' => '#4CAF50',
                    'backgroundColor' => 'rgba(76, 175, 80, 0.1)',
                    'borderWidth' => 3,
                    'fill' => true,
                    'tension' => 0.4
                ]]
            ]);
            break;
            
        case 'passenger_trends':
            $sql = "SELECT DATE(departure) as date, SUM(noofpassengers) as passengers 
                    FROM boattrip 
                    WHERE noofpassengers IS NOT NULL $dateCondition
                    GROUP BY DATE(departure) 
                    ORDER BY date";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['date'];
                $data[] = intval($row['passengers']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Passengers',
                    'data' => $data,
                    'borderColor' => '#2196F3',
                    'backgroundColor' => 'rgba(33, 150, 243, 0.2)',
                    'borderWidth' => 3,
                    'fill' => true,
                    'tension' => 0.4
                ]]
            ]);
            break;
            
        case 'revenue_by_size':
            $sql = "SELECT b.boatsize, SUM(bt.boatamount) as revenue 
                    FROM boattrip bt 
                    JOIN boat b ON b.boatid = bt.boatid 
                    WHERE bt.boatamount IS NOT NULL AND bt.boatamount > 0 $dateCondition
                    GROUP BY b.boatsize 
                    ORDER BY revenue DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['boatsize'];
                $data[] = floatval($row['revenue']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Revenue by Boat Size',
                    'data' => $data,
                    'backgroundColor' => ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0'],
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        case 'paid_unpaid':
            $sql = "SELECT 
                    SUM(CASE WHEN ispaid = 1 THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN ispaid = 0 OR ispaid IS NULL THEN 1 ELSE 0 END) as unpaid
                    FROM boattrip 
                    WHERE 1=1 $dateCondition";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            echo json_encode([
                'labels' => ['Paid', 'Unpaid'],
                'datasets' => [[
                    'data' => [intval($row['paid']), intval($row['unpaid'])],
                    'backgroundColor' => ['#4CAF50', '#FF5722']
                ]]
            ]);
            break;
            
        // 2. Driver & Staff Performance
        
        case 'top_drivers':
            $sql = "SELECT d.firstname, d.lastname, COUNT(bt.boattripid) as trip_count 
                    FROM boattrip bt 
                    JOIN driver d ON d.driverid = bt.driverid 
                    WHERE bt.driverid IS NOT NULL $dateCondition
                    GROUP BY bt.driverid 
                    ORDER BY trip_count DESC 
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['firstname'] . ' ' . $row['lastname'];
                $data[] = intval($row['trip_count']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Trip Count',
                    'data' => $data,
                    'backgroundColor' => 'rgba(54, 162, 235, 0.7)',
                    'borderColor' => 'rgba(54, 162, 235, 1)',
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        case 'driver_workload_capacity':
            $sql = "SELECT d.firstname, d.lastname, 
                    COUNT(bt.boattripid) as trip_count,
                    AVG(b.boatcapacity) as avg_capacity
                    FROM boattrip bt 
                    JOIN driver d ON d.driverid = bt.driverid 
                    JOIN boat b ON b.boatid = bt.boatid
                    WHERE bt.driverid IS NOT NULL $dateCondition
                    GROUP BY bt.driverid 
                    ORDER BY trip_count DESC 
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $trips = [];
            $capacity = [];
            foreach ($rows as $row) {
                $labels[] = $row['firstname'] . ' ' . $row['lastname'];
                $trips[] = intval($row['trip_count']);
                $capacity[] = floatval($row['avg_capacity']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'Trip Count', 'data' => $trips, 'type' => 'bar', 'backgroundColor' => 'rgba(54, 162, 235, 0.7)', 'yAxisID' => 'y'],
                    ['label' => 'Avg Capacity', 'data' => $capacity, 'type' => 'line', 'borderColor' => '#FF6384', 'yAxisID' => 'y1']
                ]
            ]);
            break;
            
        case 'cancelled_by_driver':
            // Build cancelled date condition - filter by datecancelled, not departure
            $cancelledDateCondition = '';
            $cancelledParams = [];
            if ($startDate && $endDate) {
                $cancelledDateCondition = " AND datecancelled BETWEEN ? AND ? ";
                $cancelledParams = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
            } elseif ($startDate) {
                $cancelledDateCondition = " AND datecancelled >= ? ";
                $cancelledParams = [$startDate . ' 00:00:00'];
            } elseif ($endDate) {
                $cancelledDateCondition = " AND datecancelled <= ? ";
                $cancelledParams = [$endDate . ' 23:59:59'];
            }
            $sql = "SELECT d.firstname, d.lastname, COUNT(bt.boattripid) as cancelled_count 
                    FROM boattrip bt 
                    LEFT JOIN driver d ON d.driverid = bt.cancelledby 
                    WHERE bt.datecancelled IS NOT NULL $cancelledDateCondition
                    GROUP BY bt.cancelledby 
                    ORDER BY cancelled_count DESC 
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($cancelledParams);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['firstname'] . ' ' . $row['lastname'];
                $data[] = intval($row['cancelled_count']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Cancelled Trips',
                    'data' => $data,
                    'backgroundColor' => 'rgba(255, 99, 132, 0.7)',
                    'borderColor' => 'rgba(255, 99, 132, 1)',
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        case 'top10_drivers':
            $sql = "SELECT d.firstname, d.lastname, SUM(bt.noofpassengers) as total_passengers 
                    FROM boattrip bt 
                    JOIN driver d ON d.driverid = bt.driverid 
                    WHERE bt.driverid IS NOT NULL AND bt.noofpassengers IS NOT NULL $dateCondition
                    GROUP BY bt.driverid 
                    ORDER BY total_passengers DESC 
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['firstname'] . ' ' . $row['lastname'];
                $data[] = intval($row['total_passengers']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Total Passengers',
                    'data' => $data,
                    'backgroundColor' => 'rgba(75, 192, 192, 0.7)',
                    'borderColor' => 'rgba(75, 192, 192, 1)',
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        // 3. Safety & Compliance Monitoring
        
        case 'safety_compliance':
            $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN checklifevest = 1 THEN 1 ELSE 0 END) as lifevest,
                    SUM(CASE WHEN checklifevestchildren = 1 THEN 1 ELSE 0 END) as lifevest_children,
                    SUM(CASE WHEN checkfireextandsand = 1 THEN 1 ELSE 0 END) as fire_ext,
                    SUM(CASE WHEN checktrashbin = 1 THEN 1 ELSE 0 END) as trash_bin,
                    SUM(CASE WHEN checkgas = 1 THEN 1 ELSE 0 END) as gas_check
                    FROM boattrip 
                    WHERE 1=1 $dateCondition";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            $total = intval($row['total']) ?: 1;
            $labels = ['Life Vest', 'Life Vest (Children)', 'Fire Ext & Sand', 'Trash Bin', 'Gas'];
            $data = [
                round((intval($row['lifevest']) / $total) * 100, 1),
                round((intval($row['lifevest_children']) / $total) * 100, 1),
                round((intval($row['fire_ext']) / $total) * 100, 1),
                round((intval($row['trash_bin']) / $total) * 100, 1),
                round((intval($row['gas_check']) / $total) * 100, 1)
            ];
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Compliance Rate (%)',
                    'data' => $data,
                    'backgroundColor' => ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF'],
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        case 'inspections_by_personnel':
            // Join with driver table to get personnel names
            $sql = "SELECT COALESCE(CONCAT(d.firstname, ' ', d.lastname), CONCAT('User ', bt.inspectedby)) as personnel_name, 
                    COUNT(bt.boattripid) as inspection_count 
                    FROM boattrip bt 
                    LEFT JOIN driver d ON d.driverid = bt.inspectedby 
                    WHERE bt.inspectedby > 0 $dateCondition
                    GROUP BY bt.inspectedby 
                    ORDER BY inspection_count DESC 
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['personnel_name'];
                $data[] = intval($row['inspection_count']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Inspections',
                    'data' => $data,
                    'backgroundColor' => 'rgba(255, 206, 86, 0.7)',
                    'borderColor' => 'rgba(255, 206, 86, 1)',
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        // 4. IoT & Real-Time Tracking
        
        case 'avg_speed_per_boat':
            $sql = "SELECT boatno, AVG(iot_speedKnots) as avg_speed 
                    FROM boat 
                    WHERE iot_speedKnots IS NOT NULL AND iot_speedKnots > 0
                    GROUP BY boatid, boatno 
                    ORDER BY avg_speed DESC 
                    LIMIT 10";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['boatno'];
                $data[] = floatval($row['avg_speed']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Average Speed (knots)',
                    'data' => $data,
                    'backgroundColor' => 'rgba(153, 102, 255, 0.7)',
                    'borderColor' => 'rgba(153, 102, 255, 1)',
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        case 'device_heartbeat':
            $sql = "SELECT boatno, iot_lastHeartbeat 
                    FROM boat 
                    WHERE iot_deviceSerial IS NOT NULL 
                    ORDER BY boatno";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            $now = time();
            $offlineThreshold = 300; // 5 minutes in seconds
            foreach ($rows as $row) {
                $labels[] = $row['boatno'];
                if ($row['iot_lastHeartbeat']) {
                    $heartbeat = strtotime($row['iot_lastHeartbeat']);
                    $diffSeconds = $now - $heartbeat;
                    // Device is online if heartbeat is within 5 minutes
                    $data[] = $diffSeconds <= $offlineThreshold ? 1 : 0;
                } else {
                    $data[] = 0;
                }
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Online/Offline',
                    'data' => $data,
                    'backgroundColor' => array_map(function($v) {
                        return $v == 1 ? 'rgba(75, 192, 192, 0.7)' : 'rgba(255, 99, 132, 0.7)';
                    }, $data),
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        // 5. Personnel & Accountability
        
        case 'staff_activity':
            $sql = "SELECT 
                    SUM(CASE WHEN inspectedby > 0 THEN 1 ELSE 0 END) as inspected,
                    SUM(CASE WHEN assistedby IS NOT NULL THEN 1 ELSE 0 END) as assisted,
                    SUM(CASE WHEN releasedby IS NOT NULL THEN 1 ELSE 0 END) as released
                    FROM boattrip 
                    WHERE 1=1 $dateCondition";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            echo json_encode([
                'labels' => ['Inspected', 'Assisted', 'Released'],
                'datasets' => [
                    ['label' => 'Activities', 'data' => [intval($row['inspected']), intval($row['assisted']), intval($row['released'])], 'backgroundColor' => ['rgba(54, 162, 235, 0.7)', 'rgba(75, 192, 192, 0.7)', 'rgba(255, 206, 86, 0.7)']]
                ]
            ]);
            break;
            
        case 'driver_boat_assignment':
            $sql = "SELECT 
                    b.boatno as boat_name,
                    b.driverid as default_driver,
                    bt.driverid as trip_driver,
                    COUNT(*) as trip_count
                    FROM boattrip bt 
                    JOIN boat b ON b.boatid = bt.boatid 
                    WHERE bt.boatid > 0 $dateCondition
                    GROUP BY bt.boatid, COALESCE(bt.driverid, b.driverid)
                    ORDER BY trip_count DESC 
                    LIMIT 15";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $defaultTrips = [];
            $swappedTrips = [];
            foreach ($rows as $row) {
                $labels[] = $row['boat_name'];
                if ($row['trip_driver'] == $row['default_driver'] || $row['trip_driver'] == null) {
                    $defaultTrips[] = intval($row['trip_count']);
                    $swappedTrips[] = 0;
                } else {
                    $defaultTrips[] = 0;
                    $swappedTrips[] = intval($row['trip_count']);
                }
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'Default Driver', 'data' => $defaultTrips, 'backgroundColor' => 'rgba(54, 162, 235, 0.7)'],
                    ['label' => 'Swapped Driver', 'data' => $swappedTrips, 'backgroundColor' => 'rgba(255, 99, 132, 0.7)']
                ]
            ]);
            break;
            
        // 6. Financial & Capacity Utilization
        
        case 'revenue_by_tour_type':
            $sql = "SELECT islandtourtype, SUM(boatamount) as revenue 
                    FROM boattrip 
                    WHERE islandtourtype IS NOT NULL AND boatamount > 0 $dateCondition
                    GROUP BY islandtourtype 
                    ORDER BY revenue DESC 
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['islandtourtype'] ?: 'Unknown';
                $data[] = floatval($row['revenue']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Revenue',
                    'data' => $data,
                    'backgroundColor' => 'rgba(75, 192, 192, 0.7)',
                    'borderColor' => 'rgba(75, 192, 192, 1)',
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        case 'capacity_utilization':
            $sql = "SELECT b.boatno, b.boatcapacity, AVG(bt.noofpassengers) as avg_passengers 
                    FROM boattrip bt 
                    JOIN boat b ON b.boatid = bt.boatid 
                    WHERE bt.boatid > 0 AND bt.noofpassengers IS NOT NULL $dateCondition
                    GROUP BY bt.boatid 
                    ORDER BY avg_passengers DESC 
                    ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $capacity = [];
            $passengers = [];
            foreach ($rows as $row) {
                $labels[] = $row['boatno'];
                $capacity[] = intval($row['boatcapacity']);
                $passengers[] = floatval($row['avg_passengers']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'Boat Capacity', 'data' => $capacity, 'type' => 'bar', 'backgroundColor' => 'rgba(54, 162, 235, 0.7)', 'borderColor' => 'rgba(54, 162, 235, 1)', 'borderWidth' => 1, 'yAxisID' => 'y'],
                    ['label' => 'Avg Passengers', 'data' => $passengers, 'type' => 'line', 'borderColor' => '#FF6384', 'backgroundColor' => 'rgba(255, 99, 132, 0.2)', 'borderWidth' => 3, 'fill' => true, 'yAxisID' => 'y']
                ]
            ]);
            break;
            
        case 'payment_completion_rate':
            $sql = "SELECT 
                    SUM(CASE WHEN ispaid = 1 THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ispaid = 0 OR ispaid IS NULL THEN 1 ELSE 0 END) as pending
                    FROM boattrip 
                    WHERE 1=1 $dateCondition";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            echo json_encode([
                'labels' => ['Completed', 'Pending'],
                'datasets' => [[
                    'data' => [intval($row['completed']), intval($row['pending'])],
                    'backgroundColor' => ['#4CAF50', '#FF9800']
                ]]
            ]);
            break;
            
        // 7. Geographical & Speed Analysis
        
        case 'avg_speed_by_boat_size':
            $sql = "SELECT boatsize, AVG(iot_speedKnots) as avg_speed 
                    FROM boat 
                    WHERE iot_speedKnots IS NOT NULL AND iot_speedKnots > 0
                    GROUP BY boatsize 
                    ORDER BY avg_speed DESC";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = [];
            foreach ($rows as $row) {
                $labels[] = $row['boatsize'];
                $data[] = floatval($row['avg_speed']);
            }
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Average Speed (knots)',
                    'data' => $data,
                    'backgroundColor' => 'rgba(255, 206, 86, 0.7)',
                    'borderColor' => 'rgba(255, 206, 86, 1)',
                    'borderWidth' => 1
                ]]
            ]);
            break;
            
        case 'peak_activity_hours':
            $sql = "SELECT HOUR(departure) as hour, COUNT(boattripid) as trip_count 
                    FROM boattrip 
                    WHERE departure IS NOT NULL $dateCondition
                    GROUP BY HOUR(departure) 
                    ORDER BY hour";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $labels = [];
            $data = array_fill(0, 24, 0);
            foreach ($rows as $row) {
                $h = intval($row['hour']);
                $data[$h] = intval($row['trip_count']);
            }
            $labels = array_map(function($i) { return sprintf('%02d:00', $i); }, range(0, 23));
            echo json_encode([
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Trip Count',
                    'data' => $data,
                    'borderColor' => '#2196F3',
                    'backgroundColor' => 'rgba(33, 150, 243, 0.2)',
                    'borderWidth' => 3,
                    'fill' => true,
                    'tension' => 0.4
                ]]
            ]);
            break;
            
        // Date range endpoints
        case 'minDate':
            $sql = "SELECT MIN(DATE(departure)) as minDate FROM boattrip WHERE departure IS NOT NULL";
            $stmt = $pdo->query($sql);
            $row = $stmt->fetch();
            echo json_encode(['minDate' => $row['minDate']]);
            break;
            
        case 'maxDate':
            $sql = "SELECT MAX(DATE(departure)) as maxDate FROM boattrip WHERE departure IS NOT NULL";
            $stmt = $pdo->query($sql);
            $row = $stmt->fetch();
            echo json_encode(['maxDate' => $row['maxDate']]);
            break;
            
        default:
            echo json_encode(['error' => 'Invalid report type']);
            break;
    }
    
} catch (\PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>
