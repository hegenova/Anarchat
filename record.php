<?php
require 'db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid record ID.");
}

$recordId = (int) $_GET['id'];

try {
    // Fetch the main record
    $recordStmt = $conn->prepare("SELECT * FROM records WHERE id = :id");
    $recordStmt->bindParam(':id', $recordId, PDO::PARAM_INT);
    $recordStmt->execute();
    $record = $recordStmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        die("Record not found.");
    }

    // Fetch replies recursively
    function fetchReplies($parentId)
    {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM records WHERE parent_id = :parent_id ORDER BY created_at ASC");
        $stmt->bindParam(':parent_id', $parentId, PDO::PARAM_INT);
        $stmt->execute();
        $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If there are replies, recursively fetch replies for each one
        foreach ($replies as &$reply) {
            // Recursively fetch replies to this reply
            $reply['replies'] = fetchReplies($reply['id']);
        }

        return $replies;
    }

    // Get all replies for the main record (if any)
    $replies = fetchReplies($recordId);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Embed links function to embed YouTube, Twitter, and Instagram links

function embedLinks($content)
{
    // Sanitize content first (only for parts not being embedded)
    $content = htmlspecialchars($content, ENT_NOQUOTES, 'UTF-8');

    // Undo escaping for <blockquote> to allow embeds
    $content = preg_replace(
        '/&lt;(\/?blockquote.*?)&gt;/',
        '<$1>',
        $content
    );

    // Embed YouTube links - updated regex to strip any query parameters
    $content = preg_replace_callback(
        '/https?:\/\/(?:www\.)?(?:youtube\.com\/(?:watch\?v=|v\/)|youtu\.be\/)([a-zA-Z0-9_-]+)/',
        function ($matches) {
            $videoId = $matches[1];
            return '<div class="youtube-embed"><iframe width="560" height="315" src="https://www.youtube.com/embed/' . $videoId . '" frameborder="0" allowfullscreen></iframe></div>';
        },
        $content
    );

    // Embed Twitter links (updated regex to work with both regular and short URLs)
    $content = preg_replace_callback(
        '/https?:\/\/(?:www\.)?(?:twitter\.com|x\.com)\/(?:#!\/)?(\w+)\/status(es)?\/(\d+)/',
        function ($matches) {
            $url = $matches[0];
            return '<blockquote class="twitter-tweet"><a href="' . $url . '"></a></blockquote>';
        },
        $content
    );

    // Embed Instagram links (updated regex to handle Instagram URLs correctly)
    $content = preg_replace(
        '/https?:\/\/(?:www\.)?instagram\.com\/p\/([a-zA-Z0-9_-]+)(?:\/\?.*)?/',
        '<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/p/$1/" data-instgrm-version="14"></blockquote>',
        $content
    );

    // Add Twitter script once at the end of the content
    if (strpos($content, '<blockquote class="twitter-tweet"') !== false) {
        $content .= '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';
    }

    return $content;
}


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Details</title>
    <link rel='stylesheet' type='text/css' href='dashboard.css'>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-5">
        <h1 class="mb-4">Record Details</h1>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
            <button id="darkModeToggle" class="btn btn-secondary">Dark Mode</button>
        </div>
        <!-- Main Record -->
        <div class="card mb-4">
            <div class="card-body">
                <?php
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

                if (!empty($record['image_path'])) {
                    echo '<div class="mt-2">';
                    echo '<img src="' . htmlspecialchars($record['image_path']) . '" alt="Uploaded Image" style="max-width: 100%; height: auto; border: 1px solid #ddd; padding: 5px;">';
                    echo '</div>';
                }

                ?>
                <h5 class="card-title"><?php echo htmlspecialchars($record['name']); ?></h5>
                <!-- Apply embedLinks function to the description before outputting it -->
                <p class="card-text"><?php echo nl2br(embedLinks($record['description'])); ?></p>
                <p class="text-dark small">Created at: <?php echo htmlspecialchars($record['created_at']); ?></p>
                <?php
                // Reply
                if (!$record['is_locked']) {
                    echo '<a href="#" class="btn btn-sm btn-primary reply-btn" data-id="' . $record['id'] . '">Reply</a>';
                }
                ?>
            </div>
        </div>

        <!-- Replies Section -->
        <h2>Replies</h2>
        <?php if (!empty($replies)): ?>
            <?php
            // Function to recursively render replies
            function renderReplies($replies)
            {
                foreach ($replies as $reply) {
                    echo '<div class="card mb-3 ms-4">';
                    echo '<div class="card-body">';

                    // Add an admin tag if the record is flagged as admin
                    if ($reply['is_admin'] == 1) {
                        echo '<span class="badge bg-dblue" style="margin-bottom: 10px;">🛠️</span>';
                    }
                    echo '<h6 class="card-subtitle mb-2 text-dark">Reply ID: ' . htmlspecialchars($reply['id']) . '</h6>';
                    // Apply embedLinks function to the reply description before outputting it
                    echo '<p class="card-text">' . nl2br(embedLinks($reply['description'])) . '</p>';
                    echo '<p class="text-dark small">Created at: ' . htmlspecialchars($reply['created_at']) . '</p>';

                    // Reply
                    if (!$reply['is_locked']) {
                        echo '<a href="#" class="btn btn-sm btn-primary reply-btn" style="margin-bottom: 10px;" data-id="' . $reply['id'] . '">Reply</a>';
                    }

                    if (!empty($reply['image_path'])) {
                        echo '<div class="mt-2">';
                        echo '<img src="' . htmlspecialchars($reply['image_path']) . '" alt="Uploaded Image" style="max-width: 100%; height: auto; border: 1px solid #ddd; padding: 5px;">';
                        echo '</div>';
                    }

                    // Recursively render replies to this reply
                    if (!empty($reply['replies'])) {
                        echo '<h6>Replies to this reply:</h6>';
                        renderReplies($reply['replies']);
                    }

                    echo '</div>';
                    echo '</div>';
                }
            }

            // Render all replies to the main record
            renderReplies($replies);
            ?>
        <?php else: ?>
            <p>No replies yet.</p>
        <?php endif; ?>
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
    <script>
        document.querySelectorAll('.reply-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                // Get the parent ID from the button
                const parentId = this.getAttribute('data-id');
                // Set the hidden input value in the reply modal
                document.getElementById('replyParentId').value = parentId;

                // Open the modal using Bootstrap's JavaScript API
                const replyModal = new bootstrap.Modal(document.getElementById('replyModal'));
                replyModal.show();
            });
        });

        // Form submission handling
        document.getElementById('replyForm').addEventListener('submit', function (e) {
            e.preventDefault(); // Prevent the default form submission
            const formData = new FormData(this);

            // Make an AJAX POST request to send the reply data
            fetch('replyHandler.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // On success, redirect to record.php with the record ID
                        window.location.href = `record.php?id=${data.recordId}`;
                    } else {
                        // Handle errors (e.g., show an error message)
                        alert(data.message || 'Failed to submit the reply.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while submitting the reply.');
                });
        });
    </script>

    <script async defer src='https://www.instagram.com/embed.js'></script>
    <script async src='https://platform.twitter.com/widgets.js' charset='utf-8'></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>