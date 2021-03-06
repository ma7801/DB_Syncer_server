<?php
/*****************************************
/* DB_Syncer class definition
/*****************************************/


/*
NOTES:


POST object received by server:

{action:["delete", "insert", or "update"]
 data_columns: [the columns from the data table being affected]
 data_values [the values from the data table being affected (in the same order as data_columns)]
 id: [the id number for the record being synced]
 max_id: [the highest id in the table for the record being synced (on client/device)]
 remote_db: [the server's database name]
 table [the name of the table being synced]
 id_col: [the server's id field name]
 timestamp: [the timestamp value from the timestamp table on the client - only sent for "update" action]
}

*/

define("SERVER_TO_CLIENT", 1);
define("CLIENT_TO_SERVER", 2);


// DB_Syncer constructor 


class DB_Syncer {
    private $mysqli;

    public function __construct($mysqli_conn) {
        $this->mysqli = $mysqli_conn;

        // Create the sync_action table if it doesn't already exist
        $sql = "CREATE TABLE IF NOT EXISTS _dbs_sync_actions (" .
                "id INTEGER PRIMARY KEY AUTO_INCREMENT, " .
                "table_name VARCHAR(20), " .
                "record_id INTEGER, " .
                "sync_action VARCHAR(20), " .
                "timestamp DATETIME)"; 
                
        $result = $this->mysqli->query($sql);
        
        if($this->mysqli->errno) {
            $this->error_handler("Error in creating _dbs_sync_actions table: " . $this->mysqli->error, 
                    $this->mysqli->errno);
        
        }
        
        // Create the vars table if it doesn't exist already
        $sql = "CREATE TABLE IF NOT EXISTS _dbs_vars (" .
                "id INTEGER PRIMARY KEY, " .
                "db_locked BOOLEAN," . 
                "sync_table_reduced BOOLEAN)";
                
        $result = $this->mysqli->query($sql);
        
        if($this->mysqli->errno) {
            $this->error_handler("Error in creating _dbs_vars table: " . $this->mysqli->error, 
                    $this->mysqli->errno);
        
        }
        
        // Insert the default values - if already there, will generate code 1062
        $sql = "INSERT INTO _dbs_vars (id, db_locked, sync_table_reduced) VALUES (1, 0, 0) ";

        $result = $this->mysqli->query($sql);
        
    
        if($this->mysqli->errno) { 
            // Ignore error code 1062: duplicate key error is expected if first table row already existed
            if ($this->mysqli->errno !== 1062) {
                
                $this->error_handler("Error in setting values of _dbs_vars table: " . $this->mysqli->error, 
                          $this->mysqli->errno);
            }
            /*else {
                $sql = "UPDATE _dbs_vars SET db_locked=0, sync_table_reduced=0, WHERE id=?";
                $result = mysqli_prepared_query($this->mysqli, $sql, "i", 1);
                
                if(!$result) {
                    $this->error_handler("Error reseting values of _dbs_vars table: " . $this->mysqli->error,
                        $this->mysqli->errno);
                
                }
            
            } */     
        }
        
            

    }
    
    public static function db_exists($db_name) {
        
        // ***NEEDS TESTING
        
        require("DB_Syncer_settings.php");
    
        $mysql = new mysqli($db['hostname'], $db['username'], $db['password']);
   
        $db_exists = $mysql->select_db($db_name);
        
        $mysql->close();
        
        return $db_exists;
    }
    
    public static function initialize_db($db_name, $table_names, $table_defs) {
        
        require("DB_Syncer_settings.php");
    
        // ***NEEDS TESTING
        
        // don't forget to convert sqlite definitions to mysql!! 
        //  - get rid of any double quotes
        //  - change AUTOINCREMENT to AUTO_INCREMENT
        
        // AND don't forget to create the triggers
        
        $mysql = new mysqli($db['hostname'], $db['username'], $db['password']);
        
        if($mysql->connect_errno) {
            $response['err'] = $mysql->connect_errno;
            $response['message'] = "Could not connect to MySQL ";
            return $response;
        }
        
        // Create the database
        $sql = "CREATE DATABASE IF NOT EXISTS " . $db_name;
        $mysql->query($sql);
        
        if($mysql->errno) {
            $response['err'] = $mysql->errno;
            $response['message'] = "Error creating database on server: " . $mysql->error;
            return $response;
            
        }
        
        // Select the newly created database
        $mysql->select_db($db_name);
        
        if($mysql->errno) {
            $response['err'] = $mysql->errno;
            $response['message'] = "Error selecting database on server: " . $mysql->error;
            return $response;
            
        }
        
        
        
        // Convert the sqlite to mysql - get rid of any double quotes and change AUTOINCREMENT to AUTO_INCREMENT
        $table_defs = str_ireplace("\"", "", $table_defs);
        $table_defs = str_ireplace("AUTOINCREMENT", "AUTO_INCREMENT", $table_defs);
        
        echo "after sqlite->mysql table_defs:";
        print_r($table_defs);
        
        // Create the tables, adding "IF NOT EXISTS" in create table statement if it isn't already there
        for ($cur = 0; $cur < sizeof($table_defs); $cur++) {
            $sql_array_temp = explode(" ", $table_defs[$cur]);
            
            echo ("sql_array_temp after exploding:");
            print_r($sql_array_temp);
            
            // Remove any empty elements that might result from extra whitespace, and get rid of whitespace
            $sql_array = array();
            $sql_array_pos = 0;
            for ($cur_word = 0; $cur_word < sizeof($sql_array_temp); $cur_word++) {
                if(trim($sql_array_temp[$cur_word]) === "") {
                   continue;
                }
                else {
                    $sql_array[$sql_array_pos++] = trim($sql_array_temp[$cur_word]);
                }
            }
            
            echo ("sql_array after whitespace removal");
            print_r($sql_array);
            
            $contains_if_not_exists = FALSE;
            
            // See if sql contains "if not exists" (case insensitive)
            for ($cur_word = 0; $cur_word < sizeof($sql_array); $cur_word++) {
                if(!strcasecmp($sql_array[$cur_word], "if") && 
                  !strcasecmp($sql_array[$cur_word + 1], "not") && 
                  !strcasecmp($sql_array[$cur_word + 2], "exists")) {
                
                    $contains_if_not_exists = TRUE;
                    break;
                }
            }
            
            // If sql statement does not contain "if not exists" add it in
            if (!$contains_if_not_exists) {
                // Look for 'table' within the sql statement
                for ($cur_word = 0; $cur_word < sizeof($sql_array); $cur_word++) {
                    if(!strcasecmp(trim($sql_array[$cur_word]), "table")) {
                        // Insert "IF NOT EXISTS"
                        $sql_array = array_merge(array_slice($sql_array, 0, $cur_word + 1), 
                                array("IF", "NOT", "EXISTS"), array_slice($sql_array, $cur_word + 1));
                        break;
                    
                    }
                    
                }
            
            }
            
            $def_stmt = implode(" ", $sql_array);
            echo "sql_array: ";
            print_r($sql_array);
            echo "def_stmt: " . $def_stmt;
            
            $mysql->query($def_stmt);
            
            if($mysql->errno) {
                $response['err'] = $mysql->errno;
                $response['message'] = "Error creating table(s) on server: " . $mysql->error;
                return $response;
            }
        
            // Create triggers
            
            // Insert trigger
            
            // (drop first if it exists)
            $sql = "DROP TRIGGER IF EXISTS insert_" . $table_names[$cur];
                        $mysql->query($sql);
            
            if($mysql->errno) {
                $response['err'] = $mysql->errno;
                $response['message'] = "Error dropping INSERT trigger on server: " . $mysql->error;
                return $response;
            
            }
            
            
            $sql = "CREATE TRIGGER insert_" . $table_names[$cur] . " AFTER INSERT ON " .
                    $table_names[$cur] . " FOR EACH ROW " .
                    " BEGIN " .

                        "INSERT INTO _dbs_sync_actions (table_name, record_id, sync_action, timestamp) VALUES " .
                        "('" . $table_names[$cur] . "', NEW.id, 'insert', UTC_TIMESTAMP());" .
                    " END;";
            
            $mysql->query($sql);
            
            if($mysql->errno) {
                $response['err'] = $mysql->errno;
                $response['message'] = "Error creating INSERT trigger on server: " . $mysql->error;
                return $response;
            
            }
            
            
            // Update trigger
            
             // (drop first if it exists)
            $sql = "DROP TRIGGER IF EXISTS update_" . $table_names[$cur];
                        $mysql->query($sql);
            
            if($mysql->errno) {
                $response['err'] = $mysql->errno;
                $response['message'] = "Error dropping UPDATE trigger on server: " . $mysql->error;
                return $response;
            
            }
            
            $sql = "CREATE TRIGGER update_" . $table_names[$cur] . " AFTER UPDATE ON " .
                    $table_names[$cur] . " FOR EACH ROW " .
                    " BEGIN " .
                        "INSERT INTO _dbs_sync_actions (table_name, record_id, sync_action, timestamp) VALUES " .
                        "('" . $table_names[$cur] . "', NEW.id, 'update', UTC_TIMESTAMP());" .
                    " END;";
            
            $mysql->query($sql);
            
            if($mysql->errno) {
                $response['err'] = $mysql->errno;
                $response['message'] = "Error creating UPDATE trigger on server: " . $mysql->error;
                return $response;
            
            }
            
            // Uncomment the following when non-logical deletes are implemented
            
            /*
            // Delete trigger   
            
             // (drop first if it exists)
            $sql = "DROP TRIGGER IF EXISTS delete_" . $table_names[$cur];
                        $mysql->query($sql);
            
            if($mysql->errno) {
                $response['err'] = $mysql->errno;
                $response['message'] = "Error dropping DELETE trigger on server: " . $mysql->error;
                return $response;
            
            }
                     
            $sql = "CREATE TRIGGER IF NOT EXISTS delete_" . $table_names[$cur] . " AFTER DELETE ON " .
                    $table_names[$cur] . " FOR EACH ROW " .
                    " BEGIN " .
                        "INSERT INTO _dbs_sync_actions (table_name, record_id, sync_action, timestamp) VALUES " .
                        "('" . $table_names[$cur] . "', OLD.id, 'delete', UTC_TIMESTAMP());" .
                    " END;";
            
            $mysql->query($sql);
            
            if($mysql->errno) {
                $response['err'] = $mysql->errno;
                $response['message'] = "Error creating DELETE trigger on server: " . $mysql->error;
                return $response;
            
            }
            */
            
            // Create the _dbs tables by calling the constructor
            $temp = new DB_Syncer($mysql);          
                
        }
    }
    /*
    public function log_delete($table, $id) {
        $this->log_sync_action($table, $id, "delete");
    }
    
    public function log_insert($table, $id) {
        $this->log_sync_action($table, $id, "insert");
    }
    
    public function log_update($table, $id) {
        $this->log_sync_action($table, $id, "update");
    }
    
    public function log_sync_action($table, $id, $type) {
        // log_sync_action(type, id) : Called by user manually every time a change in the local database is made
        //
        //  type can be either "insert", "delete", or "update"
        //  id is the id number of the record changed

        require_once("mysqli_prepared.php");

        $sql = "INSERT INTO _dbs_sync_actions (table_name, record_id, sync_action, timestamp) " . 
                "VALUES (?, ?, ?, UTC_TIMESTAMP())";
        
         
        $result = mysqli_prepared_query($this->mysqli, $sql, "sis", array($table, $id, $type));
        
        if(!$result) {
            $this->error_handler("Error inserting sync action record: " . $this->mysqli->error, 
                                    $this->mysqli->errno);
        } 
        
        // Indicate that the sync_table is no longer reduced
        $sql = "UPDATE _dbs_vars SET sync_table_reduced=0";
        
        $result = $this->mysqli->query($sql);
    	
    	if($this->mysqli->errno) {
    	     DB_Syncer::error_handler_static(
    	        "Error setting sync_table_reduced flag in _dbs_vars (log_sync_action)", $this->mysqli->errno);
    	}
        /*
        // Update or create the timestamp for this record
       	if($type === "insert") {  // If the type of action is an insert
 
            $sql = "INSERT INTO _dbs_timestamps (table_name, record_id, timestamp) " . 
                    "VALUES (?,?,UTC_TIMESTAMP())";
        }   		
        
        else {  // If it's an update or delete
  
            $sql = "UPDATE _dbs_timestamps SET timestamp=UTC_TIMESTAMP() WHERE table_name=? AND " .
        					"record_id=?";
        					
        }
      
        
        $result = mysqli_prepared_query($this->mysqli, $sql, "si", array($table, $id));
        
        if(!$result) {
             $this->error_handler("Error inserting/updating timestamp record: " . $this->mysqli->error, 
                                    $this->mysqli->errno);
        }
        
         
    }
    */
     
    private function error_handler($msg, $errno) {
        die($msg . " (Error number " . $errno . ")<br>");
    }
    
    public static function error_handler_static($msg, $errno) {
        die($msg . " (Error number " . $errno . ")<br>");
    }
    
    /*
    public static function sync_server_to_client($db) {
        // Called by DB_Syncer_listener when client to server sync is complete
        // Assumes sync table is already reduced
        
        // Get all of the sync actions
        $sql = "SELECT * from _dbs_sync_actions";
        $result = $db->query($sql);

        $num_sync_records = $result->num_rows;
        
        $is_last_record = FALSE;

        // For each sync record        
        for ($cur_sync = 0; $cur_sync < $num_sync_records; $cur_sync++) {
            //If this is the last record
            if ($cur_sync === $num_sync_records - 1) {
                $is_last_record = TRUE;
            }                        
        
        }
        
        
    }
    */
    
    public static function reduce_sync_table($db) {
       	// @NEEDS TESTING
    	// Reduces the sync table to avoid exchanging unnecessary information with the clientmn
    	// This will reduce two (or more) records as follows:
    	//  First action         /      Latest Action           /    Reduced Action
    	//    Insert                      Update                     Insert with latest update data
    	//    Insert                      Delete                     none / remove all actions
    	//    Update                      Update                     Update with latest update data
    	//    Update                      Delete                     Delete
    	
    	// First check to see if the table has already been reduced
    	$sql = "SELECT sync_table_reduced FROM _dbs_vars";
    	
    	$result = $db->query($sql);
    	
    	if($db->errno) {
    	     DB_Syncer::error_handler_static("Error selecting from _dbs_vars (reduce_sync_table)",
             $db->errno);
    	}
    	
    	$vars = $result->fetch_array();
    	
    	if($vars['sync_table_reduced']) {
    	    // Sync table has already been reduced
    	    return;
    	}
    	
    	
    	// Load in the sync actions table
        $sql = 	"SELECT * FROM _dbs_sync_actions ORDER BY table_name, record_id, timestamp";
        
        $result = $db->query($sql);
        
        if(!$result) {
            DB_Syncer::error_handler_static("Error selecting records from _dbs_sync_actions (reduce_sync_table)",
             $db->errno);
        }
        
        // Load the sync table into an array
        $cur_row = $result->fetch_array();
        $cur = 0;
        while($cur_row) {
            $sync_table[$cur++] = $cur_row;
            $cur_row = $result->fetch_array();
        }
        $num_actions = $result->num_rows;

   		  
        $record_ids_processed = array(); // An array of the record_ids that have been handled
        $tables_processed = array(); // Once we process an entire table's sync records, we push it on here

		// Compare each of the ids of each record to that of the other and see if there are any
		//  repeated ids (if so, handle the reduction
		for ($left = 0; $left < $num_actions; $left++) {
    	    $older_indices = array();
 	    
 	        //print_r($older_indices);
 	        
 	      	// Skip this record_id if it's already been processed
    		if (in_array($sync_table[$left]['record_id'], $record_ids_processed) ||
    		    in_array($sync_table[$left]['table_name'], $tables_processed)) {
    			continue;
    		}
    	    
    	    // Indicate that the current record has been processed so it will be skipped in the future
    	    array_push($record_ids_processed, $sync_table[$left]['record_id']);
			
			
			$latest_index = -1;  // Indication that there is no duplicate
			for ($right = $left + 1; $right < $num_actions; $right++) {
			
			    // See if this is the last record of the current table_name
			    if ($sync_table[$left]['table_name'] !== $sync_table[$right]['table_name']) {
			        // Indicate that every record in the current table has processed; we need to do this
			        //  since we're reseting the record_ids_processed array below, and we don't want any
			        //  other records in the current table_name processed - we're done with it
			        array_push($tables_processed, $sync_table[$left]['table_name']);
			        
			        // Reset the record_ids_processed array
			        $record_ids_processed = array();
			        
			        // We're not going to find anymore matches; stop looking
			        break;
			    }
	    		
	    		// See if the record ids match (we already know the table names do)
				if ($sync_table[$left]['record_id'] === $sync_table[$right]['record_id']) {
					// The "left" record_id matches the "right" record_id; 
					
					// In case that this isn't the latest record, we need to save a list of all
					//  previous "latest" indices.  (i.e. if latest index has been set)
					if ($latest_index >= 0) {
					    array_push($older_indices, $latest_index);
						//print_r($older_indices);
					}
					
					//  save the index of this record as the "latest" (it may not be the latest, but
					//   is at this point in the loop)
					$latest_index = $right;
				}
				else {
				    // Since record_ids are in order, we won't find the 'left' record id any further down
				    //  the table, so stop looking
				    break;
				
				}
			}
				
				
			// If there was more than one action for a record_id
			if ($latest_index >= 0) {
				$oldest_index = $left;  // For readability of code only
				    					
				// Is the older action an insert?
				if ($sync_table[$oldest_index]['sync_action'] === "insert") {
						
					// Situation 1: insert then update
					if ($sync_table[$latest_index]['sync_action'] === "update") {
					    
						// Remove the older sync records (oldest_index and and the older_indices)
						// First, put the oldest id on the stack of older_indices
						array_push($older_indices, $oldest_index);
							
						// Remove all older records
						for ($cur = 0; $cur < count($older_indices); $cur++) {
							$sql = "DELETE FROM _dbs_sync_actions WHERE id=?";
	
	    					$result = mysqli_prepared_query($db, $sql, "i", array(
	    					    $sync_table[$older_indices[$cur]]['id']));	
	    					
	    					if (!$result) {
	    					    DB_Synder::error_handler_static("Error deleting older sync records " .     
	    					        "(reduce_sync_table)", $db->errno);
	    					}
	    					    
						}
							
						// Change the action of the latest "update" record to "insert" 
						$sql = "UPDATE _dbs_sync_actions SET sync_action='insert' WHERE id=?";
						$result = mysqli_prepared_query($db, $sql, "i", array(
						    $sync_table[$latest_index]['id']));
						    
						if (!$result) {
						    DB_Syncer::error_handler_static("Error changing 'update' to " .
						     "'insert' (reduce_sync_table)", $db->errno);
						}
					}
					
					// Situation 2: insert then delete
					else if ($sync_table[$latest_index]['sync_action'] === "delete") {
					
					    // Remove all intermediate sync records (older_indices only)
						//  need to have record inserted on server and then marked as deleted
						//  (reduces to two actions minimum)
						// UPDATE:
						//  Now reduces to just one action - an insert; the data record should already
						//   be marked as deleted on the client, so the data, including the deleted flag
						//   will be sent to the server.  A delete action then need not be sent to the server.
					    if (count($older_indices) === 0) {
							// 2nd to last index is the oldest index; save this index
							$second_to_last_index = $oldest_index;
						}
						else {
							// 2nd to last index is the last one put in the older_indices stack;
							// Save this index
						    $second_to_last_index = array_pop($older_indices);
					    	// Add the oldest index - we'll need to delete that too
					    	array_push($older_indices, $oldest_index);
					    }
					    
					    // And the latest index too - we want to delete that now
						array_push($older_indices, $latest_index);
						
						// Remove all actions except the 2nd to last one (it should have been removed
						//  from the older_indices stack above)
						for ($cur = 0; $cur < count($older_indices); $cur++) {
						    $sql = "DELETE FROM _dbs_sync_actions WHERE id=?";
							
							$result = mysqli_prepared_query($db, $sql, "i", array(
	    				    $sync_table[$older_indices[$cur]]['id']));	

	    					if(!$result) {    	    					
	    					    DB_Syncer::error_handler_static("Error deleting older sync records " .
	    					      " (reduce_sync_table)", $db->errno);
	    					
	    					}
							
						}
						
						// Change the second to last action to an insert if it isn't already
						if ($sync_table[$second_to_last_index]['sync_action'] !== 'insert') {
							
							$sql = "UPDATE _dbs_sync_actions SET sync_action='insert' WHERE id=?";
							
							$result = mysqli_prepared_query($db, $sql, "i", array(
	    				    $sync_table[$second_to_last_index]['id']));	

	    					if(!$result) {    	    					
	    					    DB_Syncer::error_handler_static("Error updating older sync record " .
	    					      " (reduce_sync_table)", $db->errno);
	    					}
					    }
				
    						
					}
					else {
						DB_Syncer::error_handler_static("Unexpected error in sync table reduction: latest ". 
						    "sync record with duplicate record_id is neither an update or a delete" .
							" (first record was an INSERT).", $db->errno);
					}
				}
						
						
					
				// Is the older action an update?
				if ($sync_table[$oldest_index]['sync_action'] === "update") {
					
					// Situation 3: update then another update AND situation 4: update then delete
					if ($sync_table[$latest_index]['sync_action'] === "update" || 
					 $sync_table[$latest_index]['sync_action'] === "delete") {
						// Remove the older records (oldest_index & older_indices), just keep
						//  the most recent, whether it be an update or a delete
						// First, push the oldest index on the older_indices stack
						array_push($older_indices, $oldest_index);
						
						// Remove all older records
						for ($cur = 0; $cur < count($older_indices); $cur++) {
							$sql = "DELETE FROM _dbs_sync_actions WHERE id=?";
							
							
							$result = mysqli_prepared_query($db, $sql, "i", array(
	    					    $sync_table[$older_indices[$cur]]['id']));	

	    					if(!$result) {    	    					
	    					    DB_Syncer::error_handler_static("Error deleting older sync records " .
	    					      " (reduce_sync_table)", $db->errno);
	    					
	    					}
						}
					}
					else {
						DB_Syncer::error_handler_static("Unexpected error in sync table reduction: " .
						  " latest sync record with duplicate record_id is neither an update or a delete" .
						  " (first record was an UPDATE).", $db->errno);
					}
				}
            }
        }
        
        // Set the flag indicating the sync table has been reduced
        $sql = "UPDATE _dbs_vars SET sync_table_reduced=1";
        
        $result = $db->query($sql);
    	
    	if($db->errno) {
    	     DB_Syncer::error_handler_static(
    	        "Error setting sync_table_reduced flag in _dbs_vars (reduce_sync_table)", $db->errno);
    	}
    	
    	// Backup the sync table for debugging purposes
    	//DEBUG:
    	DB_Syncer::_backup_sync_table($db);
    	
    }
    // Development functions
    public function _drop_dbs_tables() {
        $sql = "DROP TABLE _dbs_sync_actions";
        
        $this->mysqli->query($sql);
        
        if($this->mysqli->errno) {
            $this->error_handler("Error dropping _dbs_sync_actions: " . $this->mysqli->error, 
                            $this->mysqli->errno);
        }
        /*
        $sql = "DROP TABLE _dbs_timestamps";
        
        $this->mysqli->query($sql);
        
        if($this->mysqli->errno) {
            $this->error_handler("Error dropping _dbs_timestamps: " . $this->mysqli->error, 
                            $this->mysqli->errno);
        }*/
    
    
    }
    
    public static function _backup_sync_table($db) {
        $sql = "DROP TABLE IF EXISTS _dbs_last_sync_reduction";
        
        $db->query($sql);
        
        if($db->errno) {
            DB_Syncer::error_handler_static("Error dropping _dbs_last_sync_reduction: " . $db->error, 
                        $db->errno);
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS _dbs_last_sync_reduction AS SELECT * FROM _dbs_sync_actions";
        
        $db->query($sql);
        
        if($db->errno) {
            DB_Syncer::error_handler_static("Error dropping _dbs_last_sync_reduction: " . $db->error, 
                        $db->errno);
            return;
        }

    }
    

}
?>
