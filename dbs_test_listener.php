<?php

/* Test server listener for a web app using DB_Syncer */
require_once("DB_Syncer.php");

// DEBUG - log errors in a file
ini_set("log_errors", 1);
ini_set("error_reporting", E_ALL);
ini_set("error_log", "/var/www/DB_Syncer/errors.log");
error_log("test");

$mysqli = open_database();

$dbs = new DB_Syncer($mysqli);

if ($_POST['action'] === "insert") {

    $sql = "INSERT INTO test_data (data) VALUES (" . $_POST['value'] . ")";
    $mysqli->query($sql);
    $id = $mysqli->insert_id;
    $dbs->log_insert("test_data", $id);

}
else if ($_POST['action'] === "update") {
    $sql = "UPDATE test_data SET data=" . $_POST['value'] . " WHERE id=" . $_POST['id'];
    $mysqli->query($sql);
    $dbs->log_update("test_data", $_POST['id']);
}
else if ($_POST['action'] === "delete") {
    $sql = "UPDATE test_data SET _is_deleted=1 WHERE id=" . $_POST['id'];
    $mysqli->query($sql);
    $dbs->log_delete("test_data", $_POST['id']);



}
else if ($_POST['action'] === "random_data") {
    $num_records = $_POST['value'];
	
	for ($cur = 0; $cur < $num_records; $cur++) {
		$sql = "INSERT INTO test_data (data) VALUES (" . rand(0,999) . ")";
        $mysqli->query($sql);
        $dbs->log_insert("test_data", $mysqli->insert_id);
	}

}
else if ($_POST['action'] === "random_actions") {
    $num_actions = $_POST['value'];
	$actions = array("insert", "update", "delete");

    // See how many records there are
	$sql = "SELECT * FROM test_data";
    $result = $mysqli->query($sql);
	$num_records = $result->num_rows;
	
	for ($cur = 0; $cur < $num_actions; $cur++) {

		$cur_action = $actions[rand(0,2)];

		if ($cur_action === "insert") {
			$sql = "INSERT INTO test_data (data) VALUES (" . rand(0, 999) .")";
			$mysqli->query($sql);
			$dbs->log_insert("test_data", $mysqli->insert_id);
			$num_records++;
		} 
		else if ($cur_action === "update") {

            
			$random_id = rand(1,$num_records);
			$iteration = 0;
			while (in_array($random_id, $deleted_records)) {
			
				$random_id = rand(1,$num_records);
				$iteration++;
				if($iteration > 10000) { break; }  // No infinte looping!

			}

			$sql = "UPDATE test_data SET data=-" . rand(0,999) . " WHERE id=" . $random_id;
			$mysqli->query($sql);
			$dbs->log_update("test_data", $random_id);
		} 
		else if ($cur_action === "delete") {
			$random_id = rand(1,$num_records);
		
			$sql = "UPDATE test_data SET _is_deleted=1 WHERE id=" . $random_id;
            $mysqli->query($sql);
   			$dbs->log_delete("test_data", $random_id);
            array_push($deleted_records, $random_id);
		}
	}
}
else if ($_POST['action'] === "sync") {

// Will send some kind of signal to the ***CLIENT*** to start syncing
// Perhaps through a method in DB_Syncer


}




function open_database() {
    require_once("DB_Syncer_settings.php");
    
    $mysql = new mysqli($db['hostname'], $db['username'], $db['password'], "test");
    
    if($mysql->connect_errno) {
        
            die("Failed to connect to MySQL: (" . $mysql->connect_errno . ") " . $mysql->connect_error);
    
    }
    
    
    else return $mysql;

}


?>
