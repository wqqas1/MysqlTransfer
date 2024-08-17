# MariaDB to Amazon Aurora RDS or any MySQL Migration Script

This PHP script facilitates the migration of a MariaDB database to Amazon Aurora RDS MySQL 8 or any compatible mysql/mariadb. It transfers the schema and data from the source MariaDB database to the target MySQL database while maintaining data integrity and allowing for pausing and resuming the migration process. The script is multithreaded, provides a progress bar, and ensures that server load does not exceed a specified threshold during the transfer. 

Usually such script is required to handle hude db transfers like 100 GB or above. This is for personal use. And anyone is welcome to use it.

## Features

- **Schema and Data Migration**: Automatically transfers all tables, including their schema and data, from the source MariaDB database to the target Aurora RDS MySQL database.
- **Progress Bar**: Displays a real-time progress bar showing the percentage of rows transferred for each table.
- **Pause and Resume**: The migration process can be paused and resumed by creating and deleting a specific file (`pause_migration.txt`).
- **Server Load Monitoring**: The script monitors the server load and pauses the migration if the load exceeds a predefined threshold.
- **Batch Processing**: Data is transferred in batches to optimize performance and reduce the load on the server.
- **Foreign Key Handling**: Foreign key checks are temporarily disabled during the migration to avoid dependency issues and re-enabled afterward.
- **Logging**: All errors and important events are logged to a file (`migration_log.txt`) for review and troubleshooting.

## Requirements

- **PHP**: Version 7.4 or later.
- **MySQLi Extension**: Enabled in your PHP installation.
- **MariaDB**: Version 10.3 or later (as the source database).
- **Amazon Aurora RDS MySQL**: Version 8.x (as the target database).
- **Linux-based System**: Required for multithreading and server load monitoring.

## Installation

1. **Clone the Repository**:
    ```bash
    git clone [https://github.com/yourusername/mariadb-to-aurora-migration](https://github.com/wqqas1/MysqlTransfer).git
    cd mariadb-to-aurora-migration
    ```

2. **Configure Source and Target Databases**:
    - Edit the script (`migrate.php`) to configure the source and target database connection details:

   ```php
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
   ```

3. Run the Script:
    - Execute the script from the command line:
   ```bash
   php migrate.php
   ```

## Customizable Options
    -** Source and Target Database Configuration:
        - Configure the source and target databases by editing the $sourceConfig and $targetConfig arrays in the script:
       ```php
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
        ```

    -** Maximum Server Load:
    - The $maxLoad variable controls the maximum server load percentage before the script pauses the migration:
       ```php
         $maxLoad = 75; // Maximum server load percentage
       ```
    -** Number of Threads:
    - The $threads variable sets the number of threads used for the migration:
       ```php
         $threads = 4; // Number of threads to use
       ```

    -** Batch Size:
    - The $batchSize variable controls how many rows are transferred in each batch:
       ```php
         $batchSize = 1000; // Adjust batch size for performance
       ```
    
    -** Log File Location:
    - The $logFile variable specifies the log file's name and location:
       ```php
         $logFile = 'migration_log.txt'; // Log file for error logging
       ```

## Pause and Resume:
     - To pause the migration, create a file named pause_migration.txt in the same directory as the script. The script will pause until the file is deleted.

## Notes
    - **Data Integrity**: The script performs basic data integrity checks after each table migration, comparing the row count in the source and target tables.
    - **Performance Tuning**: You may need to adjust the $batchSize, $maxLoad, and $threads values based on your server's performance and network conditions.
## Troubleshooting
    - ** Error Logs**: If the script encounters an error, check the migration_log.txt file for detailed information.
    - ** Connection Issues**: Ensure that the source and target databases are accessible and that the credentials are correct.
    - ** Server Load**: If the migration is pausing too frequently, consider increasing the $maxLoad threshold or optimizing the server's performance.

## Contributing
    - Contributions are welcome! Please fork this repository, make your changes, and submit a pull request.
## License
     - This project is licensed under the GNU GENERAL PUBLIC License. See the LICENSE file for details.
