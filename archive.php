<?php
require_once 'db.php';

// Get the sort_by and sort_order parameters from the request
$sortBy = $_GET['sort_by'] ?? 'created_at'; // Default sort by created_at
$sortOrder = $_GET['sort_order'] ?? 'DESC'; // Default sort order DESC

// Validate the sort_by parameter to prevent SQL injection
$allowedSortColumns = ['id', 'name', 'description', 'created_at'];
if (!in_array($sortBy, $allowedSortColumns)) {
    $sortBy = 'created_at';
}

// Validate the sort_order parameter
$allowedSortOrders = ['ASC', 'DESC'];
if (!in_array($sortOrder, $allowedSortOrders)) {
    $sortOrder = 'DESC';
}

// Get the search term from the request
$searchTerm = $_GET['search'] ?? '';

// Prepare the query to fetch archived records with optional search
$query = "
    SELECT id, name, description, created_at, image_path 
    FROM records 
    WHERE is_archived = 1
";

if (!empty($searchTerm)) {
    $query .= " AND (name LIKE :search OR description LIKE :search)";
}

$query .= " ORDER BY $sortBy $sortOrder";

$stmt = $conn->prepare($query);

// Bind the search term parameter 
if (!empty($searchTerm)) {
    $stmt->bindValue(':search', '%' . $searchTerm . '%', PDO::PARAM_STR);
}

$stmt->execute();
$archivedRecords = $stmt->fetchAll();

/**
 * Function to generate embed code based on a given URL.
 * It removes the URL from the text if an embed is generated.
 */
function generateEmbed(&$text)
{
    $youtubeRegex = '/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)(?:\?.*)?/';
    $twitterRegex = '/https?:\/\/(?:www\.)?(?:twitter|x)\.com\/(?:#!\/)?(\w+)\/status(es)?\/(\d+)(?:\?.*)?/';
    $instagramRegex = '/https?:\/\/(?:www\.)?instagram\.com\/p\/([a-zA-Z0-9_-]+)(?:\/\?.*)?/';

    // YouTube Embed
    if (preg_match($youtubeRegex, $text, $matches)) {
        $youtubeId = $matches[1];
        $text = preg_replace($youtubeRegex, '', $text); // Remove link from text
        return "<iframe width='560' height='315' src='https://www.youtube.com/embed/$youtubeId' frameborder='0' allowfullscreen></iframe>";
    }

    // Twitter/X Embed
    if (preg_match($twitterRegex, $text, $matches)) {
        $twitterUrl = $matches[0]; // Full URL for the tweet
        $text = preg_replace($twitterRegex, '', $text); // Remove link from text
        return "<div class='twitter-embed'>
                    <blockquote class='twitter-tweet'>
                        <a href='$twitterUrl'></a>
                    </blockquote>
                    <script async src='https://platform.twitter.com/widgets.js' charset='utf-8'></script>
                </div>";
    }

    // Instagram Embed
    if (preg_match($instagramRegex, $text, $matches)) {
        $instagramUrl = $matches[0]; // Full URL for the Instagram post
        $text = preg_replace($instagramRegex, '', $text); // Remove link from text
        return "<blockquote class='instagram-media' data-instgrm-permalink='$instagramUrl' data-instgrm-version='14'></blockquote>
                <script async defer src='https://www.instagram.com/embed.js'></script>";
    }

    return $text; // Return the cleaned text if no embeds matched
}





/**
 * Function to fetch replies for a record.
 */
function fetchReplies($conn, $parentId)
{
    $stmt = $conn->prepare("SELECT id, name, description, created_at FROM records WHERE parent_id = :parentId ORDER BY created_at ASC");
    $stmt->bindValue(':parentId', $parentId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Recursive function to render records and their replies.
 */
function renderRecords($records, $conn)
{
    foreach ($records as $record) {
        echo '<div class="mb-3 border p-3 rounded">';
        echo '<strong>' . htmlspecialchars($record['name']) . '</strong>';

        $description = $record['description'];
        $embed = generateEmbed($description);

        echo '<p>' . htmlspecialchars(trim($description)) . '</p>';

        if (!empty($embed)) {
            echo '<div class="embed-container">' . $embed . '</div>';
        }

        echo '<div class="text-dark small">Created on: ' . htmlspecialchars($record['created_at']) . '</div>';

        // Fetch replies for the current record
        $replies = fetchReplies($conn, $record['id']);

        if (!empty($replies)) {
            echo '<button class="btn btn-outline-primary btn-sm read-more-btn ms-2" data-record-id="' . $record['id'] . '">';
            echo '<i class="bi bi-chevron-down"></i> Read More';
            echo '</button>';
            echo '<div class="replies mt-3 ps-4 border-start d-none" id="replies-' . $record['id'] . '">';
            renderRecords($replies, $conn);
            echo '</div>';
        }

        echo '</div>';
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anarchat</title>
    <link rel='stylesheet' type='text/css' href='dashboard.css'>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .replies.d-none {
            display: none;
        }
    </style>
</head>

<body>
    <div class="container mt-5">
        <h1>Archived Records</h1>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
            <button id="toggleLayoutButton" class="btn btn-secondary">Switch to Grid View</button>
            <button id="darkModeToggle" class="btn btn-secondary">Dark Mode</button>
        </div>


        <form method="GET" action="archive.php" class="mb-4">
            <div class="input-group">
                <input type="text" name="search" class="form-control" placeholder="Search archived records..."
                    value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                <select name="sort_by" class="form-select">
                    <option value="id" <?php echo ($_GET['sort_by'] ?? '') === 'id' ? 'selected' : ''; ?>>Sort by ID
                    </option>
                    <option value="name" <?php echo ($_GET['sort_by'] ?? '') === 'name' ? 'selected' : ''; ?>>Sort by Name
                    </option>
                    <option value="description" <?php echo ($_GET['sort_by'] ?? '') === 'description' ? 'selected' : ''; ?>>Sort by Description</option>
                </select>
                <select name="sort_order" class="form-select">
                    <option value="ASC" <?php echo ($_GET['sort_order'] ?? '') === 'ASC' ? 'selected' : ''; ?>>Ascending
                    </option>
                    <option value="DESC" <?php echo ($_GET['sort_order'] ?? '') === 'DESC' ? 'selected' : ''; ?>>
                        Descending</option>
                </select>
                <button class="btn btn-primary" type="submit">Search</button>
            </div>
        </form>

        <div class="mt-3" id="records">
            <?php if (empty($archivedRecords)): ?>
                <p>No archived records found.</p>
            <?php else: ?>
                <?php renderRecords($archivedRecords, $conn); ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const readMoreButtons = document.querySelectorAll('.read-more-btn');

            readMoreButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const recordId = button.getAttribute('data-record-id');
                    const repliesContainer = document.getElementById(`replies-${recordId}`);
                    const icon = button.querySelector('i');

                    // Toggle visibility of replies
                    repliesContainer.classList.toggle('d-none');

                    // Toggle button text and icon
                    if (repliesContainer.classList.contains('d-none')) {
                        button.innerHTML = '<i class="bi bi-chevron-down"></i> Read More';
                    } else {
                        button.innerHTML = '<i class="bi bi-chevron-up"></i> Read Less';
                    }
                });
            });
        });
    </script>
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

</body>

</html>