<?php

/*
 * Class to make the dumping of mysql tables incredibly easy
 * 
 * This class outputs each table into its own dump file.
 * 
 * NOTE: This will use "--lock-all-tables" (unless you run useSingleTransaction) which will 
 * "Lock all tables across all databases" , which will result in databases that aren't involved 
 * still being read locked whilst the dump is taking place.
 * 
 * 
 * Snapshotting/Consistency.
 * =========================
 * The aim of this class is to enable "snapshotting" across multiple databases so that there is 
 * a consistent "image" which is necessary if you have a system spread across databases. If you 
 * find that this has too much of an impact on performance and dont need consistency between 
 * databases, then simply create multiple of these objects, one for each database group that need
 * to be consistent with each other.
 * 
 * Limitations:
 * This cannot handle tables spread across different hosts.
 * 
 * Example Usage:
 * ==============
 * $dumper = new TableDumper('/tmp', 'localhost', 'my-database', 'root', 'password', array('table1', 'table2'));
 * $dumper->useSingleFile('my_dump');
 * $dumper->run();
 * 
 * For more advanced features you may want to look at the database dumper instead.
 *
 * Tested to work on Ubuntu Server 12.04 LTS
 */

namespace programster/mysqldumper;


class TableDumper
{
    private $m_consistencySwitch = '--lock-tables'; # lock-all-tables isn't necessary as only 1 db
    private $m_backupDirectory;
    private $m_database;
    private $m_tables;
    private $m_useTimestamp = true;
    
    private $m_dbUser;
    private $m_dbHost;
    private $m_dbPassword;
    private $m_dbPort;

    private $m_isRds = false; # flag indicating if this is an RDS instance taking backup from.
    private $m_masterDump = false; # flag for if taking a master dump to create replicas from.
    private $m_singleFileName = null; # If this is null then we will delfault to $timestamp.sql
    private $m_oneRowPerInsert = true;
    private $m_displayColumnsOnInserts = true;
    private $m_useTransferCompression = true;
    private $m_skipComments = true; # disable this to allow dump date to be on the end of last table

   
    /**
     * Constructs this object in preperation for running a backup.
     * @param type $backupFolder - the path to the folder we will dump backup files to
     * @param type $dbHost - the mysql host where the database(s) are located
     * @param type $dbUsername - the username to connect with.
     * @param type $dbPassword - the password to connect with.
     * @param Array<string> $tables - list of tables that we wish to dump.
     * @param type $dbPort - the port to connect on.
     * @throws Exception - if that directory is not writeable.
     */
    public function __construct($backupFolder,
                                $dbHost,
                                $dbUsername, 
                                $dbPassword,
                                $dbName,
                                $tables,
                                $dbPort=3306)
    {        
        self::validateBackupDirectory($backupFolder);
        
        $this->m_backupDirectory = $backupFolder;
        $this->m_dbHost          = $dbHost;
        $this->m_database        = $dbName;
        $this->m_tables          = $tables;
        $this->m_dbUser          = $dbUsername;
        $this->m_dbPassword      = $dbPassword;
        $this->m_dbPort          = $dbPort;

        # Disable transfer compression if we are on the same host!
        if (strtolower($dbHost) == 'localhost' || strtolower($dbHost) == '127.0.0.1')
        {
            $this->m_useTransferCompression = false;
        }

        if (stripos($dbHost, 'rds.amazonaws.com') !== FALSE)
        {
            $warningMsg =
                PHP_EOL .
                "WARNING" . PHP_EOL .
                "=======" . PHP_EOL .
                "DatabaseDumper has detected that you are snapshotting an RDS instance. " . 
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
     * Make the dumped output file as small as possible. 
     * This just compresses the inset data into one line and does not show column names on that
     * insert.
     * This is not the same as mysqldump's --compress switch which compresses all information 
     * between the client and the host if both support it. That is enabled by default and can be
     * disabled by calling disableTransferCompression.
     * Please note that this is NOT the same as
     * using mysqldump's --compact switch which essentially is the same as specifying 
     * --skip-add-drop-table, --skip-add-locks, --skip-comments, --skip-disable-keys, and 
     * --skip-set-charset options. 
     * @param void.
     */
    public function compressOutput()
    {
        $this->m_oneRowPerInsert = false;
        $this->m_displayColumnsOnInserts = false;
    }


    /**
     * Disable the flag to compress all information sent between the client and the server for the
     * dump. Note that we enable this by default when the host is not 'localhost'. Just because 
     * compression is enabled does not mean that it will actually be used since it requies that 
     * both the host and client support it.
     * @param void
     * @return void
     */
    public function disableTransferCompression()
    {
        $this->m_useTransferCompression = false;
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
     * Comments can be included to include the dump date and the mysql version. However they are
     * disabled by default due to the fact that we split the dump into individual tables, so the
     * info is only on the first and last table files.
     * @param void
     * @return void
     */
    public function enableComments()
    {
        $this->m_skipComments = false;
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

        $outputFilename = $this->m_backupDirectory . '/' . $outputFilename;

        $extendedInsertSpecification = '';

        if ($this->m_oneRowPerInsert)
        {
            $extendedInsertSpecification = ' --extended-insert=FALSE ';
        }

        $completeInsertSpecification = '';
        
        if ($this->m_displayColumnsOnInserts)
        {
            $completeInsertSpecification = ' --complete-insert ';
        }

        $transferCompressionSpecification = '';

        if ($this->m_useTransferCompression)
        {
            $transferCompressionSpecification = ' --compress ';
        }

        $commentSpecification = '';
        if ($this->m_skipComments)
        {
            $commentSpecification = ' --skip-comments ';
        }

        $tablesSpecification = '';

        foreach ($this->m_tables as $table)
        {
            # we need to wrap the names in quotes to allow hyphens in table names.
            $tablesSpecification .= '"' . $table . '" ';
        }

        $backupCommand = 
            'mysqldump -u ' . $this->m_dbUser . 
            ' -p' . $this->m_dbPassword .
            ' -h' . $this->m_dbHost .
            ' --port ' . $this->m_dbPort .
            ' ' . $this->m_consistencySwitch . ' ' .
            $extendedInsertSpecification .
            $completeInsertSpecification . 
            $transferCompressionSpecification .
            $commentSpecification .
            $this->m_database . ' ' .
            $tablesSpecification .
            ' > ' . $outputFilename;

        echo "Exectuing the following command:" . PHP_EOL . $backupCommand . PHP_EOL .
             "This may take a long time." . PHP_EOL;
             
        shell_exec($backupCommand);
        
        $folderName = $this->splitDumpFile($outputFilename, $timeDumpStarted);
        
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
            $errMsg = 'DatabaseDumper: ' . $backupFolder  . " is not a directory.";
            throw new Exception($errMsg);
        }
        
        if (!is_writable($backupFolder)) 
        {
            $errMsg = 'DatabaseDumper: ' . $backupFolder  . " is not writeable.";
            throw new Exception($errMsg);
        }
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
        $databaseFolder = $targetPath . '/' . $this->m_database;
        mkdir($targetPath);
        mkdir($databaseFolder);

        $tableName = '';

        # This is only used if we are splitting by database.
        $dbDumpFileHandle = null;

        # These are only used if we are splitting by table.
        $tableFileHandle = null;

        $handle = @fopen ($source, "r");

        while (($line = fgets($handle)) != null)
        {
            if (strpos($line, "DROP TABLE") !== FALSE)
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


