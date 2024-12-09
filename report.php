<?php
session_start();
require 'db.php';

// Ensure the user is logged in
if (!isset($_SESSION['user_id'])) {
    die("You must be logged in to report a post.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_id'], $_POST['category'])) {
    $userId = $_SESSION['user_id'];
    $recordId = intval($_POST['record_id']);
    $category = $_POST['category'];

    // Validate the category
    $validCategories = ['breaking_the_law', 'trolling'];
    if (!in_array($category, $validCategories)) {
        die("Invalid report category.");
    }

    // Check if the user has already reported this post
    $stmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ? AND record_id = ?");
    $stmt->execute([$userId, $recordId]);
    if ($stmt->fetchColumn() > 0) {
        die("You have already reported this post.");
    }

    // Insert the report
    $stmt = $conn->prepare("INSERT INTO reports (user_id, record_id, category) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $recordId, $category]);

    echo "<script>alert('Post reported successfully.'); window.location.href = 'dashboard.php';</script>";
    exit();
}
?>
