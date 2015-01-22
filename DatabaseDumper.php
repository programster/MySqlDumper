<?php

/*
 * Class to make the dumping of mysql databases incredibly easy.
 * You can use this class to dump only specified databases by using addDatabase method, but by 
 * default it will dump all databases.
 * 
 * By default, this will place everything into a single dump file. However, use the 
 * useSeparateFiles() method to have the data split into separate files.
 * 
 * NOTE: This will use "--lock-all-tables" (unless you run useSingleTransaction) which will 
 * "Lock all tables across all databases" , which will result in databases that aren't involved 
 * still being read locked whilst the dump is taking place.
 * 
 * Filenames
 * Whilst splitting into multiple files may be convinient, this simply executes extra steps to
 * "break up" the dump file rather than doing anything clever, so this will always take longer and
 * will be dependent on your CPU.
 * 
 * Snapshotting/Consistency.
 * =========================
 * The aim of this class is to enable "snapshotting" across multiple databases so that there is 
 * a consistent "image" which is necessary if you have a system spread across databases. If you 
 * find that this has too much of an impact on performance and dont need consistency between 
 * databases, then simply create multiple of these objects, one for each database group that need
 * to be consistent with each other.
 *
 * Importing split dumps
 * =======================
 * Splitting the dump into multiple files is useful for if you want to view the contents or only
 * import certain tables etc, but you also want to know how to import them all again. The solution
 * is to use cat:
 * cat *.sql | mysql -u [username] -p [database name here]
 * 
 * Limitations:
 * This cannot handle databases spread across different hosts.
 * This cannot handle cases where the user provided does not have access to all of the databases.
 * 
 * Example Usage:
 * ==============
 * $dumper = new MySqlDumper('/tmp', 'localhost', 'root', 'hickory2000');
 * $dumper->useSingleFile('my_dump');
 * $dumper->run();
 * 
 * Tested to work on Ubuntu Server 12.04 LTS
 */

namespace programster/mysqldumper;


class MySqlDumper
{
    private $m_consistencySwitch = '--lock-all-tables';
    private $m_backupDirectory;
    private $m_databases = array();
    private $m_useTimestamp = true;
    
    private $m_dbUser;
    private $m_dbHost;
    private $m_dbPassword;
    private $m_dbPort;

    private $m_isRds = false; # flag indicating if this is an RDS instance taking backup from.
    private $m_masterDump = false; # flag for if taking a master dump to create replicas from.
    private $m_singleFileName = null; # If this is null then we will delfault to $timestamp.sql
    
    private $m_splitByDatabase = false; # split by database, not one dumped file.
    private $m_splitByTable = false; # split by table (this forces splitting by database to true)
    
    
    /**
     * Constructs this object in preperation for running a backup.
     * @param type $backupFolder - the path to the folder we will dump backup files to
     * @param type $dbHost - the mysql host where the database(s) are located
     * @param type $dbUsername - the username to connect with.
     * @param type $dbPassword - the password to connect with.
     * @param type $dbPort - the port to connect on.
     * @throws Exception - if that directory is not writeable.
     */
    public function __construct($backupFolder,
                                $dbHost, 
                                $dbUsername, 
                                $dbPassword, 
                                $dbPort=3306)
    {        
        self::validateBackupDirectory($backupFolder);
        
        $this->m_backupDirectory = $backupFolder;
        $this->m_dbHost = $dbHost;
        $this->m_dbUser = $dbUsername;
        $this->m_dbPassword = $dbPassword;
        $this->m_dbPort = $dbPort;

        if (stripos($dbHost, 'rds.amazonaws.com') !== FALSE)
        {
            $warningMsg =
                PHP_EOL .
                "WARNING" . PHP_EOL .
                "=======" . PHP_EOL .
                "MySqlDumper has detected that you are snapshotting an RDS instance. " . 
                PHP_EOL .
                "Please note that this means that useSingleTransaction() is enabled by " .
                "default and there can still be room for inconsistency when it comes to " .
                "non-transactional tables such as MyISAM." . PHP_EOL .
                "useMasterDump() will not work on RDS instances." . PHP_EOL .
                "Hopefully you are using this tool in order to move AWAY from RDS." . PHP_EOL .
                PHP_EOL;

            echo $warningMsg;
            $this->m_isRds = true;
            $this->useSingleTransaction();
        }
    }
    
    
    /**
     * If you are only using innodb tables, then you can use this method to implement the 
     * --single-transaction switch which results in NOT read-locking all your tables but using a 
     * transaction.
     * With future updates, this will no longer be open to the user, but the software itself will
     * determine if all the tables are using InnoDB and will use this if so.
     * @param $flag - optional - indicator of whether to use single transaction which is default off
     * @return void
     */
    public function useSingleTransaction()
    {
        $this->m_consistencySwitch = '--single-transaction';
    }
    
    
    /**
     * This disables the consistency/snapshot functionality of the dump. It is highly recommended
     * that you do NOT use this. 
     * @param void
     * @return void.
     */
    public function disableConsistencySwitch()
    {
        $this->m_consistencySwitch = '';
    }
    
    
    /**
     * By default, we prefix our dumps with the timestamp. Run this method to disable this 
     * behaviour.
     * @param void
     * @return void
     */
    public function disableTimstamp()
    {
        $this->m_useTimestamp = false;
    }
    
    
    /**
     * Add a database to the list of databases to be backed up.
     * If you do not add any databases then ALL databases will be dumped.
     * If this is not used, then we will use the --all-databases switch to dump all the databases
     * @param $databaseName - the name of the database we wish to backup.
     * @return void.
     */
    public function addDatabase($databaseName)
    {
        if ($this->m_isRds)
        {
            throw new Exception('Cannot specify databases on RDS instances.');
        }

        # We set the index to prevent duplicates.
        $this->m_databases[$databaseName] = 1;
    }
    
    
    /**
     * Specify the name you want for the dumped file. This is redundant if you are going to use
     * use the SeparateFiles method.
     * @param $name - the name of the file should dump as (dont include the extension)
     * @return void.
     */
    public function setFileName($name)
    {
        $this->m_singleFileName = $name;
    }
    
    
    /**
     * Specify that you want the dump of all databases in a single file, and specify the name
     * you want the dump to be placed in (may still be prefixed with timestamp if thats enabled).
     * @param $name - the name of the file should dump as (dont include the extension)
     * @return void.
     */
    public function useSeparateFiles($tableLevel = false)
    {
        if ($this->m_masterDump)
        {
            throw new Exception('You cannot split a master dump.');
        }

        $this->m_splitByDatabase = true;
        
        if ($tableLevel)
        {
            $this->m_splitByTable = true;
        }
    }


    /**
     * Only use this if binlogging on the server is active.
     * Use this option to dump a master replication server to produce a dump file that can be used 
     * to set up another server as a slave of the master. It causes the dump output to include a 
     * CHANGE MASTER TO statement that indicates the binary log coordinates (file name and position)
     * of the dumped server. These are the master server coordinates from which the slave should 
     * start replicating after you load the dump file into the slave. 
     * ref: http://dev.mysql.com/doc/refman/5.1/en/mysqldump.html#option_mysqldump_master-data
     * @param $flag - optionally set to false to switch this off again (off by default)
     * @return void
     */
    public function useMasterDump($flag=true)
    {
        if ($this->m_isRds)
        {
            throw new Exception('Cannot use master dumps on RDS.');
        }

        if ($m_splitByDatabase || $m_splitByTable)
        {
            throw new Exception('Setting master dump will not work with splitting up the dump');
        }
        
        $this->m_masterDump = true;
    }
    
    
    /**
     * This is the main bit which actually results in the datbase being dummped.
     * @param void - this object should already have been configured using the constructor/methods
     * @return Array<String> $filenames - the array of files generated by backing up.
     */
    public function run()
    {
        $timeDumpStarted = time();
        $generatedFiles = array();
        
        $databasesSpecification = ' --all-databases ';
        
        if (count($this->m_databases) > 0)
        {
            $databaseNames = array_keys($this->m_databases);
            $databases = implode(' ', $databaseNames);
            $databasesSpecification = ' --databases ' . $databases . ' ';
        }
        
        # @TODO to look into whether its possible for this only to affect this class.
        date_default_timezone_set('UTC');
        
        if ($this->m_singleFileName !== null)
        {
            $outputFilename = $this->m_singleFileName;
        }
        else 
        {
            $outputFilename = 'backup.sql';
        }
        
        if ($this->m_useTimestamp)
        {
            $outputFilename = $timeDumpStarted . '_' . $outputFilename;
        }

        $masterDataSpecification = '';
        if ($this->m_masterDump)
        {
            $masterDataSpecification = " --master-data ";
        }
        
        $outputFilename = $this->m_backupDirectory . '/' . $outputFilename;
        
        $backupCommand = 
            'mysqldump -u ' . $this->m_dbUser . 
            ' -p' . $this->m_dbPassword .
            ' -h' . $this->m_dbHost .
            ' --port ' . $this->m_dbPort .
            $databasesSpecification .
            ' ' . $this->m_consistencySwitch . 
            $masterDataSpecification .
            ' > ' . $outputFilename;

        echo "Exectuing the following command:" . PHP_EOL . $backupCommand . PHP_EOL .
             "This may take a long time." . PHP_EOL;
             
        shell_exec($backupCommand);
        
        /* @TODO Split the dump up based on $m_splitByDatabase and  $splitByTable */
        if ($this->m_splitByTable )
        {
            # create folder for each database and input the files within.
            $folderName = $this->splitDumpFile($outputFilename, $timeDumpStarted);
        }
        else if($this->m_splitByDatabase)
        {
            # create dump files for each database.
            $folderName = $this->splitDumpFile($outputFilename, $timeDumpStarted);
        }
        
        $generatedFiles = array($outputFilename);
        
        return $generatedFiles;
    }


    /**
     * Given the name of a table, return whether it is an innodb table or not.
     * @param String $tableName - the name of the table we want to know about.
     * @return bool - flag indicating whether it is an InnoDB table.
     */
    private function isTableInnoDb($tableName)
    {
        $query = "SHOW TABLE STATUS WHERE Name = '" . $tableName . "'";
        throw new Exception("getTableEngine has not been completed");
        return $engineType;
    }
    
    
    
    /**
     * Validates the a backup direcotry provided is a writeable directory.
     * @param String $backupFolder - the path to the backup directory.
     * @throws Exception for any relevant issues.
     * @return void
     */
    private static function validateBackupDirectory($backupFolder)
    {
        if (!is_dir($backupFolder))
        {
            $errMsg = 'MySqlDumper: ' . $backupFolder  . " is not a directory.";
            throw new Exception($errMsg);
        }
        
        if (!is_writable($backupFolder)) 
        {
            $errMsg = 'MySqlDumper: ' . $backupFolder  . " is not writeable.";
            throw new Exception($errMsg);
        }
    }
    
    
    
    /**
     * List all the databases on the specified host.
     * @parm void
     * @return Array<String> - array of all the database names on the host.
     */
    private function listDatabases()
    {
        $cmd = 
            'mysql' . 
            ' -u ' . $this->m_dbUser . 
            ' -p' . $this->m_m_dbPassword .
            ' -Bse "show databases"';

        $mysqlResponse = shell_exec($cmd);
        $databases = explode(PHP_EOL, $mysqlResponse);
        
        return $databases;
    }


    /**
     * Split large files into smaller ones
     * This function does not simply perform a file_get_contents because it is quite likely that
     * the file is too large to fit into memory.
     * @param string $source - the full/relative path to the source dump file
     * @param int $timestamp - the timestamp when the dump was started
     * @return void
     */
    private function splitDumpFile($source, $timestamp)
    {
        $targetPath = dirname($source) . '/' . $timestamp;
        mkdir($targetPath);

        $databaseName = '';
        $tableName = '';

        # This is only used if we are splitting by database.
        $dbDumpFileHandle = null;

        # These are only used if we are splitting by table.
        $databaseFolder = null;
        $tableFileHandle = null;

        $handle = @fopen ($source, "r");

        while (($line = fgets($handle)) != null)
        {
            if (strpos($line, "Current Database:") !== FALSE)
            {
                $parts = explode('`', $line);
                $databaseName = $parts[1];

                if ($this->m_splitByTable)
                {
                    $databaseFolder = $targetPath . '/' . $databaseName;
                    mkdir($databaseFolder);
                }
                else
                {
                    $dbDumpFile = $targetPath . '/' . $databaseName . '.sql';

                    if ($dbDumpFileHandle != null)
                    {
                        fclose($dbDumpFileHandle);
                    }

                    if (!$dbDumpFileHandle = @fopen($dbDumpFile, 'w')) 
                    {
                        echo "Cannot open file ($dbDumpFile)";
                        exit;
                    }
                }
                
            }
            elseif ($this->m_splitByTable && strpos($line, "DROP TABLE") !== FALSE)
            {
                $parts = explode('`', $line);
                $tableName = $parts[1];
                
                if ($tableFileHandle != null)
                {
                    fclose($tableFileHandle);
                }
                
                $tableFileHandle = fopen($databaseFolder . '/' . $tableName . '.sql', 'w');
            }

            
            if ($dbDumpFileHandle != null)
            {
                if (!@fwrite($dbDumpFileHandle, $line)) 
                {
                    echo "Cannot write to file ($fname)";
                    exit;
                }
            }
            elseif($tableFileHandle != null)
            {
                if (!@fwrite($tableFileHandle, $line)) 
                {
                    echo "Cannot write to file ($fname)";
                    exit;
                }
            }
        }

        if ($dbDumpFileHandle != null)
        {
            fclose ($dbDumpFileHandle);
        }

        if ($tableFileHandle != null)
        {
            fclose ($tableFileHandle);
        }

        unlink($source);

        return $targetPath;
    }
}


