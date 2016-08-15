<?php
/**
* @file csv2mysql.php
*
* @package library/misc
*
* A quick and dirty means of creating a mysql table from a csv file. 
* A header row is required for the csv, and can be optionally used to create the 
* table column names. 
*
* @example: invoke from a shell prompt, eg: $php csv2mysql.php
*
* Configuration: 
* 
* All variables can be set in script explicity or better, by extending.
*
* NOTE: The csv is assumed to come from a trusted source.
*
* @author programming@dbswebsite.com  2010-10-27
*
*
* LICENSE
*
*    This program is free software: you can redistribute it and/or modify
*    it under the terms of the GNU General Public License as published by
*    the Free Software Foundation, either version 3 of the License, or
*    (at your option) any later version.
*
*    This program is distributed in the hope that it will be useful,
*    but WITHOUT ANY WARRANTY; without even the implied warranty of
*    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*    GNU General Public License for more details.
*
*    You should have received a copy of the GNU General Public License
*    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
*
*/


// set some high values for command line
ini_set( 'memory_limit','512M' );
set_time_limit( 0 );
error_reporting ( E_ALL );

new csv2mysl();

/**
* @class csv2mysql
*/
class csv2mysql
{

	protected $csv			= null;		// csv file name, **required**
	protected $db			= null;		// database name, will be named from the csv if is_null.
	protected $table_name		= null;		// table name, will be named from the csv if is_null.
	protected $db_host		= 'localhost';
	protected $db_user		= 'root';
	protected $db_password		= '';

	public    $autoexec			= true;	// run from constructor
	protected $debug			= true;
	protected $create			= true;	// dynamically create table structure from csv header or not. TODO: not tested if this is FALSE :/
	protected $insert_ignore		= false;	// INSERT IGNORE syntax for possible duplicate keys
	protected $truncate			= true;	// whether to truncate the table or not (only meaningul if using a predefined table structure)
	protected $first_column_key		= true;	// Inserts a unique index as the first column, if true.
	protected $columns			= array(); // Column names, if empty the csv 1st row header titles will be used.
	protected $custom_sql			= null;	// custom sql to run after the table has been built, can be a reference to a file, or inline sql statement(s)
	protected $varchar_size			= 255;
	protected $limit			= false; 	// integer > 0, limit number of records (for testing purposes typically)


	/**
	* @return void
	*
	* Constructor, parses configs and sets up table building.
	*/
	function __construct() 
	{
		if ( $this->autoexec ) {
			$this->exec();
		}
	}

	/**
	* @return void
	*
	* Run all the sub-processes.
	*
	*/
	public function exec() 
	{

		if ( $this->debug ) echo "Running exec commands\n";

		if ( ! is_file( $this->csv ) ) { 
			$this->help( "Cannot find your csv file, aborting\n" );
		}
		
		// make our db name and table name from the csv, if none are supplied
		if ( ! $this->table_name ) {
			$this->table_name = $this->sanitize( str_replace( '.csv', '', basename( $this->csv ) ) );
			if ( $this->debug ) echo "Creating table $this->table_name\n";
		}
		if ( ! $this->db ) { 
			$this->db = $this->table_name;
			if ( $this->debug ) echo "Creating database $this->db\n";
		}
		
		// connect to db 
		mysql_connect( $this->db_host, $this->db_user, $this->db_password ) || die( 'Unable to connect to db host' );

		// create the database, if we need to
		mysql_query("CREATE DATABASE IF NOT EXISTS $this->db");

		// open db
		mysql_select_db( $this->db ) || die( 'Cannot connect to database, bummer' );

		// do it!!! //////
		$this->add_data();
		//////////////////

		// run code for AFTER the data is imported
		$this->post_process();

	}

	/**
	* @return void
	*
	* Quick and dirty -- populate a database table from a csv file.
	*/
	protected function add_data() 
	{
		if ( $this->debug ) echo "adding data now ...\n";

		$row = 0;
		$handle = fopen( $this->csv, "r" );
		if ( ! $handle ) { 
			$this->help( "Error opening your csv file, aborting\n" );
		}

		// if its not there, create it from the csv header row, etc
		$this->create_table();

		// remove header row
		fgetcsv( $handle, 20000, "," );

		$ignore = ( $this->insert_ignore ) ? 'IGNORE' : '';

		// Now Loop through csv data rows, putting together the pieces.
		while (( $data = fgetcsv($handle, 4096, "," )) !== FALSE ) {
			
			// prep incoming csv data
			$data = array_map( 'mysql_real_escape_string', $data );
			
			// make it a MySQL friendly statement
			$cdata = '("' . implode( '","', $data ) . '")';
			
			// construct query from pieces
			$sql = "INSERT $ignore INTO `$this->table_name` VALUES " . $cdata;
		
			// make it stick
			mysql_query( $sql ) || die("Error adding data table at $row: " . mysql_error() );
			$row++;

			if ( $this->limit && $row > $this->limit ) {
				if ( $this->debug ) echo "Breaking at: # $row row\n";
				break;
			}
			if ( $this->debug ) echo "Adding row: # $row\n";
		}

		/* TODO: This may conflict with other options / configurations ??? */
		if ( $this->first_column_key ) {
			
			// add a unique key as first column
			$first_col_id = $this->table_name . '_id';
			$sql = "ALTER TABLE `$this->table_name` ADD `$first_col_id` int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT FIRST";
			mysql_query( $sql ) || die( 'Error creating index' );
		}

		if ( $this->debug ) echo "Done.\n";
	}

	/**
	* @return void
	*
	* Now we create columns as varchars from the header row, or config. And, at
	* least give it one indexed column (the first column), if configured so.
	*/
	protected function create_table()
	{
		// if we aren't set up to (re)create this table, then bug out now.
		if ( ! $this->create ) {
		
			if ( $this->truncate ) {
				mysql_query( "TRUNCATE `$this->table_name`" );
			}

			// if we aren't generating the table, then we should not inject the primary key column
			$this->first_column_key = false;
			return;
		}

		// (re)create table.
		mysql_query( "DROP TABLE IF EXISTS `$this->table_name`" ) || die('Error dropping table' . $this->table_name);

		$handle = fopen( "$this->csv", "r" );

		// Extract first (header) record only, use for mysql column names unless our config options has a predefined array of column names.
		$_columns = fgetcsv( $handle, 2048, "," );

		if ( empty( $this->columns ) ) {
			
			// Extract first (header) record only to use for mysql column
			// names, if config options does not provide column names.
			$this->columns = $_columns;
		}
		
		$sql= "CREATE TABLE IF NOT EXISTS `$this->table_name` (";
		for( $i=0;$i<count( $this->columns ); $i++ ) {
			
			// clean up the header row
			$this->columns[$i] = $this->sanitize( $this->columns[$i] );

			if ( empty ( $this->columns[$i] ) ) $this->columns[$i] = 'NONE_SUPPLIED';
			$sql .= $this->columns[$i].' VARCHAR('. $this->varchar_size .'), ';
		}
		
		//The line below gets rid of the comma
		$sql = substr($sql,0,strlen($sql)-2);
		$sql .= ')';
		fclose( $handle );
		mysql_query( $sql ) || die( 'Error creating table' );
		if ( $this->debug ) echo "Table is created and ready\n";
	}

	/**
	* @return void
	*
	* Run any code we need to run AFTER the data has been imported.
	*
	* @author Hal Burgiss  2012-11-25
	*/
	protected function post_process() 
	{
		if ( ! empty( $this->custom_sql ) ) {
			if ( $this->debug ) echo "Running post processing code\n";
			if ( is_file( $this->custom_sql ) ) {

				// not tested :(
				$out = shell_exec( "mysql -h$this->db_host -u$this->db_user -p$this->db_password $this->db < $this->custom_sql" );
				if ( $out ) {
					die( $out );
				}
			} else {
				mysql_query( $this->custom_sql ) || die( "Error running custom_sql: " . mysql_error() );;
			}
		}
	}

	/**
	* @return string $name, with certain characters removed
	*
	* @param string $name
	*
	* Remove characters that are illegal or don't make for good mysql names.
	*
	*/
	protected function sanitize( $name ) 
	{
		$name = str_replace( ' ', '_', $name );
		return str_replace( array( "'", "/",'\\',".",'"','?','$','-','*','`','+', ','), '', $name );
	}

	/**
	* HELP!. Spit this message out on command line errors.
	*/
	protected function help( $error = '' ) 
	{
		echo "
		$error

		HELP

		This script will:
			- create an empty table from a csv header row
			- create / recreate table from csv
			- populate the table with the csv data rows

		This script is intended to be run from the command line. All variables
		can be set by extending the class. 

		The newly created table will have a primary key index created (default configuration). 
		All other columns will be created as VARCHARs.
";
		die();

	}
}

