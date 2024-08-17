<?php

ini_set('memory_limit', '1G'); // Increase memory limit to 1GB, adjust as needed

$sourceConfig = [
    'host' => 'source_host',
    'username' => 'source_user',
    'password' => 'source_password',
    'dbname' => 'source_db',
    'port' => 3306
];

$targetConfig = [
    'host' => 'target_host',
    'username' => 'target_user',
    'password' => 'target_password',
    'dbname' => 'target_db',
    'port' => 3306
];

$maxLoad = 75; // Maximum server load percentage
$threads = 4; // Number of threads to use
$logFile = 'migration_log.txt'; // Log file for error logging
$pauseFile = 'pause_migration.txt'; // File to signal pause
$batchSize = 500; // Reduced batch size for lower memory usage

function logMessage($message) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - " . $message . PHP_EOL, FILE_APPEND);
}

function getServerLoad() {
    $load = sys_getloadavg();
    return $load[0] * 100;
}

function showProgressBar($progress, $total, $table, $rowsTransferred) {
    $percentage = round(($progress / $total) * 100);
    $bar = str_repeat('=', $percentage / 2) . str_repeat(' ', 50 - ($percentage / 2));
    echo sprintf("\r[%s] %s%% (%d/%d) - Table: %s, Rows Transferred: %d", $bar, $percentage, $progress, $total, $table, $rowsTransferred);
    if ($progress == $total) {
        echo PHP_EOL;
    }
}

function checkPause() {
    global $pauseFile;
    while (file_exists($pauseFile)) {
        echo "\nMigration paused. Remove 'pause_migration.txt' to resume...\n";
        sleep(5);
    }
}

function fetchRows($source, $table) {
    $result = $source->query("SELECT * FROM `$table`", MYSQLI_USE_RESULT);
    if ($result === false) {
        logMessage("Failed to fetch rows from table `$table`: " . $source->error);
        return; // Exit the function if the query fails
    }

    while ($row = $result->fetch_assoc()) {
        yield $row;
    }
    $result->free(); // Free the result set when done
}

function migrateSchema($source, $target) {
    $result = $source->query('SHOW TABLES');
    if ($result === false) {
        logMessage("Failed to fetch tables from source: " . $source->error);
        return; // Exit if query fails
    }

    while ($row = $result->fetch_row()) {
        $table = $row[0];
        $createTableResult = $source->query("SHOW CREATE TABLE `$table`");
        if ($createTableResult === false) {
            logMessage("Failed to fetch create statement for table `$table`: " . $source->error);
            continue;
        }

        $createTable = $createTableResult->fetch_row()[1];
        if ($target->query($createTable) === FALSE) {
            logMessage("Failed to create table `$table`: " . $target->error);
        }
    }
}

function migrateData($source, $target, $table) {
    global $batchSize;

    // Temporarily disable foreign key checks
    $target->query('SET FOREIGN_KEY_CHECKS=0');

    $result = $source->query("SELECT COUNT(*) FROM `$table`");
    if ($result === false) {
        logMessage("Failed to count rows in table `$table`: " . $source->error);
        return; // Exit if query fails
    }
    $totalRows = $result->fetch_row()[0];

    $rowCount = 0;

    foreach (fetchRows($source, $table) as $row) {
        checkPause(); // Check if the script should pause

        $columns = implode('`, `', array_keys($row));
        $values = implode("', '", array_map([$source, 'real_escape_string'], array_values($row)));
        $query = "INSERT INTO `$table` (`$columns`) VALUES ('$values')";
        if ($target->query($query) === FALSE) {
            logMessage("Failed to insert row into `$table`: " . $target->error);
        }

        $rowCount++;
        if ($rowCount % $batchSize == 0 || $rowCount == $totalRows) {
            // Show progress
            showProgressBar($rowCount, $totalRows, $table, $rowCount);
        }
    }

    // Set auto-increment value
    $autoIncrementResult = $source->query("SHOW TABLE STATUS LIKE '$table'");
    if ($autoIncrementResult === false) {
        logMessage("Failed to fetch auto increment value for table `$table`: " . $source->error);
    } else {
        $autoIncrementRow = $autoIncrementResult->fetch_assoc();
        $autoIncrementValue = $autoIncrementRow['Auto_increment'];
        if ($autoIncrementValue) {
            $target->query("ALTER TABLE `$table` AUTO_INCREMENT = $autoIncrementValue");
        }
    }

    // Re-enable foreign key checks
    $target->query('SET FOREIGN_KEY_CHECKS=1');
}

function worker($sourceConfig, $targetConfig, $table) {
    $source = new mysqli($sourceConfig['host'], $sourceConfig['username'], $sourceConfig['password'], $sourceConfig['dbname'], $sourceConfig['port']);
    $target = new mysqli($targetConfig['host'], $targetConfig['username'], $targetConfig['password'], $targetConfig['dbname'], $targetConfig['port']);

    migrateData($source, $target, $table);

    $source->close();
    $target->close();
}

$source = new mysqli($sourceConfig['host'], $sourceConfig['username'], $sourceConfig['password'], $sourceConfig['dbname'], $sourceConfig['port']);
$target = new mysqli($targetConfig['host'], $targetConfig['username'], $targetConfig['password'], $targetConfig['dbname'], $targetConfig['port']);

// Error handling for database connection
if ($source->connect_error) {
    die("Connection to source database failed: " . $source->connect_error);
}
if ($target->connect_error) {
    die("Connection to target database failed: " . $target->connect_error);
}

migrateSchema($source, $target);

$tablesResult = $source->query('SHOW TABLES');
if ($tablesResult === false) {
    logMessage("Failed to fetch tables from source database: " . $source->error);
    die("Failed to fetch tables. Check the logs for details.");
}

$tables = [];
while ($row = $tablesResult->fetch_row()) {
    $tables[] = $row[0];
}

$processes = [];
foreach ($tables as $table) {
    while (getServerLoad() > $maxLoad) {
        echo "Pausing migration due to high server load...\n";
        sleep(10);
    }

    $pid = pcntl_fork();
    if ($pid == -1) {
        die('Could not fork');
    } elseif ($pid) {
        // Parent process
        $processes[] = $pid;
    } else {
        // Child process
        worker($sourceConfig, $targetConfig, $table);
        exit(0);
    }

    if (count($processes) >= $threads) {
        pcntl_wait($status);
    }
}

foreach ($processes as $pid) {
    pcntl_waitpid($pid, $status);
}

$source->close();
$target->close();

echo "Migration completed!\n";
?>
