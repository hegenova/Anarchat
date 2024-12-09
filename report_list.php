<?php
session_start();
require 'db.php';

// Check if the user is an admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Access denied.");
}

// Fetch reported posts with category counts and record details
$stmt = $conn->prepare("
    SELECT 
        records.id AS record_id,
        records.name AS record_name,
        records.description AS record_description,
        COUNT(CASE WHEN reports.category = 'breaking_the_law' THEN 1 END) AS breaking_the_law_count,
        COUNT(CASE WHEN reports.category = 'trolling' THEN 1 END) AS trolling_count
    FROM reports
    JOIN records ON reports.record_id = records.id
    GROUP BY records.id, records.name, records.description
");
$stmt->execute();

// Fetch the results into $reports
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anarchat</title>
    <link rel='stylesheet' type='text/css' href='dashboard.css'>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom CSS (optional) -->
    <style>
        body {
            background-color: #f8f9fa;
        }

        .container {
            margin-top: 50px;
        }

        .table {
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
        }

        .table th {
            background-color: #343a40;
            color: #ffffff;
            text-align: center;
        }

        .table td {
            text-align: center;
        }

        .heading {
            text-align: center;
            margin-bottom: 30px;
            color: #343a40;
        }

        .btn-link {
            padding: 0;
            border: none;
            background: none;
            color: #0d6efd;
            text-decoration: underline;
            cursor: pointer;
        }

        .btn-link:hover {
            text-decoration: none;
        }

        .truncate {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>

<body>

    <div class="container">
        <h1 class="heading">Reported Posts</h1>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
            <button id="darkModeToggle" class="btn btn-secondary">Dark Mode</button>
        </div>
        <!-- Display table with Bootstrap classes -->
        <div class="table-responsive">
            <table class="table table-bordered table-hover shadow-sm">
                <thead>
                    <tr>
                        <th>Post ID</th>
                        <th>Post Name</th>
                        <th>Post Description</th>
                        <th>Breaking the Law</th>
                        <th>Trolling</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($reports)): ?>
                        <?php foreach ($reports as $report): ?>
                            <tr>
                                <td>
                                    <!-- Make the Record ID clickable -->
                                    <a href="record.php?id=<?php echo htmlspecialchars($report['record_id']); ?>"
                                        class="btn btn-outline-primary btn-sm">
                                        <?php echo htmlspecialchars($report['record_id']); ?>
                                    </a>
                                </td>
                                <td class="truncate"><?php echo htmlspecialchars($report['record_name']); ?></td>
                                <td class="truncate"><?php echo htmlspecialchars($report['record_description']); ?></td>
                                <td><?php echo htmlspecialchars($report['breaking_the_law_count']); ?></td>
                                <td><?php echo htmlspecialchars($report['trolling_count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">No reports found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script>
        // Get the toggle button
        const toggleButton = document.getElementById('darkModeToggle');

        // Check the saved theme preference
        const currentTheme = localStorage.getItem('theme');
        if (currentTheme === 'dark') {
            document.body.classList.add('dark-mode');
        }

        // Toggle dark mode and save preference
        toggleButton.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            const isDarkMode = document.body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDarkMode ? 'dark' : 'light');
            applyTwitterDarkMode();
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>