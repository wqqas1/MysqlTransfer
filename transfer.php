<?php

ini_set('memory_limit', '1G'); // Increase memory limit to 1GB, adjust as needed

$sourceConfig = [
    'host' => 'source_host',
    'username' => 'source_user',
    'password' => 'source_password',
    'port' => 3306
];

$targetConfig = [
    'host' => 'target_host',
    'username' => 'target_user',
    'password' => 'target_password',
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

function showProgressBar($progress, $total, $message) {
    $percentage = round(($progress / $total) * 100);
    $bar = str_repeat('=', $percentage / 2) . str_repeat(' ', 50 - ($percentage / 2));
    echo sprintf("\r[%s] %s%% (%d/%d) - %s", $bar, $percentage, $progress, $total, $message);
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

function migrateSchema($source, $target, $dbName) {
    $result = $source->query('SHOW TABLES');
    if ($result === false) {
        logMessage("Failed to fetch tables from source database `$dbName`: " . $source->error);
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
            showProgressBar($rowCount, $totalRows, "Table: $table, Rows Transferred: $rowCount");
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

function getDatabases($source) {
    $result = $source->query('SHOW DATABASES');
    if ($result === false) {
        logMessage("Failed to fetch databases: " . $source->error);
        die("Failed to fetch databases. Check the logs for details.");
    }

    $databases = [];
    while ($row = $result->fetch_row()) {
        $dbName = $row[0];
        if (!in_array($dbName, ['information_schema', 'mysql', 'performance_schema', 'sys'])) {
            $databases[] = $dbName;
        }
    }
    return $databases;
}

function selectDatabases($databases) {
    echo "Available databases:\n";
    foreach ($databases as $index => $dbName) {
        echo sprintf("[%d] %s\n", $index + 1, $dbName);
    }

    echo "Enter the numbers of the databases you want to migrate (comma-separated), or 'all' to select all: ";
    $input = trim(fgets(STDIN));

    if (strtolower($input) === 'all') {
        return $databases;
    }

    $selectedIndices = array_map('intval', explode(',', $input));
    $selectedDatabases = [];

    foreach ($selectedIndices as $index) {
        if (isset($databases[$index - 1])) {
            $selectedDatabases[] = $databases[$index - 1];
        }
    }

    return $selectedDatabases;
}

function getCharacterEncoding($source, $dbName) {
    $result = $source->query("SELECT default_character_set_name FROM information_schema.SCHEMATA WHERE schema_name = '$dbName'");
    if ($result === false) {
        logMessage("Failed to fetch character encoding for database `$dbName`: " . $source->error);
        return null; // Return null if query fails
    }
    $row = $result->fetch_assoc();
    return $row['default_character_set_name'];
}

// Connect to source server to get available databases
$source = new mysqli($sourceConfig['host'], $sourceConfig['username'], $sourceConfig['password'], '', $sourceConfig['port']);
if ($source->connect_error) {
    die("Connection to source server failed: " . $source->connect_error);
}

$databases = getDatabases($source);
$selectedDatabases = selectDatabases($databases);

foreach ($selectedDatabases as $dbName) {
    $source->select_db($dbName);

    // Get the character encoding of the source database
    $characterEncoding = getCharacterEncoding($source, $dbName);
    $characterEncodingClause = $characterEncoding ? "DEFAULT CHARACTER SET $characterEncoding" : "";

    // Create the target database with the same character encoding if it doesn't exist
    $target = new mysqli($targetConfig['host'], $targetConfig['username'], $targetConfig['password'], '', $targetConfig['port']);
    $target->query("CREATE DATABASE IF NOT EXISTS `$dbName` $characterEncodingClause");
    $target->select_db($dbName);

    migrateSchema($source, $target, $dbName);

    $tablesResult = $source->query('SHOW TABLES');
    if ($tablesResult === false) {
        logMessage("Failed to fetch tables from database `$dbName`: " . $source->error);
        continue; // Skip this database if query fails
    }

    $tables = [];
    while ($row = $tablesResult->fetch_row()) {
        $tables[] = $row[0];
    }

    $totalTables = count($tables);
    $processedTables = 0;

    $processes = [];
    foreach ($tables as $table) {
        checkPause();

        $pid = pcntl_fork();
        if ($pid == -1) {
            logMessage("Failed to fork process for table `$table` in database `$dbName`.");
            continue;
        } elseif ($pid) {
            $processes[] = $pid;
        } else {
            worker($sourceConfig, $targetConfig, $table);
            exit(0);
        }

        if (count($processes) >= $threads) {
            pcntl_wait($status);
            $processedTables++;
            showProgressBar($processedTables, $totalTables, "Database: $dbName");
        }
    }

    foreach ($processes as $pid) {
        pcntl_waitpid($pid, $status);
        $processedTables++;
        showProgressBar($processedTables, $totalTables, "Database: $dbName");
    }

    echo "Migration of database `$dbName` completed!\n";
}

$source->close();
$target->close();

echo "All selected databases have been migrated!\n";
?>
