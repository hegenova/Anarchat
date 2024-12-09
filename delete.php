<?php
// Include the database connection
require 'db.php';

session_start();
if ($_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

// Check if the record ID is provided via GET request
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $record_id = $_GET['id'];

    // Check if the record exists
    $stmt = $conn->prepare("SELECT * FROM records WHERE id = ?");
    $stmt->execute([$record_id]);
    $record = $stmt->fetch();

    if ($record) {
        // Process the deletion if the record exists
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Prepare the delete query
            $delete_stmt = $conn->prepare("DELETE FROM records WHERE id = ?");
            $delete_stmt->execute([$record_id]);

            // Check if the delete was successful
            if ($delete_stmt->rowCount() > 0) {
                $success = "Record deleted successfully.";
                header("Location: dashboard.php"); // Redirect back to the main page after deletion
                exit;
            } else {
                $error = "Error: Unable to delete the record.";
            }
        }
    } else {
        $error = "Record not found.";
    }
} else {
    $error = "No record ID specified.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anarchat</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h2 class="text-center">Delete Record</h2>

        <?php if (isset($success)): ?>
            <div class="alert alert-success" role="alert">
                <?php echo $success; ?>
                <a href="dashboard.php" class="btn btn-link">Go back to the records list</a>
            </div>
        <?php elseif (isset($error)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo $error; ?>
                <a href="dashboard.php" class="btn btn-link">Go back to the records list</a>
            </div>
        <?php else: ?>
            <div class="alert alert-warning" role="alert">
                Are you sure you want to delete the record: <strong><?php echo $record['name']; ?></strong>?
            </div>

            <!-- Confirm Deletion Form -->
            <form method="POST" action="delete.php?id=<?php echo $record['id']; ?>">
                <button type="submit" class="btn btn-danger">Yes, Delete</button>
                <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
            </form>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS and dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>
</body>
</html>
