<?php
session_start();
require 'db.php';

// Ensure only admins can pin/unpin records
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

// Validate the request parameters
if (isset($_GET['id'], $_GET['action']) && in_array($_GET['action'], ['pin', 'unpin'])) {
    $id = intval($_GET['id']);
    $isPinned = ($_GET['action'] === 'pin') ? 1 : 0;

    try {
        // Update the is_pinned status in the database
        $stmt = $conn->prepare("UPDATE records SET is_pinned = ? WHERE id = ?");
        $stmt->execute([$isPinned, $id]);

        // Redirect back to the dashboard
        header('Location: dashboard.php');
        exit();
    } catch (PDOException $e) {
        // Handle database errors (optional)
        echo "Error: " . $e->getMessage();
        exit();
    }
} else {
    // Redirect to dashboard if parameters are missing or invalid
    header('Location: dashboard.php');
    exit();
}
