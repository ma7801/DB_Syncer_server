<?php

/* Test server listener for a web app using DB_Syncer */
require_once("DB_Syncer.php");

// DEBUG - log errors in a file
ini_set("log_errors", 1);
ini_set("error_reporting", E_ALL);
ini_set("error_log", "/var/www/DB_Syncer/errors.log");
error_log("test");

$max_user_records = 1000;

$mysqli = open_database();

$dbs = new DB_Syncer($mysqli);

if ($_POST['action'] === "insert") {

    $sql = "INSERT INTO test_values (data) VALUES (" . $_POST['value'] . ")";
    $mysqli->query($sql);
    $id = $mysqli->insert_id;

}
else if ($_POST['action'] === "update") {
    $sql = "UPDATE test_values SET data=" . $_POST['value'] . " WHERE id=" . $_POST['id'];
    $mysqli->query($sql);
}
else if ($_POST['action'] === "delete") {
    $sql = "UPDATE test_values SET _is_deleted=1 WHERE id=" . $_POST['id'];
    $mysqli->query($sql);



}
else if ($_POST['action'] === "reset") {
    $sql = "UPDATE random_user_records SET already_used=0";
    $mysqli->query($sql);
}
else if ($_POST['action'] === "random_data") {
    $num_records = $_POST['value'];
	
	for ($cur = 0; $cur < $num_records; $cur++) {
		$sql = "INSERT INTO test_values (data) VALUES (" . rand(0,999) . ")";
        $mysqli->query($sql);
        
        $record = get_random_user_record($mysqli, $max_user_records);
        
        // Insert into test_users table
        $sql = 'INSERT INTO test_users (name, email, country, description, number, created) VALUES ' .
            '("' . $record['name'] . '","' . $record['email'] . '","' . $record['country'] . '","' . 
            $record['description'] . '","' . $record['number'] . '","' . $record['created'] . '")';
        $mysqli->query($sql);
        if($mysqli->errno) {
            echo ("Error: " . $mysqli->error);
        }
 
	}
	

}
else if ($_POST['action'] === "random_actions") {
    $num_actions = $_POST['value'];
	$actions = array("insert", "update", "delete");

    // See how many records there are
	$sql = "SELECT * FROM test_values";
    $result = $mysqli->query($sql);
	$num_records = $result->num_rows;
	
	$sql = "SELECT * FROM test_users";
	$result = $mysqli->query($sql);
	$num_user_records = $result->num_rows;
	
	$deleted_records = array();
	
	for ($cur = 0; $cur < $num_actions; $cur++) {

		$cur_action = $actions[rand(0,2)];

		if ($cur_action === "insert") {
			$sql = "INSERT INTO test_values (data) VALUES (" . rand(0, 999) .")";
			$mysqli->query($sql);
			
			$num_records++;
			
			// Get a random user record
			$record = get_random_user_record($mysqli, $max_user_records);
			
			$sql = 'INSERT INTO test_users (name, email, country, description, number, created) VALUES ' .
            '("' . $record['name'] . '","' . $record['email'] . '","' . $record['country'] . '","' . 
            $record['description'] . '","' . $record['number'] . '","' . $record['created'] . '")';
			
			$mysqli->query($sql);
            if($mysqli->errno) {
                echo ("Error: " . $mysqli->error);
            }
            
		} 
		else if ($cur_action === "update") {

            
			$random_id = rand(1,$num_records);
			$iteration = 0;
			while (in_array($random_id, $deleted_records)) {
			
				$random_id = rand(1,$num_records);
				$iteration++;
				if($iteration > 10000) { break; }  // No infinte looping!

			}

			$sql = "UPDATE test_values SET data=-" . rand(0,999) . " WHERE id=" . $random_id;
			$mysqli->query($sql);
			
			
			$record = get_random_user_record($mysqli, $max_user_records);
			$sql = 'UPDATE test_users SET name="UPDATED_ON_SERVER ' . $record['name'] . '", email="' . $record['email'] .
			    '" WHERE id=' . $random_id;
			$mysqli->query($sql);
            if($mysqli->errno) {
                echo ("Error: " . $mysqli->error);
            }
           
		} 
		else if ($cur_action === "delete") {
			$random_id = rand(1,$num_records);
		
			$sql = "UPDATE test_values SET _is_deleted=1 WHERE id=" . $random_id;
            $mysqli->query($sql);
   			
            array_push($deleted_records, $random_id);
            
            $sql = "UPDATE test_users SET _is_deleted=1 WHERE id=" . $random_id;
            $mysqli->query($sql);
   			
            
		}
	}
	
	
}
else if ($_POST['action'] === "sync") {

// Will send some kind of signal to the ***CLIENT*** to start syncing
// Perhaps through a method in DB_Syncer


}




function open_database() {
    require_once("DB_Syncer_settings.php");
    
    $mysql = new mysqli($db['hostname'], $db['username'], $db['password'], "_sync_test_two");
    
    if($mysql->connect_errno) {
        
            die("Failed to connect to MySQL: (" . $mysql->connect_errno . ") " . $mysql->connect_error);
    
    }
    
    
    else return $mysql;

}

function get_random_user_record($mysqli, $max_user_records) {
     // Get random record from random_user_records table that hasn't been used already
        do {
            $sql = "SELECT * FROM random_user_records WHERE id=" . rand(0, $max_user_records - 1);
            $result = $mysqli->query($sql);
            $record = $result->fetch_array();
        } while ($record['already_used']);
            
        // Set record as used
        $sql = "UPDATE random_user_records SET already_used=1 WHERE id=" . $record['id'];
        $mysqli->query($sql);
        if($mysqli->errno) {
            echo ("Error: " . $mysqli->error);
        }   
        
        return $record;
}

?>
