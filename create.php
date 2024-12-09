<?php
session_start();
require 'db.php';

// Upload image function to ImgBB
function uploadToImgBB($imageFilePath)
{
    $apiKey = 'f821d9aabdc453475e600d2c66c19c3c';
    $url = 'https://api.imgbb.com/1/upload';

    // Prepare the image data
    $imageData = base64_encode(file_get_contents($imageFilePath));

    // Send the API request
    $data = [
        'key' => $apiKey,
        'image' => $imageData
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    // Parse the response
    $responseDecoded = json_decode($response, true);
    if (isset($responseDecoded['data']['url'])) {
        return $responseDecoded['data']['url']; // Return the image URL
    }

    return null; // Return null if upload failed
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure user is logged in
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }

    $userId = $_SESSION['user_id'];

    // Fetch user role from the database
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRole = $stmt->fetchColumn();

    // Determine if the user is an admin
    $isAdmin = ($userRole === 'admin') ? 1 : 0;

    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $imageUrl = null;

    // Check if an image file is uploaded
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        // Define allowed types and max file size
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxFileSize = 2 * 1024 * 1024; // 2MB limit

        // Get the file type
        $fileType = mime_content_type($_FILES['image']['tmp_name']);

        // Validate file type
        if (!in_array($fileType, $allowedTypes)) {
            die('Error: Only JPEG, PNG, and GIF files are allowed.');
        }

        // Validate file size
        if ($_FILES['image']['size'] > $maxFileSize) {
            die('Error: File size exceeds the 2MB limit.');
        }

        // Upload to ImgBB
        $imageUrl = uploadToImgBB($_FILES['image']['tmp_name']);
        if ($imageUrl === null) {
            die('Error: Failed to upload image.');
        }
    }

    // Save the new record with the image URL and admin flag
    $stmt = $conn->prepare("
        INSERT INTO records (name, description, parent_id, created_at, image_path, is_admin)
        VALUES (?, ?, NULL, NOW(), ?, ?)
    ");
    $stmt->execute([$name, $description, $imageUrl, $isAdmin]);

    header('Location: dashboard.php');
    exit();
}

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anarchat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-5">
        <h1>Create a New Record</h1>
        <form method="POST" action="create.php" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="name" class="form-label">Record Name</label>
                <input type="text" class="form-control" id="name" name="name" required>
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
            </div>
            <div class="mb-3">
                <label for="image" class="form-label">Upload Image (Max: 2MB)</label>
                <input type="file" class="form-control" id="image" name="image" accept="image/*">
            </div>
            <button type="submit" class="btn btn-primary">Create Record</button>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>
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
</body>

</html>
