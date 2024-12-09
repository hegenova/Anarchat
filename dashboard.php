<?php
session_start();
require 'db.php';


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}


// Debugger console
function debug_to_console($data)
{
    $output = $data;
    if (is_array($output))
        $output = implode(',', $output);

    echo "<script>console.log('Debug Objects: " . $output . "' );</script>";
}


// Lock/unlock functionality
if (isset($_GET['id'], $_GET['action']) && in_array($_GET['action'], ['lock', 'unlock'])) {
    $id = intval($_GET['id']);
    $isLocked = ($_GET['action'] === 'lock') ? 1 : 0;

    $stmt = $conn->prepare("UPDATE records SET is_locked = ? WHERE id = ?");
    $stmt->execute([$isLocked, $id]);

    header('Location: dashboard.php');
    exit();
}


if (isset($_GET['archive'])) {
    $stmt = $conn->prepare("UPDATE records SET is_archived = 1 WHERE id NOT IN (
        SELECT id FROM (
            SELECT id FROM records ORDER BY created_at DESC LIMIT 25
        ) AS recent
    )");
    $stmt->execute();

    header('Location: dashboard.php');
    exit();
}

// Check user role
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if (isset($_GET['id'], $_GET['action']) && in_array($_GET['action'], ['pin', 'unpin'])) {
    $id = intval($_GET['id']);
    $isPinned = ($_GET['action'] === 'pin') ? 1 : 0;

    $stmt = $conn->prepare("UPDATE records SET is_pinned = ? WHERE id = ?");
    $stmt->execute([$isPinned, $id]);

    header('Location: dashboard.php');
    exit();
}

function archiveOldRecords($conn)
{
    // Select IDs of the most recent 25 records
    $stmt = $conn->prepare("
        SELECT id 
        FROM records 
        WHERE is_archived = 0 
        ORDER BY created_at DESC 
        LIMIT 25
    ");
    $stmt->execute();
    $recentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($recentIds)) {
        // Archive all records not in the most recent 25
        $placeholders = str_repeat('?,', count($recentIds) - 1) . '?';
        $archiveStmt = $conn->prepare("
            UPDATE records 
            SET is_archived = 1 
            WHERE id NOT IN ($placeholders) 
                AND is_archived = 0 
                AND is_pinned != 1
        ");
        $archiveStmt->execute($recentIds);
    }
}

function uploadToImgBB($imageFilePath)
{
    $apiKey = 'f821d9aabdc453475e600d2c66c19c3c'; // Replace with your ImgBB API key
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

// Handle replies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'], $_POST['parent_id'])) {
    // Ensure the user is logged in
    if (!isset($_SESSION['user_id'])) {
        die("You must be logged in to reply.");
    }

    $userId = $_SESSION['user_id'];
    $parentId = intval($_POST['parent_id']);
    $replyContent = trim($_POST['reply']);
    $imageUrl = null;

    // Check if the thread is locked
    $stmt = $conn->prepare("SELECT is_locked FROM records WHERE id = ?");
    $stmt->execute([$parentId]);
    $row = $stmt->fetch();

    // Fetch user role from the database
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRole = $stmt->fetchColumn();

    // Determine if the user is an admin
    $isAdmin = ($userRole === 'admin') ? 1 : 0;

    // Prevent replies if the thread is locked and the user is not an admin
    if ($row && $row['is_locked'] == 1 && !$isAdmin) {
        die("Replies are not allowed for this thread as it is locked.");
    }

    // Check if an image file is uploaded
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imageFilePath = $_FILES['image']['tmp_name'];

        // Upload the image to ImgBB
        $imageUrl = uploadToImgBB($imageFilePath);

        if (!$imageUrl) {
            // Handle upload failure (optional)
            die("Image upload failed. Please try again.");
        }
    }

    // Save the reply with the image URL (if any) and admin flag
    $stmt = $conn->prepare("
        INSERT INTO records (name, description, parent_id, image_path, is_admin, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute(['Reply', $replyContent, $parentId, $imageUrl, $isAdmin]);

    header('Location: dashboard.php');
    exit();
}



// Handle sorting
$sortColumn = isset($_GET['sort_by']) && in_array($_GET['sort_by'], ['id', 'name', 'description']) ? $_GET['sort_by'] : 'id';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'asc' : 'desc';

// Fetch records with replies recursively
function fetchRecordsWithReplies($conn, $parentId = null, $sortColumn, $sortOrder, $limit = null, $searchTerm = null)
{
    $query = "
    SELECT id, name, description, parent_id, created_at, image_path, is_pinned , is_locked, is_admin
    FROM records 
    WHERE parent_id " . ($parentId ? "= ?" : "IS NULL") . " AND is_archived = 0
";

    // Add search condition if a term is provided
    if ($searchTerm) {
        $query .= " AND (name LIKE ? OR description LIKE ?)";
    }

    $query .= " ORDER BY is_pinned DESC, $sortColumn $sortOrder";

    if (is_null($parentId) && !is_null($limit)) {
        $query .= " LIMIT $limit";
    }

    $stmt = $conn->prepare($query);

    // Bind parameters
    $params = $parentId ? [$parentId] : [];
    if ($searchTerm) {
        $params[] = "%$searchTerm%";
        $params[] = "%$searchTerm%";
    }

    $stmt->execute($params);
    $records = $stmt->fetchAll();

    foreach ($records as &$record) {
        $record['replies'] = fetchRecordsWithReplies($conn, $record['id'], $sortColumn, $sortOrder, null, $searchTerm);
    }

    return $records;
}




// Get search term from the query string
$searchTerm = isset($_GET['search']) ? $_GET['search'] : null;

// Archive older records on page load
archiveOldRecords($conn);

// Fetch records with search term
$records = fetchRecordsWithReplies($conn, null, $sortColumn, $sortOrder, 25, $searchTerm);

if (empty($records)) {
    echo "<div class='alert alert-warning'>No records found matching your search.</div>";
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
    <link rel='stylesheet' type='text/css' href='dashboard.css'>
</head>

<body>
    <div class="container mt-5">
        <!-- Header -->
        <div class="row header">
            <div class="col-12 d-flex justify-content-between align-items-center">
                <h1>Dashboard</h1>
                <?php if ($isAdmin) {
                    echo ' <p style="margin-top: 15px;"> Admin Mode </p>';
                }
                ?>
                <div class="d-flex align-items-center">
                    <button id="toggleLayoutButton" class="btn btn-outline-secondary">Switch to Grid</button>
                    <button id="darkModeToggle" class="btn btn-secondary me-2 ms-2">Dark Mode</button>
                    <?php if ($isAdmin) {
                        echo '<a href="report_list.php" class="btn btn-secondary me-2">Report List</a>';
                    }
                    ?>
                    <a href="archive.php" class="btn btn-secondary me-2">View Archive</a>
                    <a href="logout.php" class="btn btn-danger rounded-pill px-3 py-1 shadow-sm">Logout</a>
                </div>
            </div>
        </div>

        <!-- Sort Options -->
        <div class="row mt-3">
            <div class="col-12">
                <form method="GET" action="dashboard.php">
                    <div class="d-flex align-items-center">
                        <label class="me-2">Sort By:</label>
                        <select class="form-select me-2" name="sort_by" onchange="this.form.submit()">
                            <option value="id" <?php echo $sortColumn === 'id' ? 'selected' : ''; ?>>ID</option>
                            <option value="name" <?php echo $sortColumn === 'name' ? 'selected' : ''; ?>>Name</option>
                            <option value="description" <?php echo $sortColumn === 'description' ? 'selected' : ''; ?>>
                                Description</option>
                        </select>
                        <select class="form-select me-2" name="order" onchange="this.form.submit()">
                            <option value="asc" <?php echo $sortOrder === 'asc' ? 'selected' : ''; ?>>Ascending</option>
                            <option value="desc" <?php echo $sortOrder === 'desc' ? 'selected' : ''; ?>>Descending
                            </option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <!-- Search Form -->
        <div class="row mt-3">
            <div class="col-12">
                <form method="GET" action="dashboard.php">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="Search records..."
                            value="<?php echo isset($_GET['search']) ? $_GET['search'] : ''; ?>">
                        <button class="btn btn-primary btn-sm" type="submit">Search</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add New Record Button -->
        <div class="row mt-3 new-record">
            <div class="col-12">
                <a href="create.php" class="btn btn-success">Add New Record</a>
            </div>
        </div>

        <!-- Records Table -->
        <div class="row mt-3">
            <div class="col-12">
                <div id="records">
                    <?php
                    function renderRecords($records, $isAdmin)
                    {

                        foreach ($records as $record) {



                            // Record content (will be toggled)
                            echo '<div class="record-content mt-2" id="content-' . $record['id'] . '">';

                            // Add a clickable link to redirect to `record.php`
                            echo '<a href="record.php?id=' . $record['id'] . '" class="text-decoration-none">';

                            if (!$isAdmin) {
                                echo '<div class="mt-3">';
                                echo '<button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#reportModal" data-record-id="' . htmlspecialchars($record['id']) . '">';
                                echo 'Report';
                                echo '</button>';
                                echo '</div>';
                            }

                            // Display the record ID at the top-right corner
                            echo '<div class="record-id text-end small text-dark" style=" top: 5%; right: 20%;">';
                            echo 'ID: ' . htmlspecialchars($record['id']);
                            echo '</div>';

                            // Add a 'bg-warning' class if the record is pinned
                            echo '<div class="record mb-3' . ($record['is_pinned'] ? ' bg-warning' : '') . '">';

                            // Add visual indicator if thread is locked
                            if ($record['is_locked']) {
                                echo '<span class="badge bg-danger">🔒</span>';
                            }

                            // Add an admin tag if the record is flagged as admin
                            if ($record['is_admin'] == 1) {
                                echo '<span class="badge bg-dblue ms-2">🛠️</span>';
                            }

                            // Add an admin tag if the record is flagged as admin
                            if ($record['is_pinned'] == 1) {
                                echo '<span class="badge bg-dark ms-2 me-2">📌</span>';
                            }
                            $name = " " . htmlspecialchars($record['name']);
                            $description = htmlspecialchars($record['description']);


                            if ($isAdmin) {
                                if ($record['is_pinned']) {
                                    echo ' <a href="pin.php?id=' . $record['id'] . '&action=unpin" class="btn btn-sm btn-secondary">Unpin</a>';
                                } else {
                                    echo ' <a href="pin.php?id=' . $record['id'] . '&action=pin" class="btn btn-sm btn-primary">Pin</a>';
                                }
                            }

                            if ($isAdmin) {
                                if ($record['is_locked']) {
                                    echo ' <a href="dashboard.php?id=' . $record['id'] . '&action=unlock" class="btn btn-sm me-2 btn-secondary">Unlock</a>';
                                } else {
                                    echo ' <a href="dashboard.php?id=' . $record['id'] . '&action=lock" class="btn btn-sm me-2 btn-danger">Lock</a>';
                                }
                            }

                            if (!empty($record['image_path'])) {
                                echo '<div class="mt-2">';
                                echo '<img src="' . htmlspecialchars($record['image_path']) . '" alt="Uploaded Image" style="max-width: 100%; height: auto; border: 1px solid #ddd; padding: 5px;">';
                                echo '</div>';
                            }

                            // Get name and description
                            $name = htmlspecialchars($record['name']);
                            $description = htmlspecialchars($record['description']);

                            // Regex for Instagram URLs
                            $instagramRegex = '/https?:\/\/(?:www\.)?instagram\.com\/p\/([a-zA-Z0-9_-]+)/';

                            // Check for Instagram links in the name and replace with embed iframe
                            if (preg_match($instagramRegex, $name, $matches)) {
                                $postId = $matches[1];
                                $name = '<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/p/' . htmlspecialchars($postId) . '/" data-instgrm-version="14"></blockquote>';
                            }

                            // Check for Instagram links in the description and replace with embed iframe
                            if (preg_match($instagramRegex, $description, $matches)) {
                                $postId = $matches[1];
                                $description = '<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/p/' . htmlspecialchars($postId) . '/" data-instgrm-version="14"></blockquote>';
                            }

                            // Regex to detect YouTube links
                            $youtubeRegex = '/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/';

                            // Check for YouTube links in the name and replace them with embed iframe
                            if (preg_match($youtubeRegex, $name, $matches)) {
                                $videoId = $matches[1];
                                $name = '<div class="youtube-embed"><iframe width="560" height="315" src="https://www.youtube.com/embed/' . htmlspecialchars($videoId) . '" frameborder="0" allowfullscreen></iframe></div>';
                            }

                            // Check for YouTube links in the description and replace them with embed iframe
                            if (preg_match($youtubeRegex, $description, $matches)) {
                                $videoId = $matches[1];
                                $description = '<div class="youtube-embed"><iframe width="560" height="315" src="https://www.youtube.com/embed/' . htmlspecialchars($videoId) . '" frameborder="0" allowfullscreen></iframe></div>';
                            }

                            // Regex to detect Twitter links
                            $twitterRegex = '/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/(?:#!\/)?(\w+)\/status(es)?\/(\d+)/';

                            // Automatically embed Twitter links in the name
                            if (preg_match($twitterRegex, $name, $matches)) {
                                $twitterURL = $matches[0];
                                $name = '<blockquote class="twitter-tweet"><a href="' . htmlspecialchars($twitterURL) . '"></a></blockquote>';
                            }

                            // Automatically embed Twitter links in the description
                            if (preg_match($twitterRegex, $description, $matches)) {
                                $twitterURL = $matches[0];
                                $description = '<blockquote class="twitter-tweet"><a href="' . htmlspecialchars($twitterURL) . '"></a></blockquote>';
                            }

                            // Render the transformed content
                            echo '<div style="margin-top:5px;"></div>';
                            echo "<strong>$name</strong>";
                            echo "<div>$description</div>";

                            // Display other record details
                            if (!empty($record['image_path'])) {
                                echo '<div>';
                                echo '<img src="' . htmlspecialchars($record['image_path']) . '" alt="Uploaded Image" style="max-width: 100%; height: auto; border: 1px solid #ddd; padding: 5px;">';
                                echo '</div>';
                            }


                            echo '<div class="text-dark small ">Created on: ' . htmlspecialchars($record['created_at']) . '</div>';
                            // Hidden button placeholder
                            echo '<button class="btn btn-sm btn-warning record-hidden-btn d-none" id="record-hidden-' . $record['id'] . '" data-record-id="' . $record['id'] . '">Record Hidden</button>';

                            echo '</div>'; // End of record-container
                            echo '<div class="actions mt-2">';
                            if (!$record['is_locked']) {
                                echo '<a href="#" class="btn btn-sm btn-primary reply-btn" data-id="' . $record['id'] . '">Reply</a>';
                            }
                            if ($isAdmin) {
                                echo ' <a href="edit.php?id=' . $record['id'] . '" class="btn btn-sm btn-warning">Edit</a>';
                                echo ' <a href="delete.php?id=' . $record['id'] . '" class="btn btn-sm btn-danger">Delete</a>';
                            }
                            // Replies section
                            if (!empty($record['replies'])) {
                                echo '<button class="btn btn-outline-success btn-sm read-more-btn ms-1" data-record-id="' . $record['id'] . '">';
                                echo '<i class="bi bi-chevron-down"></i> Read More';
                                echo '</button>';
                                echo '<div class="replies mt-3 ps-4 border-start d-none" id="replies-' . $record['id'] . '">';
                                renderRecords($record['replies'], $isAdmin); // Recursive call for replies
                                echo '</div>';
                            }

                            echo '</div>';

                            // Render replies recursively
                            // if (!empty($record['replies'])) {
                            //     echo '<div class="replies mt-3 ps-4 border-start">';
                            //     renderRecords($record['replies'], $isAdmin);
                            //     echo '</div>';
                            // }
                    
                            echo '</div>';
                        }
                    }
                    renderRecords($records, $isAdmin);
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Reply Modal -->
    <div class="modal fade" id="replyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="dashboard.php" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Reply</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <textarea class="form-control" name="reply" rows="3" placeholder="Write your reply here..."
                            required></textarea>
                        <input type="file" name="image" class="form-control mt-3" accept="image/*">
                        <input type="hidden" name="parent_id" id="replyParentId">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Reply</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Report Modal -->
    <div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="reportModalLabel">Report Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="reportForm">
                        <input type="hidden" name="record_id" id="reportPostId">
                        <label for="reportCategory" class="form-label">Reason:</label>
                        <select class="form-select" name="category" id="reportCategory" required>
                            <option value="breaking_the_law">Breaking the Law</option>
                            <option value="trolling">Trolling</option>
                        </select>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="submitReport">Submit</button>
                </div>
            </div>
        </div>
    </div>



    <!-- Bootstrap JS and dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggleLayoutButton = document.getElementById('toggleLayoutButton');
            const recordsContainer = document.getElementById('records');

            toggleLayoutButton.addEventListener('click', () => {
                // Toggle the class for horizontal layout
                recordsContainer.classList.toggle('horizontal');

                // Update the button text
                if (recordsContainer.classList.contains('horizontal')) {
                    toggleLayoutButton.textContent = 'Switch to List View';
                } else {
                    toggleLayoutButton.textContent = 'Switch to Grid View';
                }
            });
        });
    </script>

    <script>
        // Reply button click handler
        document.querySelectorAll('.reply-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const parentId = this.getAttribute('data-id');
                document.getElementById('replyParentId').value = parentId;
                const replyModal = new bootstrap.Modal(document.getElementById('replyModal'));
                replyModal.show();
            });
        });
    </script>
    <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>
    <script>
        function applyTwitterDarkMode() {
            // Check if dark mode is enabled
            const isDarkMode = document.body.classList.contains('dark-mode');

            // Find all Twitter embeds
            const twitterEmbeds = document.querySelectorAll('.twitter-tweet');

            twitterEmbeds.forEach(embed => {
                const iframe = embed.querySelector('iframe');
                if (iframe) {
                    const src = iframe.src;
                    // Adjust the theme in the embed URL
                    const newSrc = isDarkMode
                        ? src.replace(/theme=light/g, 'theme=dark')
                        : src.replace(/theme=dark/g, 'theme=light');
                    if (newSrc !== src) {
                        iframe.src = newSrc;
                    }
                }
            });

            // Reload Twitter embeds
            if (window.twttr && twttr.widgets) {
                twttr.widgets.load();
            }
        }

        // Call this after dynamically adding any new Twitter links
        //reloadTwitterEmbeds();
    </script>
    <script async src="https://www.instagram.com/embed.js"></script>
    <script>
        // Reload Instagram embeds dynamically after adding new links
        function reloadInstagramEmbeds() {
            if (window.instgrm && instgrm.Embeds) {
                instgrm.Embeds.process();
            }
        }
        reloadInstagramEmbeds();
    </script>
    <script>
        // Dark Mode Handler
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
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Attach click event listeners to all "Read More" buttons
            document.querySelectorAll('.read-more-btn').forEach(function (button) {
                button.addEventListener('click', function () {
                    const recordId = this.getAttribute('data-record-id');
                    const repliesDiv = document.getElementById('replies-' + recordId);

                    // Toggle visibility of replies
                    if (repliesDiv.classList.contains('d-none')) {
                        repliesDiv.classList.remove('d-none'); // Show replies
                        this.textContent = 'Read Less';
                    } else {
                        repliesDiv.classList.add('d-none'); // Hide replies
                        this.textContent = 'Read More';
                    }
                });
            });
        });
    </script>
    <script>
        // Handling Report modal to report.php
        document.addEventListener('DOMContentLoaded', function () {
            const reportModal = document.getElementById('reportModal');

            // Populate modal with record ID
            reportModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget; // Button that triggered the modal
                const recordId = button.getAttribute('data-record-id'); // Extract record ID
                document.getElementById('reportPostId').value = recordId; // Set record ID in hidden input
            });

            // Handle AJAX form submission
            document.getElementById('submitReport').addEventListener('click', function () {
                const recordId = document.getElementById('reportPostId').value;
                const category = document.getElementById('reportCategory').value;

                if (!recordId || !category) {
                    alert('Please select a reason for reporting.');
                    return;
                }

                const formData = new FormData();
                formData.append('record_id', recordId);
                formData.append('category', category);

                fetch('report.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.text())
                    .then(data => {
                        alert(data); // Display response from server
                        const modalInstance = bootstrap.Modal.getInstance(reportModal);
                        modalInstance.hide(); // Hide modal
                        location.reload(); // Optionally refresh the page
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred. Please try again.');
                    });
            });
        });
    </script>








    <footer class="bg-light text-center text-lg-start mt-5 py-3 border-top no-dark-mode">
        <div class="container text-center">
            <div class="row">
                <div class="col-md-6 text-md-start mb-3 mb-md-0">
                    <span class="text-muted">&copy; <?php echo date("Y"); ?>Kuliah Pemrograman Web Jurusan Teknik
                        Informatika ITS (2023). Dosen: Imam Kuswardayan, S.Kom, M.T. Burhanudin Rifa. All rights
                        reserved.</span>
                </div>
            </div>
        </div>
    </footer>
</body>

</html>