<?php
/*****************************************
/* DB_Syncer server listener
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


Data object sent back from server (JSON):

{err: [error number, 0= success],
 message: [error message],
 action: [insert, delete, or update],
 has_new_id: [=1 if new id had to be set for this record, =0 if not],
 new_id: [the new id number if it has one],
 keep_original: [=1 if client should create a new record for the 
 old_id: [the original id number that was sent from client],
 table: [name of the table that was affected],
 id_col_name: [name of the id column for this table],
 
*/

 
// ***NEED handler if database doesn't exist on server (i.e. create it)
//  --difficulty here - knowing the datatype to use for each column


//require_once("DB_Syncer_settings.php");




// ***DEBUG:
//print_r($_POST);

require_once("mysqli_prepared.php");
require_once("DB_Syncer.php");
require_once("DB_Syncer_settings.php");



if($_POST['action'] === "initialize_db") {
    
    $err = DB_Syncer::initialize_db($_POST['remote_db'], $_POST['table_names'], $_POST['table_defs']);
    if(!$err) {
        respond_success($response);
    }
    else {
        respond_error("Error creating database on server", $err);
    }


}


// Make sure the specified database exists on the server
if(!DB_Syncer::db_exists($_POST['remote_db'])) {
    respond_error("Database does not exist on server.  Call method initialize_server_db on " .
                "client to create and initialize the database on the server.", 1);
    

}


// Open the server database
$mysql = open_database();



// Reduce the sync table
DB_Syncer::reduce_sync_table($mysql);

if($_POST['direction'] === "client_to_server") { 
    // First check to see if there are any local actions (conflicts) for the record_id sent by client
    $sql = "SELECT * FROM _dbs_sync_actions WHERE record_id=? AND table_name=?";

    $result = mysqli_prepared_query($mysql, $sql, "is", array($_POST['id'], $_POST['table']));

    if($result) {
        // Set a flag 
        $server_conflict = TRUE;
        $server_record = $result[0];
        
    }
    else {
        $server_conflict = FALSE;
    }
}



// If the action sent by client is a delete
if($_POST['action'] === "delete") {
    // Setup the default values of the response back to the client
    $response = array(
        "err" => 0,
        "message" => "Deleted corresponding record on server. ",
        "action" => "delete",
        "has_new_id" => 0,
        "new_id" => 0,
        "old_id" => $_POST['id'],
        "table" => $_POST['table'], 
        "id_col" => $_POST['id_col']);



    //See if there is a conflict
    if($server_conflict) {
        
        // @TEST NEEDED
        
        // See what the conflicting server action is
        if($server_record['sync_action'] === "update") {
            // @TEST NEEDED

            // Create time object for the server timestamp
            $server_timestamp = strtotime($server_record['timestamp']);
   
            // Create time object for the client timestamp
            $client_timestamp = strtotime($_POST['timestamp']);
            
            //If the conflict policy is to take the latest action, and the latest action is the server's update
            //  action OR if the conflict policy is to always update (regardless of timestamp) then...
            if(($conflict_policies['update_vs_delete'] === LATEST_ACTION && 
             $server_timestamp > $client_timestamp) || 
             $conflict_policies['update_vs_delete'] === ALWAYS_UPDATE) {

                // @TEST NEEDED
                
                // Change the default message indicating there was a conflict, but all is well
                $response['message'] =  "Delete vs. Update conflict, server 'update' newer...ignoring delete " . 
                    "per conflict policy.";
                respond_success($response);

            } 
            else if ($conflict_policies['update_vs_delete'] === LATEST_ACTION && 
             $server_timestamp < $client_timestamp) {
             
                // @TEST NEEDED

                // Client "delete" action timestamp newer...set message as such
                $response['message'] = "Delete vs. Update conflict, client 'delete' newer...deleting per " .
                    " conflict policy.";
            }
        }
        else if($server_record['sync_action'] === "delete") {
            // @TEST NEEDED
    
            // Delete vs. delete conflict -- remove the unneccessary 'delete' action record from server's 
            //  sync_actions table
            $sql = "DELETE FROM _dbs_sync_actions WHERE id=?";
            
            $result = mysqli_prepared_query($mysql, $sql, "i", array($server_record['id']));
        
            $response['message'] = "Delete vs Delete conflict...removed redundant server delete action record";
        }
    
    }

    // @TEST NEEDED
    
    
    // Logically delete the record indicated by the id #
    $result = delete_record($mysql);
    
    
    if(!$result) {

        // Return an error
        respond_error("Could not delete record on server database: " . $mysql->error, $mysql->errno);
    }

    else {
        // Return success (error code 0)
        respond_success($response);
    }
    

}

else if($_POST['action'] === "insert") {
   
    $result = insert_record($mysql);
    
    if(!$result) {
        respond_error("Error inserting the new records: " . $mysql->error, $mysql->errno);
    }
    else {
        // Delete the automatically inserted sync record - we don't want this created during sync, only if the
        //  developer actually inserts a record in their own code
        $sql = "DELETE FROM _dbs_sync_actions WHERE record_id=? AND table_name=?";
        
        $result_sync_delete = mysqli_prepared_query($mysql, $sql, "is", 
                        array($mysql->insert_id, $_POST['table']));
        
        if(!$result_sync_delete) {
            respond_error("Error deleting automatically generated sync record.");
        
        }
        
        $response = array(
            "err" => $result['mysql_errno'],
            "message" => $result['mysql_error'] === "" ? "Inserted record on server." : $result['mysql_error'],
            "action" => "insert",
            "has_new_id" => $result['has_new_id'],
            "new_id" => $result['new_id'],
            "old_id" => $_POST['id'],
            "table" => $_POST['table'], 
            "id_col" => $_POST['id_col']);

        respond_success($response);
    }

}

// If the action sent by the client is an update
else if($_POST['action'] === "update") {
    
    // Setup the default values of the response back to the client
    $response = array(
        "err" => 0,
        "message" => "Updated record on server.",
        "action" => "update",
        "has_new_id" => 0,
        "new_id" => $_POST['id'],
        "old_id" => $_POST['id'],
        "table" => $_POST['table'], 
        "id_col" => $_POST['id_col']);


    //See if there is a conflict
    if($server_conflict) {
        // (We'll need to check the timestamps, so load them into time objects)
     
        // Create time object for the server timestamp
        $server_timestamp = strtotime($server_record['timestamp']);

        // Create time object for the client timestamp
        $client_timestamp = strtotime($_POST['timestamp']);
      
    
        
        if($server_record['sync_action'] === "delete") {
            // Situation 1: server has 'delete' action, and it is more recent, conflict policy is to enact latest 
            //   action (server's delete wins)
            if(($server_timestamp > $client_timestamp) &&
                 $conflict_policies['update_vs_delete'] === LATEST_ACTION) {
                $response['message'] = "Ignoring update request, since server has a more recent conflcting" . 
                 " 'delete' action for this record and per conflict policy, the more recent server action " .
                 "will be enacted.";
                respond_success($response);
            }
            // Situation 2: server has 'delete' action but policy is ALWAYS_UPDATE or 
            //  Situation 3: server has 'delete', client timestamp newer
            else {
                // Remove the server's delete sync record so that it doesn't get deleted on client during
                //  server to client sync
                $sql = "DELETE FROM _dbs_sync_actions WHERE record_id=? AND table_name=?";
                $result = mysqli_prepared_query($mysql, $sql, "is", array($_POST['id'], $_POST['table']));
                if(!$result) {
                    respond_error("Error deleting sync record during delete/update conflict:" . $mysql->error,
                    $mysql->errno);
                }
                // No "respond_success" here because we want the code after the other else if's to execute so 
                //  that server data record is updated
            }
        }
        // Situation 4: server has 'update' action, and policy is to keep both updated records
        else if($server_record['sync_action'] === "update" && 
            $conflict_policies['update_vs_update'] === KEEP_BOTH) {
            
            // Insert the record from the client
            $result = insert_record($mysql);
            
            
            // Indicate to the client that a new record has been added with conflicting data
            $response['message'] = "Records on both client and server have been updated.  Per " .
             "conflict policy, keeping both records.  Client record has been inserted into server " .       
             "database with a new id.";
            $response['new_id'] = $result['new_id'];
            $response['has_new_id'] = 1;
            
            // Change the server's update record to an insert so that the client will get a copy
            $sql = "UPDATE _dbs_sync_actions SET sync_action='insert' WHERE record_id=? AND table_name=?";
            $result = mysqli_prepared_query($mysql, $sql, "is", array($_POST['id'], $_POST['table']));
            if(!$result) {
                respond_error("Error updating server record when trying to keep both records:" . $mysql->error,
                $mysql->errno);
            }
            else {
                respond_success($response);
            }
        }
        // Situation 5: server has 'update' action, it's newer and policy is latest action (server's update wins)
        else if($server_record['sync_action'] === "update" && 
            $conflict_policies['update_vs_update'] === LATEST_ACTION &&
            ($server_timestamp > $client_timestamp)) {
                // Ignore the client's record, just send back a response
                $response['message'] = "Records on both client and server have been update.  Per conflict " .
                    "policy, the most recent update (latest action) will be synced.  Ignoring the older " .
                    "client record.";
                respond_success($response);
            
        }
       
    }
    
    // All situations where the client record will be accepted by server:   
    // Situation 6: server has 'update' action, it's older and policy is latest action (client's update wins)
    // Situation 7: no conflict
    
    // Create the update sql statement
    $sql = "UPDATE " . $_POST['table'] . " SET ";
    
    $cur = 0;
    $datatypes = "";
    while(isset($_POST['data_columns'][$cur])) {
        //If this is the last column
        if($cur === sizeof($_POST['data_columns']) - 1) {
            $sql .= $_POST['data_columns'][$cur] . "=?";
        }
        else {
            $sql .= $_POST['data_columns'][$cur] . "=?,";
        }
        
        if(is_numeric($_POST['data_values'][$cur])) {
            $datatypes .= "i";
        }
        else {
            $datatypes .= "s";
        }
        $cur++;
    }
    
    $sql .=" WHERE " . $_POST['id_col'] . "=" . $_POST['id'];
    
    //echo "update sql: " . $sql;
    
    $result = mysqli_prepared_query($mysql, $sql, $datatypes, $_POST['data_values']);
    
    if(!$result) {
        respond_error("Error updating record on server: " . $mysql->error, $mysql->errno);
    
    } 
    
    // Delete the automatically inserted sync record - we don't want this created during sync, only if the
    //  developer actually inserts a record in their own code
    $sql = "DELETE FROM _dbs_sync_actions WHERE record_id=? AND table_name=?";
    
    $result = mysqli_prepared_query($mysql, $sql, "is", array($mysql->insert_id, $_POST['table']));
    
    if(!$result) {
        respond_error("Error deleting automatically generated sync record.");
    
    }
  
    if(!isset($response['message'])) {       
       $response['message'] = "No conflict or a conflict of no consequence occurred.";
    }
    respond_success($response);

}
else if ($_POST['action'] === "get_server_sync_data") {
    /* Called by client after client to server sync -- server will send all sync records and any necessary 
      data to client.
      
     Data sent by client:
     - id_col (id column title used in the data tables)
    
     Data array sent by server:
     -sync_records (array) 
        - id
        - record_id
        - sync_action
        - table_name
        - timestamp
        - data_columns (array)
        - data_values (array)
     */
    
    // Get the sync record table, sort by id#
    $sql = "SELECT * FROM _dbs_sync_actions ORDER BY id";  
    
    $sync_result = $mysql->query($sql);
    
    if(!$sync_result) {
        respond_error("Could not select from _dbs_sync_actions during server to client sync.",
            $mysql->error, $mysql->errno);
     
    }
    
    $response = array();
    
    // Create the array to be returned to the client
    for ($cur_sync = 0; $cur_sync < $sync_result->num_rows; $cur_sync++) {
        $sync_record = $sync_result->fetch_array();
        
        $response[$cur_sync]['id'] = $sync_record['id'];
        $response[$cur_sync]['table_name'] = $sync_record['table_name'];
        $response[$cur_sync]['record_id'] = $sync_record['record_id'];
        $response[$cur_sync]['sync_action'] = $sync_record['sync_action'];
        $response[$cur_sync]['timestamp'] = $sync_record['timestamp'];
        
        // We can skip getting data if it's a delete action
        if ($sync_record['sync_action'] === "delete") {
            continue;
        }
        
        // Get the data associated with this sync record
        $sql = "SELECT * FROM " . $sync_record['table_name'] . " WHERE " . $db['id_col'] . "=?";
        
        $data_result = mysqli_prepared_query($mysql, $sql, "i", array($sync_record['record_id']));
        
        //print_r($data_result);
        
        // Put the data into the response array
        $cur_field_index = 0;
        foreach ($data_result[0] as $field=>$value) {
            $response[$cur_sync]['data_columns'][$cur_field_index] = $field;
            $response[$cur_sync]['data_values'][$cur_field_index] = $value;
            $cur_field_index++;
        
        }
        
    }
    
    // Send the data to the client!
    respond_success($response);
}
else if ($_POST['action'] === "get_next_sync_record_and_data") {
    // DEPRECATED - DELETE WHEN NOT NEEDED ANYMORE
    // Called by client during server to client sync -- sends the next sync record & the associated data
    // One update is sent at a time
    // NEED TO CODE
    // Need to send to client: id of data record (id), sync_record_id, data_values & 
    //   data_columns (both are arrays), action, is_last_server_record (flag to indicate last record)
    
    // Defaults
    $response = array( 
        "id" => 0,
        "sync_record_id" => 0,
        "data_values" => array(),
        "data_columns" => array(),
        "action" => "",
        "is_last_server_record" => 0
    );
        
    
    
    // Get the sync record table, sort by id#
    $sql = "SELECT * FROM _dbs_sync_actions ORDER BY id";
    
    $result = $mysql->query($sql);
    
    if(!$result) {
        respond_error("Could not select from _dbs_sync_actions during server to client sync.",
            $mysql->error, $mysql->errno);
     
    }
    
    if($result->num_rows === 1) {
        $response['is_last_server_record'] = 1;
    }
    
    $sync_record = $result->fetch_array();
    
    // Get the data
    $sql = "SELECT * FROM " . $sync_record['table_name'] . " WHERE " . $_POST['id_col'] . "=?";
    
    $result = mysqli_prepared_query($mysql, $sql, "i", array($sync_record['record_id']));
    
    if(!$result) {
        respond_error("Could not select data from table " . $sync_record['table_name'] . " during server to " .
            "client sync: ", $mysql->error, $mysql->errno);
    }
    
    // Set the response values and send to client
    $response['data_values'] = array_values($result);
    $response['data_columns'] = array_keys($result);
    $response['action'] = $sync_record['sync_action'];
    $response['id'] = $sync_record['record_id'];
    $response['sync_record_id'] = $sync_record['id'];
    respond_success($response);
    
}
else if ($_POST['action'] === "delete_sync_record") {
    // NEED TO TEST
    // Tells the server that the client received the last action and successfully executed it, and that it can
    //  delete the corresponding sync record
    $sql = "DELETE FROM _dbs_sync_actions WHERE id=?";
    
    $result = mysqli_prepared_query($mysql, $sql, "i", array($_POST['sync_record_id']));
    
    if(!$result) {
        respond_error("Error deleting sync record from server: ", $mysql->error, $mysql->errno);
    
    }
    /*
    else {
        respond_success();
    }*/
}

else {
    respond_error("Error: _sync_action in post data array is not valid.", 1);
}


function respond_error($msg, $code) {
    echo json_encode(array("message" => $msg, "code" => $code));
    die();

}

function respond_success($response) {
    echo json_encode($response);
    die();

}


function open_database() {
    require("DB_Syncer_settings.php");
    
    $mysql_obj = new mysqli($db['hostname'], $db['username'], $db['password'], $_POST['remote_db']);
    
    if($mysql_obj->connect_errno) {
        
        /* FINISH THIS LATER (database doesn't exist handler)
        // Possibly the database doesn't exist yet
        $mysql = new mysqli($db['hostname'], $db['username'], $db['password']);
        
        
        if ($mysql->connect_errno) {*/
            die("Failed to connect to MySQL: (" . $mysq_objl->connect_errno . ") " . $mysql_obj->connect_error);
        /*}

        
        else {
            //Create the database
            $sql = "CREATE DATABASE IF NOT EXISTS " . $_POST['remote_db']
        }*/
    
    }
    
    
    else return $mysql_obj;

}

function insert_record($mysql) {
    // @TEST NEEDED (changed id conflict code - was incorrect - now makes new id client's max_id + 1;

    // Shortcut function to insert the record indicated by the post data  

    $id_conflict = FALSE;
    $more_client_records = FALSE;
    
    // Test to see if the id from the client data is already in the data (i.e. id conflict)
    $sql = "SELECT " . $_POST['id_col'] ." FROM " . $_POST['table'] . " WHERE " . $_POST['id_col'] . "=?";
    //echo "id exists sql: " . $sql;
    $result = mysqli_prepared_query($mysql, $sql, "i", array($_POST['id']));
    //DEBUG:
    //echo "id exists result: " . print_r($result, 1);
    
    if($result) {  // id conflict
        $id_conflict = TRUE; //Set a flag indicating that the server record will be different than client's
        
        // The new id should be the larger of the client's max_id + 1 or the server's max_id + 1 (i.e. auto
        //  increment value on server)
            
        // First, lookup the server's max_id
        $sql = "SELECT max(" . $_POST['id_col'] . ") AS server_max_id FROM " . $_POST['table'];
        $result = $mysql->query($sql);
        $result_data = $result->fetch_array();
        
        // If client max id is larger
        if($_POST['max_id'] > $result_data['server_max_id']) {
            $new_id = $_POST['max_id'] + 1;
            $more_client_records = TRUE;
        }
  
       
    }
    
    // Create the prepared statement parameters from the column and value data passed by client (device)
    $sql = "INSERT INTO " . $_POST['table'] . " (";
    $datatypes = "";
    for($cur = 0; $cur < sizeof($_POST['data_columns']); $cur++) {
                
        //If this is the id column AND there was a conflict 
        if($_POST['data_columns'][$cur] === $_POST['id_col'] && $id_conflict) {
            // If there are not more records on the client side  (num_server_records >= num_client_records)
            if (!$more_client_records) {
                // Skip putting in sql string (will allow auto increment of id to occur)
                continue;
            }
        }
       
        $sql .= $_POST['data_columns'][$cur] . ",";
        
    }
    
    // Remove the last comma
    $sql = substr($sql, 0, -1);
    
    $sql .= ") VALUES (";

    for($cur = 0; $cur < sizeof($_POST['data_values']); $cur++) {
  
        //If this is the id column and there was an id conflict
        if($_POST['data_columns'][$cur] === $_POST['id_col'] && $id_conflict) {
            
            // If the number of client records is greater than the number of server records
            if ($more_client_records) {            
                // Set the id explicitly as 1 more than the client's max id
                $data[$cur] = $_POST['max_id'] + 1;
            }
            else {
                continue;  // Need to skip adding an 'i' in the $datatypes array (auto increment will occur)
            }
        }
        else {
        
            // Copy the current data value to the $data array
            $data[$cur] = $_POST['data_values'][$cur];

        }

        // Add on a '?' for the prepared statement
        $sql .= "?,";
        
        if(is_numeric($data[$cur])) {
            $datatypes .= "i";
        }
        else {
            $datatypes .= "s";
        }
    }
    
    // Remove the last comma
    $sql = substr($sql, 0, -1);

    $sql .= ")";
    
   // Re-index the data_values array, since the id column may have been skipped
    $data = array_values($data);
        
    $result = mysqli_prepared_query($mysql, $sql, $datatypes, 
        $data);
    
    
    if(!$result) {
        return 0;  //Error
    }
    else {
        // Add some data to the result about id conflicts
        $result['has_new_id'] = $id_conflict;
        $result['new_id'] = $mysql->insert_id;
        $result['mysql_errno'] = $mysql->errno;
        $result['mysql_error'] = $mysql->error;
        return $result;
    }
    
  
 

}


function delete_record($mysql) {
    // Shortcut function to logically delete the record for table & id indicated by POST values
    require("DB_Syncer_settings.php");
   

    $sql = "UPDATE " . $_POST['table'] . " SET " . $db['logical_delete_field'] . "=1 WHERE " .  
     $_POST['id_col'] . "=?";
    
    //echo "delete sql: " . $sql;
    
    return mysqli_prepared_query($mysql, $sql, "i", array($_POST['id']));
 


}

 

?>
