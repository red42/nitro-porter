<?php

/**
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 */

namespace Porter;

use Porter\Database\ResultSet;
use Porter\Database\DbFactory;
use Porter\Log;

/**
 * Object for exporting other database structures into a format that can be imported.
 */
class ExportModel
{
    /**
     * Character constants.
     */
    public const COMMENT = '//';
    public const DELIM = ',';
    public const ESCAPE = '\\';
    public const NEWLINE = "\n";
    public const NULL = '\N';
    public const QUOTE = '"';

    /**
     * @var bool
     */
    public $captureOnly = false;

    /**
     * @var array Any comments that have been written during the export.
     */
    public $comments = array();

    /**
     * @var string The charcter set to set as the connection anytime the database connects.
     */
    public $characterSet = 'utf8';

    /**
     * @var int The chunk size when exporting large tables.
     */
    public $chunkSize = 100000;

    /**
     * @var array *
     */
    public $currentRow = null;

    /**
     * @var string Where we are sending this export: 'file' or 'database'. *
     */
    public $destination = 'file';

    /**
     * @var string *
     * @deprecated
     */
    public $destPrefix = 'GDN_z';

    public $destDb;

    public $beginTime;

    public $endTime;

    public $totalTime;

    /**
     * @var array *
     */
    public static $escapeSearch = array();

    /**
     * @var array *
     */
    public static $escapeReplace = array();

    /**
     * @var resource File pointer
     */
    public $file = null;

    /**
     * @var string Database host. *
     * @deprecated
     */
    public $host = 'localhost';

    /**
     * @var bool Whether mb_detect_encoding() is available. *
     */
    public static $mb = false;

    /**
     * @var object PDO instance
     * @deprecated
     */
    protected $pdo = null;

    /**
     * @var string Database password. *
     * @deprecated
     */
    protected $password;

    /**
     * @var string The path to the export file.
     * @deprecated
     */
    public $path = '';

    /**
     * @var string DB prefix. SQL strings passed to ExportTable() will replace occurances of :_ with this.
     * @see ExportModel::ExportTable()
     * @deprecated
     */
    public $prefix = '';

    /**
     * @var array *
     */
    public $queries = array();

    /**
     * @var array *
     */
    protected $queryStructures = array();

    /**
     * @var array Tables to limit the export to.  A full export is an empty array.
     */
    public $restrictedTables = array();

    /**
     * @var string The path to the source of the export in the case where a file is being converted.
     * @deprecated
     */
    public $sourcePath = '';

    /**
     * @var string
     * @deprecated
     */
    public $sourcePrefix = '';

    /**
     * @var bool *
     */
    public $scriptCreateTable = true;

    /**
     * @var array Structures that define the format of the export tables.
     */
    protected $structures = array();

    /**
     * @var bool Whether to limit results to the $testLimit.
     */
    public $testMode = false;

    /**
     * @var int How many records to limit when $testMode is enabled.
     */
    public $testLimit = 10;

    /**
     * @var bool Whether or not to use compression when creating the file.
     */
    protected $useCompression = true;

    /**
     * @var string Database username.
     * @deprecated
     */
    protected $username;

    /**
     * @var DbFactory Instance DbFactory
     * @deprecated
     */
    protected $dbFactory;


    /**
     * Setup.
     */
    public function __construct($dbFactory)
    {
        $this->dbFactory = $dbFactory;
        self::$mb = function_exists('mb_detect_encoding');

        // Set the search and replace to escape strings.
        self::$escapeSearch = array(self::ESCAPE, self::DELIM, self::NEWLINE, self::QUOTE); // escape must go first
        self::$escapeReplace = array(
            self::ESCAPE . self::ESCAPE,
            self::ESCAPE . self::DELIM,
            self::ESCAPE . self::NEWLINE,
            self::ESCAPE . self::QUOTE
        );

        // Load structure.
        $this->structures = loadStructure();
    }

    /**
     * Selective exports.
     *
     * 1. Get the comma-separated list of tables and turn it into an array
     * 2. Trim off the whitespace
     * 3. Normalize case to lower
     * 4. Save to the ExportModel instance
     *
     * @param string $restrictedTables
     */
    public function loadTables(string $restrictedTables)
    {
        if (!empty($restrictedTables)) {
            $restrictedTables = explode(',', $restrictedTables);

            if (is_array($restrictedTables) && !empty($restrictedTables)) {
                $restrictedTables = array_map('trim', $restrictedTables);
                $restrictedTables = array_map('strtolower', $restrictedTables);

                $this->restrictedTables = $restrictedTables;
            }
        }
    }

    /**
     * Create the export file and begin the export.
     *
     * @param string $source Package name.
     * @return resource Pointer to the file created.
     */
    public function beginExport($source = '')
    {
        $this->comments = array();
        $this->beginTime = microtime(true);

        // Allow us to define where the output file goes.
        if (Request::instance()->get('destpath')) {
            $this->path = Request::instance()->get('destpath');
            if (strstr($this->path, '/') !== false && substr($this->path, 1, -1) != '/') {
                // We're using slash paths but didn't include a final slash.
                $this->path .= '/';
            }
        }

        // Allow the $path parameter to override this default naming.
        $this->path .= 'export_' . date('Y-m-d_His') . '.txt' . ($this->useCompression() ? '.gz' : '');

        // Start the file pointer.
        $fp = $this->openFile();

        // Build meta info about where this file came from.
        $comment = 'Nitro Porter Export';
        if ($source) {
            $comment .= self::DELIM . ' Source: ' . $source;
        }

        // Add meta info to the output.
        if ($this->captureOnly) {
            $this->comment($comment);
        } else {
            fwrite($fp, $comment . self::NEWLINE . self::NEWLINE);
        }

        $this->comment('Export Started: ' . date('Y-m-d H:i:s'));

        return $fp;
    }

    /**
     * Write a comment to the export file.
     *
     * @param string $message The message to write.
     * @param bool   $echo    Whether or not to echo the message in addition to writing it to the file.
     */
    public function comment($message, $echo = true)
    {
        if ($this->destination == 'file') {
            $char = self::COMMENT;
        } else {
            $char = '--';
        }

        $comment = $char . ' ' . str_replace(
            self::NEWLINE,
            self::NEWLINE . self::COMMENT . ' ',
            $message
        ) . self::NEWLINE;

        Log::comment($comment);
        if ($echo) {
            if (defined('CONSOLE')) {
                echo $comment;
            } else {
                $this->comments[] = $message;
            }
        }
    }

    /**
     * End the export and close the export file.
     *
     * This method must be called if BeginExport() has been called or else the export file will not be closed.
     */
    public function endExport()
    {
        $this->endTime = microtime(true);
        $this->totalTime = $this->endTime - $this->beginTime;

        $this->comment($this->path);
        $this->comment('Export Completed: ' . date('Y-m-d H:i:s'));
        $this->comment(sprintf('Elapsed Time: %s', self::formatElapsed($this->totalTime)));

        if ($this->testMode || Request::instance()->get('dumpsql') || $this->captureOnly) {
            $queries = implode("\n\n", $this->queries);
            if ($this->destination == 'database') {
                fwrite($this->file, $queries);
            } else {
                $this->comment($queries, true);
            }
        }

        if ($this->useCompression() && function_exists('gzopen')) {
            gzclose($this->file);
        } else {
            fclose($this->file);
        }
    }

    /**
     * Export a table to the export file.
     *
     * @param string $tableName Name of table to export. This must correspond to one of the accepted Vanilla tables.
     * @param mixed  $query     The query that will fetch the data for the export this can be one of the following:
     *      <b>String</b>: Represents a string of SQL to execute.
     *      <b>PDOStatement</b>: Represents an already executed query result set.
     *      <b>Array</b>: Represents an array of associative arrays or objects containing the data in the export.
     * @param array  $mappings Specifies mappings, if any, between source and export where keys represent source columns
     *   and values represent Vanilla columns.
     *   If you specify a Vanilla column then it must be in the export structure contained in this class.
     *   If you specify a MySQL type then the column will be added.
     *   If you specify an array you can have the following keys: Column,
     *   and Type where Column represents the new column name and Type
     *   represents the MySQL type. For a list of the export tables and columns see $this->Structure().
     */
    public function exportTable($tableName, $query, $mappings = [])
    {
        if (!empty($this->restrictedTables) && !in_array(strtolower($tableName), $this->restrictedTables)) {
            $this->comment("Skipping table: $tableName");
        } else {
            $beginTime = microtime(true);

            $rowCount = $this->exportTableWrite($tableName, $query, $mappings);

            $endTime = microtime(true);
            $elapsed = self::formatElapsed($beginTime, $endTime);
            $this->comment("Exported Table: $tableName ($rowCount rows, $elapsed)");
            fwrite($this->file, self::NEWLINE);
        }
    }

    /**
     * Convert database blobs into files.
     *
     * @param $sql
     * @param $blobColumn
     * @param $pathColumn
     * @param bool $thumbnail
     */
    public function exportBlobs($sql, $blobColumn, $pathColumn, $thumbnail = false)
    {
        $this->comment('Exporting blobs...');

        $result = $this->query($sql);
        $count = 0;
        while ($row = $result->nextResultRow()) {
            // vBulletin attachment hack (can't do this in MySQL)
            if (strpos($row[$pathColumn], '.attach') && strpos($row[$pathColumn], 'attachments/') !== false) {
                $pathParts = explode('/', $row[$pathColumn]); // 3 parts

                // Split up the userid into a path, digit by digit
                $n = strlen($pathParts[1]);
                $dirParts = array();
                for ($i = 0; $i < $n; $i++) {
                    $dirParts[] = $pathParts[1][$i];
                }
                $pathParts[1] = implode('/', $dirParts);

                // Rebuild full path
                $row[$pathColumn] = implode('/', $pathParts);
            }

            $path = $row[$pathColumn];

            // Build path
            if (!file_exists(dirname($path))) {
                $r = mkdir(dirname($path), 0777, true);
                if (!$r) {
                    die("Could not create " . dirname($path));
                }
            }

            if ($thumbnail) {
                $picPath = str_replace('/avat', '/pavat', $path);
                $fp = fopen($picPath, 'wb');
            } else {
                $fp = fopen($path, 'wb');
            }
            if (!is_resource($fp)) {
                die("Could not open $path.");
            }

            fwrite($fp, $row[$blobColumn]);
            fclose($fp);
            $this->status('.');

            if ($thumbnail) {
                if ($thumbnail === true) {
                    $thumbnail = 50;
                }

                $thumbPath = str_replace('/avat', '/navat', $path);
                $this->generateThumbnail($picPath, $thumbPath, $thumbnail, $thumbnail);
            }
            $count++;
        }
        $this->status("$count Blobs.\n");
        $this->comment("$count Blobs.", false);
    }

    /**
     * Process for writing an entire single table to file.
     *
     * @see    ExportTable()
     * @param  string $tableName
     * @param  string $query
     * @param  array $mappings
     * @param  array $options
     * @return int|void
     */
    protected function exportTableWrite($tableName, $query, $mappings = [], $options = [])
    {
        $fp = $this->file;

        // Make sure the table is valid for export.
        if (!array_key_exists($tableName, $this->structures)) {
            $this->comment(
                "Error: $tableName is not a valid export."
                . " The valid tables for export are " . implode(", ", array_keys($this->structures))
            );
            fwrite($fp, self::NEWLINE);

            return;
        }
        if ($this->destination == 'database') {
            //$this->_exportTableDB($tableName, $query, $mappings);

            return;
        }

        // Check for a chunked query.
        $query = str_replace('{from}', -2000000000, $query);
        $query = str_replace('{to}', 2000000000, $query);

        // If we are in test mode then limit the query.
        if ($this->testMode && $this->testLimit) {
            $query = rtrim($query, ';');
            if (stripos($query, 'select') !== false && stripos($query, 'limit') === false) {
                $query .= " limit {$this->testLimit}";
            }
        }

        $structure = $this->structures[$tableName];

        $firstQuery = true;

        $data = $this->executeQuery($query);

        // Loop through the data and write it to the file.
        $rowCount = 0;
        if ($data !== false) {
            while ($row = $data->nextResultRow()) {
                $row = (array)$row; // export%202010-05-06%20210937.txt
                $this->currentRow =& $row;
                $rowCount++;

                if ($firstQuery) {
                    // Get the export structure.
                    $exportStructure = $this->getExportStructure($row, $structure, $mappings, $tableName);
                    $revMappings = $this->flipMappings($mappings);
                    $this->writeBeginTable($fp, $tableName, $exportStructure);

                    $firstQuery = false;
                }
                $this->writeRow($fp, $row, $exportStructure, $revMappings);
            }
        }
        unset($data);
        if (!isset($options['NoEndline'])) {
            $this->writeEndTable($fp);
        }

        return $rowCount;
    }

    /**
     *
     *
     * @param $tableName
     * @param $query
     * @param array $mappings
     */
    protected function createExportTable($tableName, $query, $mappings = [])
    {
        if (!$this->scriptCreateTable) {
            return;
        }

        // Limit the query to grab any additional columns.
        $queryStruct = rtrim($query, ';') . ' limit 1';
        $structure = $this->structures[$tableName];

        $data = $this->query($queryStruct, true);
        //      $mb = function_exists('mb_detect_encoding');

        // Loop through the data and write it to the file.
        if ($data === false) {
            return;
        }

        // Get the export structure.
        while (($row = $data->nextResultRow()) !== false) {
            $row = (array)$row;

            // Get the export structure.
            $exportStructure = $this->getExportStructure($row, $structure, $mappings, $tableName);

            break;
        }

        // Build the create table statement.
        $columnDefs = array();
        foreach ($exportStructure as $columnName => $type) {
            $columnDefs[] = "`$columnName` $type";
        }
        $destDb = '';
        if (isset($this->destDb)) {
            $destDb = $this->destDb . '.';
        }

        $this->query("drop table if exists {$destDb}{$this->destPrefix}$tableName");
        $createSql = "create table {$destDb}{$this->destPrefix}$tableName (\n  " . implode(
            ",\n  ",
            $columnDefs
        ) . "\n) engine=innodb";

        $this->query($createSql);
    }

    /**
     * Applying filter to permission column.
     *
     * @param  $columns
     * @return array
     */
    public function fixPermissionColumns($columns)
    {
        $result = array();
        foreach ($columns as $index => $value) {
            if (is_string($value) && strpos($value, '.') !== false) {
                $value = array('Column' => $value, 'Type' => 'tinyint(1)');
            }
            $result[$index] = $value;
        }

        return $result;
    }

    /**
     * Flip keys and values of associative array.
     *
     * @param  $mappings
     * @return array
     */
    public function flipMappings($mappings)
    {
        $result = array();
        foreach ($mappings as $column => $mapping) {
            if (is_string($mapping)) {
                $result[$mapping] = array('Column' => $column);
            } else {
                $col = $mapping['Column'];
                $mapping['Column'] = $column;
                $result[$col] = $mapping;
            }
        }

        return $result;
    }

    /**
     * For outputting how long the export took.
     *
     * @param  int $start
     * @param  int $end
     * @return string
     */
    public static function formatElapsed($start, $end = null)
    {
        if ($end === null) {
            $elapsed = $start;
        } else {
            $elapsed = $end - $start;
        }

        $m = floor($elapsed / 60);
        $s = $elapsed - $m * 60;
        $result = sprintf('%02d:%05.2f', $m, $s);

        return $result;
    }

    /**
     * Execute an sql statement and return the entire result as an associative array.
     *
     * @param  string $sql
     * @param  bool   $indexColumn
     * @return array
     */
    public function get($sql, $indexColumn = false)
    {
        $r = $this->executeQuery($sql);
        $result = [];

        while ($row = ($r->nextResultRow())) {
            if ($indexColumn) {
                $result[$row[$indexColumn]] = $row;
            } else {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * Determine the character set of the origin database.
     *
     * @param string $table Table to derive charset from.
     * @param string $default Default character set.
     */
    public function setCharacterSet($table, $default = 'utf8')
    {
        $characterSet = $default;
        $update = true;

        // First get the collation for the database.
        $data = $this->query("show table status like ':_{$table}';");
        if (!$data) {
            $update = false;
        }
        if ($statusRow = $data->nextResultRow()) {
            $collation = $statusRow['Collation'];
        } else {
            $update = false;
        }
        unset($data);

        // Grab the character set from the database.
        $data = $this->query("show collation like '$collation'");
        if (!$data) {
            $update = false;
        }
        if ($collationRow = $data->nextResultRow()) {
            $characterSet = $collationRow['Charset'];
            if (!defined('PORTER_CHARACTER_SET')) {
                define('PORTER_CHARACTER_SET', $characterSet);
            }
            $update = false;
        }

        if ($update) {
            $this->characterSet = $characterSet;
        }
    }

    /**
     *
     *
     * @return array
     */
    public function getDatabasePrefixes()
    {
        // Grab all of the tables.
        $data = $this->query('show tables');
        if ($data === false) {
            return array();
        }

        // Get the names in an array for easier parsing.
        $tables = array();
        while ($row = $data->nextResultRow(false)) {
            $tables[] = $row[0];
        }
        sort($tables);

        $prefixes = array();

        // Loop through each table and get its prefixes.
/*        foreach ($tables as $table) {
            $pxFound = false;
            foreach ($prefixes as $pxIndex => $px) {
                $newPx = $this->getPrefix($table, $px);
                if (strlen($newPx) > 0) {
                    $pxFound = true;
                    if ($newPx != $px) {
                        $prefixes[$pxIndex] = $newPx;
                    }
                    break;
                }
            }
            if (!$pxFound) {
                $prefixes[] = $table;
            }
        }*/

        return $prefixes;
    }

    /**
     *
     *
     * @param  $row
     * @param  $tableOrStructure
     * @param  $mappings
     * @param  string $tableName
     * @return array
     */
    public function getExportStructure($row, $tableOrStructure, &$mappings, $tableName = '_')
    {
        $exportStructure = array();

        if (is_string($tableOrStructure)) {
            $structure = $this->structures[$tableOrStructure];
        } else {
            $structure = $tableOrStructure;
        }

        // See what columns to add to the end of the structure.
        foreach ($row as $column => $x) {
            if (array_key_exists($column, $mappings)) {
                $mapping = $mappings[$column];
                if (is_string($mapping)) {
                    if (array_key_exists($mapping, $structure)) {
                        // This an existing column.
                        $destColumn = $mapping;
                        $destType = $structure[$destColumn];
                    } else {
                        // This is a created column.
                        $destColumn = $column;
                        $destType = $mapping;
                    }
                } elseif (is_array($mapping)) {
                    if (!isset($mapping['Column'])) {
                        trigger_error("Mapping for $column does not have a 'Column' defined.", E_USER_ERROR);
                    }

                    $destColumn = $mapping['Column'];

                    if (isset($mapping['Type'])) {
                        $destType = $mapping['Type'];
                    } elseif (isset($structure[$destColumn])) {
                        $destType = $structure[$destColumn];
                    } else {
                        $destType = 'varchar(255)';
                    }
                    //               $mappings[$column] = $destColumn;
                }
            } elseif (array_key_exists($column, $structure)) {
                $destColumn = $column;
                $destType = $structure[$column];

                // Verify column doesn't exist in Mapping array's Column element
                $mappingExists = false;
                foreach ($mappings as $testMapping) {
                    if ($testMapping == $column) {
                        $mappingExists = true;
                    } elseif (
                        is_array($testMapping)
                        && array_key_exists('Column', $testMapping)
                        && ($testMapping['Column'] == $column)
                    ) {
                        $mappingExists = true;
                    }
                }

                // Also add the column to the mapping.
                if (!$mappingExists) {
                    $mappings[$column] = $destColumn;
                }
            } else {
                $destColumn = '';
                $destType = '';
            }

            // Check to see if we have to add the column to the export structure.
            if ($destColumn && !array_key_exists($destColumn, $exportStructure)) {
                // TODO: Make sure $destType is a valid MySQL type.
                $exportStructure[$destColumn] = $destType;
            }
        }

        // Add filtered mappings since filters can add new columns.
        foreach ($mappings as $source => $options) {
            if (!is_array($options)) {
                // Force the mappings into the expanded array syntax for easier processing later.
                $mappings[$source] = array('Column' => $options);
                continue;
            }

            if (!isset($options['Column'])) {
                trigger_error("No column for $tableName(source).$source.", E_USER_NOTICE);
                continue;
            }

            $destColumn = $options['Column'];

            if (!array_key_exists($source, $row) && !isset($options['Type'])) {
                trigger_error("No column for $tableName(source).$source.", E_USER_NOTICE);
            }

            if (isset($exportStructure[$destColumn])) {
                continue;
            }

            if (isset($structure[$destColumn])) {
                $destType = $structure[$destColumn];
            } elseif (isset($options['Type'])) {
                $destType = $options['Type'];
            } else {
                trigger_error("No column for $tableName.$destColumn.", E_USER_NOTICE);
                continue;
            }

            $exportStructure[$destColumn] = $destType;
            $mappings[$source] = $destColumn;
        }

        return $exportStructure;
    }

    /**
     * @param  $sql
     * @param  $default
     * @return mixed
     * @deprecated
     */
    public function getValue($sql, $default)
    {
        $data = $this->get($sql);
        if (count($data) > 0) {
            $data = array_shift($data); // first row
            $result = array_shift($data); // first column

            return $result;
        } else {
            return $default;
        }
    }

    /**
     *
     *
     * @return resource
     */
    protected function openFile()
    {
        $this->path = str_replace(' ', '_', $this->path);
        if ($this->useCompression()) {
            $fp = gzopen($this->path, 'wb');
        } else {
            $fp = fopen($this->path, 'wb');
        }

        $this->file = $fp;

        return $fp;
    }

    /**
     * Execute a SQL query on the current connection.
     *
     * Wrapper for _Query().
     *
     * @param  string $query The sql to execute.
     * @return ResultSet|string|false The query cursor.
     */
    public function query($query)
    {
        if (!preg_match('`limit 1;$`', $query)) {
            $this->queries[] = $query;
        }

        if ($this->destination == 'database' && $this->captureOnly) {
            if (!preg_match('`^\s*select|show|describe|create`', $query)) {
                return 'SKIPPED';
            }
        }

        return $this->executeQuery($query);
    }

    /**
     * Send multiple SQL queries.
     *
     * @param string|array $sqlList An array of single query strings or a string of queries terminated with semi-colons.
     */
    public function queryN($sqlList)
    {
        if (!is_array($sqlList)) {
            $sqlList = explode(';', $sqlList);
        }

        foreach ($sqlList as $sql) {
            $sql = trim($sql);
            if ($sql) {
                $this->query($sql);
            }
        }
    }

    /**
     * Using RestrictedTables, determine if a table should be exported or not
     *
     * @param  string $tableName Name of the table to check
     * @return bool True if table should be exported, false otherwise
     */
    public function shouldExport($tableName)
    {
        return empty($this->restrictedTables) || in_array(strtolower($tableName), $this->restrictedTables);
    }

    /**
     * Echo a status message to the console.
     *
     * @param string $msg
     */
    public function status($msg)
    {
        if (defined('CONSOLE')) {
            echo $msg;
        }
    }

    /**
     * Returns an array of all the expected export tables and expected columns in the exports.
     *
     * When exporting tables using ExportTable() all of the columns in this structure will always be exported
     * in the order here, regardless of how their order in the query.
     *
     * @return array
     */
    public function structures($newStructures = false)
    {
        if (is_array($newStructures)) {
            $this->structures = $newStructures;
        }

        return $this->structures;
    }

    /**
     * Whether or not to use compression on the output file.
     *
     * @param  bool $value The value to set or NULL to just return the value.
     * @return bool
     */
    public function useCompression($value = null)
    {
        if ($value !== null) {
            $this->useCompression = $value;
        }

        return $this->useCompression && $this->destination == 'file' && function_exists('gzopen');
    }

    /**
     * Checks whether or not a table and columns exist in the database.
     *
     * @param  string $table   The name of the table to check.
     * @param  array  $columns An array of column names to check.
     * @return bool|array The method will return one of the following
     *  - true: If table and all of the columns exist.
     *  - false: If the table does not exist.
     *  - array: The names of the missing columns if one or more columns don't exist.
     */
    public function exists($table, $columns = [])
    {
        static $_exists = array();

        if (!isset($_exists[$table])) {
            $result = $this->query("show table status like ':_$table'");
            if (!$result) {
                $_exists[$table] = false;
            } elseif (!$result->nextResultRow()) {
                $_exists[$table] = false;
            } else {
                $desc = $this->query('describe :_' . $table);
                if ($desc === false) {
                    $_exists[$table] = false;
                } else {
                    if (is_string($desc)) {
                        die($desc);
                    }

                    $cols = array();
                    while (($TD = $desc->nextResultRow()) !== false) {
                        $cols[$TD['Field']] = $TD;
                    }
                    $_exists[$table] = $cols;
                }
            }
        }

        if ($_exists[$table] == false) {
            return false;
        }

        $columns = (array)$columns;

        if (count($columns) == 0) {
            return true;
        }

        $missing = array();
        $cols = array_keys($_exists[$table]);
        foreach ($columns as $column) {
            if (!in_array($column, $cols)) {
                $missing[] = $column;
            }
        }

        return count($missing) == 0 ? true : $missing;
    }

    /**
     * Checks all required source tables are present.
     *
     * @param  array $requiredTables
     */
    public function verifySource(array $requiredTables)
    {
        $missingTables = false;
        $countMissingTables = 0;
        $missingColumns = array();

        foreach ($requiredTables as $reqTable => $reqColumns) {
            $tableDescriptions = $this->executeQuery('describe :_' . $reqTable);

            //echo 'describe '.$prefix.$reqTable;
            if ($tableDescriptions === false) { // Table doesn't exist
                $countMissingTables++;
                if ($missingTables !== false) {
                    $missingTables .= ', ' . $reqTable;
                } else {
                    $missingTables = $reqTable;
                }
            } else {
                // Build array of columns in this table
                $presentColumns = array();
                while (($TD = $tableDescriptions->nextResultRow()) !== false) {
                    $presentColumns[] = $TD['Field'];
                }
                // Compare with required columns
                foreach ($reqColumns as $reqCol) {
                    if (!in_array($reqCol, $presentColumns)) {
                        $missingColumns[$reqTable][] = $reqCol;
                    }
                }
            }
        }
        // Return results
        if ($missingTables === false) {
            if (count($missingColumns) > 0) {
                $error = [];
                // Build a string of missing columns.
                foreach ($missingColumns as $table => $columns) {
                    $error[] = "The $table table is missing the following column(s): " . implode(', ', $columns);
                }
                trigger_error(implode("<br />\n", $error));
            }
        } elseif ($countMissingTables == count($requiredTables)) {
            $error = 'The required tables are not present in the database.
                Make sure you entered the correct database name and prefix and try again.';

            // Guess the prefixes to notify the user.
            $prefixes = $this->getDatabasePrefixes();
            if (count($prefixes) == 1) {
                $error .= ' Based on the database you provided,
                    your database prefix is probably ' . implode(', ', $prefixes);
            } elseif (count($prefixes) > 0) {
                $error .= ' Based on the database you provided,
                    your database prefix is probably one of the following: ' . implode(', ', $prefixes);
            }
            trigger_error($error);
        } else {
            trigger_error('Missing required database tables: ' . $missingTables);
        }
    }

    /**
     * Start table write to file.
     *
     * @param resource $fp
     * @param string $tableName
     * @param array $exportStructure
     */
    public function writeBeginTable($fp, $tableName, $exportStructure)
    {
        $tableHeader = '';

        foreach ($exportStructure as $key => $value) {
            if (is_numeric($key)) {
                $column = $value;
                $type = '';
            } else {
                $column = $key;
                $type = $value;
            }

            if (strlen($tableHeader) > 0) {
                $tableHeader .= self::DELIM;
            }

            if ($type) {
                $tableHeader .= $column . ':' . $type;
            } else {
                $tableHeader .= $column;
            }
        }

        fwrite($fp, 'Table: ' . $tableName . self::NEWLINE);
        fwrite($fp, $tableHeader . self::NEWLINE);
    }

    /**
     * End table write to file.
     *
     * @param resource $fp
     */
    public function writeEndTable($fp)
    {
        fwrite($fp, self::NEWLINE);
    }

    /**
     * Write a table's row to file.
     *
     * @param resource $fp
     * @param array $row
     * @param array $exportStructure
     * @param array $revMappings
     */
    public function writeRow($fp, $row, $exportStructure, $revMappings)
    {
        $this->currentRow =& $row;

        // Loop through the columns in the export structure and grab their values from the row.
        $exRow = array();
        foreach ($exportStructure as $field => $type) {
            // Get the value of the export.
            $value = null;
            if (isset($revMappings[$field]) && isset($row[$revMappings[$field]['Column']])) {
                // The column is mapped.
                $value = $row[$revMappings[$field]['Column']];
            } elseif (array_key_exists($field, $row)) {
                // The column has an exact match in the export.
                $value = $row[$field];
            }

            // Check to see if there is a callback filter.
            if (isset($revMappings[$field]['Filter'])) {
                $callback = $revMappings[$field]['Filter'];

                $row2 =& $row;
                $value = call_user_func($callback, $value, $field, $row2, $field);
                $row = $this->currentRow;
            }

            // Format the value for writing.
            if (is_null($value)) {
                $value = self::NULL;
            } elseif (is_integer($value)) {
                // Do nothing, formats as is.
                // Only allow ints because PHP allows weird shit as numeric like "\n\n.1"
            } elseif (is_string($value) || is_numeric($value)) {
                // Check to see if there is a callback filter.
                if (!isset($revMappings[$field])) {
                    //$value = call_user_func($Filters[$field], $value, $field, $row);
                } else {
                    if (self::$mb && mb_detect_encoding($value) != 'UTF-8') {
                        $value = utf8_encode($value);
                    }
                }

                $value = str_replace(array("\r\n", "\r"), array(self::NEWLINE, self::NEWLINE), $value);
                $value = self::QUOTE
                    . str_replace(self::$escapeSearch, self::$escapeReplace, $value)
                    . self::QUOTE;
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            } else {
                // Unknown format.
                $value = self::NULL;
            }

            $exRow[] = $value;
        }
        // Write the data.
        fwrite($fp, implode(self::DELIM, $exRow));
        // End the record.
        fwrite($fp, self::NEWLINE);
    }

    /**
     * SQL to get the file extension from a string.
     *
     * @param  string $columnName
     * @return string SQL.
     */
    public static function fileExtension($columnName)
    {
        return "right($columnName, instr(reverse($columnName), '.') - 1)";
    }

    /**
     * Execute a SQL query on the current connection.
     *
     * @param string $sql
     * @return ResultSet|false instance of ResultSet of success false on failure
     */
    private function executeQuery($sql)
    {
        $sql = str_replace(':_', $this->prefix, $sql); // replace prefix.
        if ($this->sourcePrefix) {
            $sql = preg_replace("`\b{$this->sourcePrefix}`", $this->prefix, $sql); // replace prefix.
        }

        $sql = rtrim($sql, ';') . ';';

        $dbResource = $this->dbFactory->getInstance();
        return $dbResource->query($sql);
    }

    /**
     * Escaping string using the db resource
     *
     * @param string $string
     * @return string escaped string
     */
    public function escape($string)
    {
        $dbResource = $this->dbFactory->getInstance();
        return $dbResource->escape($string);
    }

    /**
     * Determine if an index exists in a table
     *
     * @param  string $indexName
     * @param  string $table
     * @return bool
     */
    public function indexExists($indexName, $table)
    {
        $result = $this->query("show index from `$table` WHERE Key_name = '$indexName'");

        return $result->nextResultRow() !== false;
    }

    /**
     * Determine if a table exists
     *
     * @param string $tableName
     * @return bool
     */
    public function tableExists($tableName)
    {
        $result = $this->query("show tables like '$tableName'");

        return !empty($result->nextResultRow());
    }

    /**
     * Determine if a column exists in a table
     *
     * @param string $tableName
     * @param string $columnName
     * @return bool
     */
    public function columnExists($tableName, $columnName)
    {
        $result = $this->query(
            "
            select column_name
            from information_schema.columns
            where table_schema = database()
                and table_name = '$tableName'
                and column_name = '$columnName'
        "
        );
        return $result->nextResultRow() !== false;
    }

    /**
     * Create a thumbnail from an image file.
     *
     * @param string $path
     * @param string $thumbPath
     * @param  int $height
     * @param  int $width
     * @return void
     */
    public function generateThumbnail($path, $thumbPath, $height = 50, $width = 50)
    {
        list($widthSource, $heightSource, $type) = getimagesize($path);

        $XCoord = 0;
        $YCoord = 0;
        $heightDiff = $heightSource - $height;
        $widthDiff = $widthSource - $width;
        if ($widthDiff > $heightDiff) {
            // Crop the original width down
            $newWidthSource = round(($width * $heightSource) / $height);

            // And set the original x position to the cropped start point.
            $XCoord = round(($widthSource - $newWidthSource) / 2);
            $widthSource = $newWidthSource;
        } else {
            // Crop the original height down
            $newHeightSource = round(($height * $widthSource) / $width);

            // And set the original y position to the cropped start point.
            $YCoord = round(($heightSource - $newHeightSource) / 2);
            $heightSource = $newHeightSource;
        }

        try {
            switch ($type) {
                case 1:
                    $sourceImage = imagecreatefromgif($path);
                    break;
                case 2:
                    $sourceImage = @imagecreatefromjpeg($path);
                    if (!$sourceImage) {
                        $sourceImage = imagecreatefromstring(file_get_contents($path));
                    }
                    break;
                case 3:
                    $sourceImage = imagecreatefrompng($path);
                    imagealphablending($sourceImage, true);
                    break;
            }

            $targetImage = imagecreatetruecolor($width, $height);
            imagecopyresampled(
                $targetImage,
                $sourceImage,
                0,
                0,
                $XCoord,
                $YCoord,
                $width,
                $height,
                $widthSource,
                $heightSource
            );
            imagedestroy($sourceImage);

            switch ($type) {
                case 1:
                    imagegif($targetImage, $thumbPath);
                    break;
                case 2:
                    imagejpeg($targetImage, $thumbPath);
                    break;
                case 3:
                    imagepng($targetImage, $thumbPath);
                    break;
            }
            imagedestroy($targetImage);
        } catch (\Exception $e) {
            echo "Could not generate a thumnail for " . $targetImage;
        }
    }
}
