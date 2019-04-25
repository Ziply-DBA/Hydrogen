<?php

include_once (dirname(__FILE__).'/debug.php');
require_once (dirname(__FILE__).'/../settings.php');
/*

(all this should come from /settings.php)
set default db connection string values
create array for dataSource objects
create array for saved SQL objects
define SQL statement class
define dataSource class with lots of easy interfaces
instantiate dataSource class with defaults and save as 'default' array member
provide short-name $dds reference to 'default' data source

define ("DEFAULT_DB_TYPE","oracle"); 	// set default database type
define ("DEFAULT_DB_USER","scott"); 	// set default database user
define ("DEFAULT_DB_PASS","tiger"); // set default database password
define ("DEFAULT_DB_HOST","localhost"); // set default database host
define ("DEFAULT_DB_PORT","1521"); 	// set default database port
define ("DEFAULT_DB_INST","XE"); 		// set default database name/instance/schema
define ("DEFAULT_MAX_RECS",150);

*/



$dataSource=array();
$savedSQL=array();

//echo "Connected.";
//oci_free_statement($stmt);
//oci_close($conn);

class dataSource {

	protected $dbconn;
	protected $maxRecs;
	protected $cursor;
	protected $stmt;
	protected $mysqli;
	protected $mysqli_result;
	protected $dbType;
	protected $colNames;
	protected $colTypes;
	protected $unlimitedSQL;
	protected $page_count;
	protected $page_num;

	function limitSQL($sql) {
		if (strpos('.'.strtoupper($sql),'INSERT')==1) return $sql;
		if (strpos('.'.strtoupper($sql),'ALTER')==1) return $sql;
		if (strpos('.'.strtoupper($sql),'UPDATE')==1) return $sql;
		if (!isset($this->page_num)) $this->page_num=1;
		debug("Page num: $this->page_num");
		if (isset($this->page_count)) {
			if ($this->page_num > $this->page_count) $this->page_num=$this->page_count;
		}
		$start_rec=(($this->page_num-1)*$this->maxRecs)+1;
		$end_rec=$start_rec+$this->maxRecs-1;

		switch ($this->dbType) {
			case 'oracle':
				//ugh  . . .
				//http://www.oracle.com/technetwork/issue-archive/2006/06-sep/o56asktom-086197.html
				$prepend="select * from ( select /*+ FIRST_ROWS(n) */   a.*, ROWNUM rnum from ( ";
				$append=") a where ROWNUM <=  " . ($start_rec + $this->maxRecs - 1) . ")  where rnum  >= $start_rec";
				return $prepend . " " . $sql . " " . $append;
				break;
			default:
				return $sql . " " . " limit " . ($start_rec - 1) . " , " . $this->maxRecs;
		}
	}

	public function __construct(
		$dbType=DEFAULT_DB_TYPE,
		$dbUser=DEFAULT_DB_USER,
		$dbPass=DEFAULT_DB_PASS,
		$dbHost=DEFAULT_DB_HOST,
		$dbPort=DEFAULT_DB_PORT,
		$dbInst=DEFAULT_DB_INST) {
		debug("Constructing dataSource class");
		$this->setMaxRecs();
		$this->dbType=$dbType;
		switch ($this->dbType) {
			case 'oracle':
				debug("Connecting to Oracle");
			    $dbstring=$dbHost . ":" . $dbPort . "/" . $dbInst;
				$this->dbconn = oci_connect($dbUser, $dbPass, $dbstring) or die("Connection to DB failed." . oci_error());
				$this->setSQL("alter session SET NLS_DATE_FORMAT = 'RRRR-MM-DD HH24:MI:SS'" );
				break;
			default:
				//mysql
				debug("Connecting to mysql");
				$this->mysqli=new mysqli($dbHost, $dbUser, $dbPass,$dbInst);
				if (mysqli_connect_errno()) {
				    die ("Connect failed: ".  mysqli_connect_error());
				}
				//$this->dbconn = mysql_connect($dbHost, $dbUser, $dbPass) or die("Connection to DB failed." . mysql_error());
				//$result = mysql_select_db($dbInst, $this->dbconn) or die("Error selecting DB." . mysql_error());
		}
	}

	public function setMaxRecs($int=DEFAULT_MAX_RECS) {
		$this->maxRecs=$int;
	}

	public function setPageNum($page_num) {
		$this->page_num=$page_num;
		if ($this->page_num < 1) $this->page_num=1;
		if (isset($this->page_count)) {
			if ($page_num > $this->page_count) $page_num=$this->page_count;
		}
	}

	function setSQL($unlimited_sql) {
		unset($this->page_count);
		$this->unlimited_sql=$unlimited_sql;
		$sql=$this->limitSQL($unlimited_sql);
		$this->colNames=array();
		$this->colTypes=array();
		debug("class-limited SQL: $sql");
			switch ($this->dbType) {

			case 'oracle':
				//Parse the statement
				$stmt = oci_parse($this->dbconn,$sql) or die ( oci_error($this->dbconn));
				//execute the query
				$result= oci_execute($stmt) or die ("Error querying DB with SQL:" . $sql . " Error message: " . oci_error($this->dbconn));
				$this->stmt=$stmt;
				//get metadata
				$ncols=oci_num_fields($this->stmt);
				for ($i = 1; $i <= $ncols; $i++) {
					$this->colNames[$i-1] = oci_field_name($stmt, $i);
					$this->colTypes[$i-1] = oci_field_type($stmt, $i);
				}
				break;
			default:
				//mysql
				$result = $this->mysqli->query($sql) or die ("Error querying DB with SQL:" . $sql . " Message: " . $this->mysqli->error);
				$this->mysqli_result=$result;

				if (strpos(strtoupper($sql),'INSERT')===0) return $sql;
				else if (strpos(strtoupper($sql),'UPDATE')===0) return $sql;
				else {


					//get metadata
					$finfo = $result->fetch_fields();
					$ncols=count($finfo);
					debug ("MySQL result set column count: ".$ncols);
					$i=1;
					foreach ($finfo as $val) {
							$this->colNames[$i-1] = $val->name;
							$this->colTypes[$i-1] = $val->type;
							$i++;
					}
				}
			}
	}

	function paginate() {
		debug ("function: cls_dataSource:pagination");
		$count_sql="SELECT COUNT(*) FROM (" . $this->unlimited_sql . ")";
		switch ($this->dbType) {
			case 'oracle':
				//Parse the statement
				$stmt = oci_parse($this->dbconn,$count_sql) or die ( oci_error($this->dbconn));
				//execute the query
				$result= oci_execute($stmt) or die ("Error querying DB with SQL:" . $count_sql . " Error message: " . oci_error($this->dbconn));
				$result_row = oci_fetch_array($stmt,OCI_NUM+OCI_RETURN_NULLS);
				break;
			default:
				//mysql
				$count_sql=$count_sql . " as aggr";
				$result = $this->mysqli->query($count_sql) or die ("Error querying DB with SQL:" . $count_sql . " Message: " . $this->mysqli->error);
				$result_row = $result->fetch_array(MYSQLI_NUM);
		}
		$rec_count=$result_row[0];
		if ($this->maxRecs==0) $this->maxRecs==$rec_count;
		$this->page_count=ceil($rec_count/$this->maxRecs);
	}

	function getPageCount() {
		if (!isset($this->page_count)) $this->paginate();
		return $this->page_count;
	}

	public function getFieldNames() {
			return $this->colNames;
	}

	public function getFieldTypes() {
			return $this->colTypes;
	}

	public function getInt($sql) {
		if ($result_row=$this->getNextRow()) {
				$int=(int)$result_row[0] or die ("Data type conversion error");
				return $int;
		}
	}

	public function getString($sql) {
		if ($result_row=$this->getNextRow()) {
				$str=(string)$result_row[0] or die ("Data type conversion error");
				return $str;
		}
	}

	public function getNextRow($arraytype="indexed") {
		switch ($this->dbType) {
			case "oracle":
				if ($arraytype=="indexed") {
					$result_row = oci_fetch_array($this->stmt,OCI_NUM+OCI_RETURN_NULLS);
				} else {
					$result_row = oci_fetch_array($this->stmt,OCI_ASSOC+OCI_RETURN_NULLS);
				}
				break;
			default:
				if ($arraytype=="indexed") {
					$result_row = $this->mysqli_result->fetch_array(MYSQLI_NUM);
				} else {
					$result_row = $this->mysqli_result->fetch_array(MYSQLI_ASSOC);
				}
			}
		return $result_row;
	}

	function getDataset() {
			$rownum=0;
			while ($result_rows[$rownum] = $this->getNextRow()){
					$rownum++;
			}
			return $result_rows;
	}


}

if (!isset($dataSource['default'])) {
	debug("Creating default data source");
	$dataSource['default']=new dataSource();
}
	$dds = $dataSource['default'];
?>
