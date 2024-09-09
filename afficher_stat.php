<!DOCTYPE html>
<html>
<head>
    <title>Database Statistics</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        h2 {
            margin-top: 40px;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        th {
            background-color: #f2f2f2;
            text-align: left;
        }
        .chart-container {
            width: 100%;
            margin: 20px 0;
            text-align: center;
        }
    </style>
</head>
<body>

<?php
$serverName = "NOURCHENE";
$connectionOptions = array(
    "Database" => "AdventureWorks2022",
    "UID" => "atef2",
    "PWD" => "atef",
);

$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    die("<p>Erreur de connexion : " . print_r(sqlsrv_errors(), true) . "</p>");
}

// Fetching transaction log usage
$sqlLogUsage = "SELECT name, log_reuse_wait_desc FROM sys.databases WHERE name = 'AdventureWorks2022'";
$stmtLogUsage = sqlsrv_query($conn, $sqlLogUsage);
if ($stmtLogUsage === false) {
    die("<p>Erreur de requête : " . print_r(sqlsrv_errors(), true) . "</p>");
}
$row = sqlsrv_fetch_array($stmtLogUsage, SQLSRV_FETCH_ASSOC);
?>

<h2>Transaction Log Usage</h2>
<p>Log Reuse Wait Status: <?php echo htmlspecialchars($row['log_reuse_wait_desc']); ?></p>

<?php
// Fetching recent changes
$sqlRecentChanges = "
    SELECT TOP 10
        OBJECT_NAME(OBJECT_ID) AS TableName,
        last_user_update AS LastUpdate
    FROM
        sys.dm_db_index_usage_stats
    WHERE
        database_id = DB_ID('AdventureWorks2022')
    ORDER BY
        last_user_update DESC";
$stmtRecentChanges = sqlsrv_query($conn, $sqlRecentChanges);
if ($stmtRecentChanges === false) {
    die("<p>Erreur de requête : " . print_r(sqlsrv_errors(), true) . "</p>");
}
?>

<h2>Recent Changes </h2>
<table>
    <tr>
        <th>Table Name</th>
        <th>Last Update</th>
    </tr>
    <?php while ($row = sqlsrv_fetch_array($stmtRecentChanges, SQLSRV_FETCH_ASSOC)) : ?>
    <tr>
        <td><?php echo htmlspecialchars($row['TableName']); ?></td>
        <td><?php echo $row['LastUpdate'] ? $row['LastUpdate']->format('Y-m-d H:i:s') : 'N/A'; ?></td>
    </tr>
    <?php endwhile; ?>
</table>

<?php
// Fetching table size information
$sqlTableSize = "
    SELECT 
        t.name AS TableName,
        p.rows AS RowCounts,
        SUM(a.total_pages) * 8 AS TotalSpaceKB,
        SUM(a.used_pages) * 8 AS UsedSpaceKB,
        (SUM(a.total_pages) - SUM(a.used_pages)) * 8 AS UnusedSpaceKB
    FROM 
        sys.tables t
    INNER JOIN 
        sys.indexes i ON t.object_id = i.object_id
    INNER JOIN 
        sys.partitions p ON i.object_id = p.object_id AND i.index_id = p.index_id
    INNER JOIN 
        sys.allocation_units a ON p.partition_id = a.container_id
    WHERE 
        t.type = 'U'
    GROUP BY 
        t.name, p.rows
    ORDER BY 
        TotalSpaceKB DESC";

$stmtTableSize = sqlsrv_query($conn, $sqlTableSize);
if ($stmtTableSize === false) {
    die("<p>Erreur de requête : " . print_r(sqlsrv_errors(), true) . "</p>");
}
$tableSizes = [];
while ($row = sqlsrv_fetch_array($stmtTableSize, SQLSRV_FETCH_ASSOC)) {
    $tableSizes[] = [
        'Table' => $row['TableName'],
        'TotalSpaceKB' => $row['TotalSpaceKB']
    ];
}

// Sorting and limiting data for chart display
usort($tableSizes, function($a, $b) {
    return $b['TotalSpaceKB'] - $a['TotalSpaceKB'];
});
$topTables = array_slice($tableSizes, 0, 10); // Display only the top 10 tables
?>

<h2>Table Size Information</h2>
<div class="chart-container">
    <canvas id="tableSizeChart" width="400" height="200"></canvas>
</div>

<?php
// Fetching index fragmentation information
$sqlIndexFragmentation = "
    SELECT 
        OBJECT_NAME(ips.object_id) AS TableName, 
        i.name AS IndexName,
        ips.index_id, 
        ips.avg_fragmentation_in_percent
    FROM 
        sys.dm_db_index_physical_stats(DB_ID(), NULL, NULL, NULL, 'LIMITED') AS ips
    JOIN 
        sys.indexes AS i ON ips.object_id = i.object_id AND ips.index_id = i.index_id
    WHERE 
        ips.avg_fragmentation_in_percent > 30
    ORDER BY 
        avg_fragmentation_in_percent DESC";

$stmtIndexFragmentation = sqlsrv_query($conn, $sqlIndexFragmentation);
if ($stmtIndexFragmentation === false) {
    die("<p>Erreur de requête : " . print_r(sqlsrv_errors(), true) . "</p>");
}
$indexFragmentationData = [];
while ($row = sqlsrv_fetch_array($stmtIndexFragmentation, SQLSRV_FETCH_ASSOC)) {
    $indexFragmentationData[] = [
        'TableName' => $row['TableName'],
        'IndexName' => $row['IndexName'],
        'Fragmentation' => $row['avg_fragmentation_in_percent']
    ];
}
?>

<h2>Index Fragmentation (Above 30%)</h2>
<div class="chart-container">
    <canvas id="indexFragmentationChart" width="400" height="200"></canvas>
</div>

<?php
// Fetching database space information
$sqlDatabaseSpace = "
    SELECT 
        DB_NAME(database_id) AS DatabaseName,
        type_desc AS FileType,
        size * 8 / 1024 AS SizeMB,
        size * 8 / 1024 - CAST(FILEPROPERTY(name, 'SpaceUsed') AS INT) * 8 / 1024 AS FreeSpaceMB
    FROM 
        sys.master_files
    WHERE 
        DB_NAME(database_id) = 'AdventureWorks2022'";

$stmtDatabaseSpace = sqlsrv_query($conn, $sqlDatabaseSpace);
if ($stmtDatabaseSpace === false) {
    die("<p>Erreur de requête : " . print_r(sqlsrv_errors(), true) . "</p>");
}
$databaseSpaceData = [];
while ($row = sqlsrv_fetch_array($stmtDatabaseSpace, SQLSRV_FETCH_ASSOC)) {
    $databaseSpaceData[] = [
        'FileType' => $row['FileType'],
        'SizeMB' => $row['SizeMB']
    ];
}
?>

<h2>Database Space Usage</h2>
<div class="chart-container">
    <canvas id="databaseSpaceChart" width="200" height="200"></canvas>
</div>

<?php
// Fetching active sessions
$sqlActiveSessions = "
    SELECT 
        COUNT(session_id) AS ActiveSessions
    FROM 
        sys.dm_exec_sessions
    WHERE 
        is_user_process = 1";

$stmtActiveSessions = sqlsrv_query($conn, $sqlActiveSessions);
if ($stmtActiveSessions === false) {
    die("<p>Erreur de requête : " . print_r(sqlsrv_errors(), true) . "</p>");
}
$row = sqlsrv_fetch_array($stmtActiveSessions, SQLSRV_FETCH_ASSOC);
?>

<h2>Active Sessions</h2>
<p>Number of Active Sessions: <?php echo htmlspecialchars($row['ActiveSessions']); ?></p>

<?php
sqlsrv_close($conn);
?>

<script>
    // Data for the Table Size Chart
    var ctx1 = document.getElementById('tableSizeChart').getContext('2d');
    var tableSizeChart = new Chart(ctx1, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($topTables, 'Table')); ?>,
            datasets: [{
                label: 'Table Sizes (KB)',
                data: <?php echo json_encode(array_column($topTables, 'TotalSpaceKB')); ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                }
            }
        }
    });

    // Data for the Index Fragmentation Chart
    var ctx2 = document.getElementById('indexFragmentationChart').getContext('2d');
    var indexFragmentationChart = new Chart(ctx2, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($indexFragmentationData, 'TableName')); ?>,
            datasets: [{
                label: 'Index Fragmentation (%)',
                data: <?php echo json_encode(array_column($indexFragmentationData, 'Fragmentation')); ?>,
                backgroundColor: 'rgba(255, 99, 132, 0.6)',
                borderColor: 'rgba(255, 99, 132, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                }
            }
        }
    });

    // Data for the Database Space Usage Chart
    var ctx3 = document.getElementById('databaseSpaceChart').getContext('2d');
    var databaseSpaceChart = new Chart(ctx3, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($databaseSpaceData, 'FileType')); ?>,
            datasets: [{
                label: 'Database Space Usage',
                data: <?php echo json_encode(array_column($databaseSpaceData, 'SizeMB')); ?>,
                backgroundColor: ['rgba(75, 192, 192, 0.6)', 'rgba(153, 102, 255, 0.6)'],
                borderColor: ['rgba(75, 192, 192, 1)', 'rgba(153, 102, 255, 1)'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var label = context.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += context.raw + ' MB';
                            return label;
                        }
                    }
                }
            }
        }
    });
</script>

</body>
</html>
