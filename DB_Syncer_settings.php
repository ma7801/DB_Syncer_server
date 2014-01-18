<?php
/*************************************
/*   Settings for DB_Syncer
/*************************************/

if(!defined("CONSTANTS_DEFINED")) {
    define("CONSTANTS_DEFINED", 1);
    define('ALWAYS_UPDATE', 1);
    define('LATEST_ACTION', 2);
    define('KEEP_BOTH', 3);
}

/* Database settings */

// Local database host
$db['hostname'] = "localhost";

// Local mysql username
$db['username'] = "root";

// Local mysql password
$db['password'] = "cmskt&@BSUkk";

/*
// Server sync actions table
$db['sync_table'] = "_dbs_sync_actions";
*/


// The logical delete field name
$db['logical_delete_field'] = "_is_deleted";

// The id column field name used in ALL data tables used by DB Syncer
$db['id_col'] = "id";

/*
// The timestamp field name
$db['timestamp_field'] = "_last_updated";
*/

/* Sync conflict resolution settings */

/* Conflict policies:

conflict_policies['update_vs_delete']:  Update and Delete on same record
   ALWAYS_UPDATE  = Always choose the update (never delete, even if delete is more recent)  (default)
   LATEST_ACTION = Take the action that has most recent timestamp
    
conflict_policies['update_vs_update']: Update on server, update on client
   LATEST_ACTION = Update to whichever is more recent 
   KEEP_BOTH = keep both, giving older one a new record id (default)
   
Why is the update_vs_delete policy ALWAYS_UPDATE as default?
- A user may update a record on the web (server), see the OLD record on the device (client) and delete it 
    (before a sync), thinking that the 'new' one from the web will download onto the client.
    
*/

$conflict_policies['update_vs_delete'] = ALWAYS_UPDATE;
$conflict_policies['update_vs_update'] = LATEST_ACTION;




?>
