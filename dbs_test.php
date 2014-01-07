<html>
<head><title>DB Syncer Test</title></head>
<body>
<form method="POST" action="dbs_test_listener.php">
ID: <input name="id" type="text" />
Value / Number of data items or actions: <input name="value" type="text" /><br />
Action:
<select name="action">
    <option value="insert">Insert</option>
    <option value="update">Update</option>
    <option value="delete">Delete</option>
    <option value="random_data">Generate Random Data</option>
    <option value="random_actions">Generate Random Actions</option>
</select>
<input type="submit" />

</form>

</body>


</html>
