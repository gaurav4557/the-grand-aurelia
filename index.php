<?php
require_once "db.php";

$search_errors = [];
$search_notice = "";
$search_empty = "";
$check_in = "";
$check_out = "";
$guests = "";
$searched = false;
$available_rooms = [];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function is_valid_date($value) {
    $date = DateTime::createFromFormat("!Y-m-d", $value);
    return $date && $date->format("Y-m-d") === $value;
}

if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["search"])) {
    $check_in = trim($_GET["check_in"] ?? "");
    $check_out = trim($_GET["check_out"] ?? "");
    $guests = trim($_GET["guests"] ?? "");

    if ($check_in === "" || !is_valid_date($check_in)) {
        $search_errors[] = "Please choose a valid check-in date.";
    }
    if ($check_out === "" || !is_valid_date($check_out)) {
        $search_errors[] = "Please choose a valid check-out date.";
    }
    if ($guests === "" || !ctype_digit($guests) || (int)$guests < 1) {
        $search_errors[] = "Please enter a positive whole number of guests.";
    } elseif ((int)$guests > 10) {
        $search_errors[] = "Guest search is limited to 10 people for now.";
    }

    $today = date("Y-m-d");
    if ($check_in !== "" && is_valid_date($check_in) && $check_in < $today) {
        $search_errors[] = "Check-in cannot be in the past.";
    }
    if ($check_in !== "" && $check_out !== "" &&
        is_valid_date($check_in) && is_valid_date($check_out) &&
        $check_out <= $check_in) {
        $search_errors[] = "Check-out must be after check-in.";
    }

    if (empty($search_errors)) {
        $searched = true;
        $guest_count = (int)$guests;

        $availability_sql = "
            SELECT rooms.room_id, rooms.room_number, rooms.status,
                   room_types.type_name, room_types.price_per_night,
                   room_types.capacity, room_types.description
            FROM rooms
            JOIN room_types ON rooms.type_id = room_types.type_id
            WHERE rooms.status = 'Available'
              AND room_types.capacity >= ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM booking_rooms
                  JOIN bookings ON booking_rooms.booking_id = bookings.booking_id
                  WHERE booking_rooms.room_id = rooms.room_id
                    AND bookings.status IN ('Pending', 'Confirmed')
                    AND bookings.check_in < ?
                    AND bookings.check_out > ?
              )
            ORDER BY rooms.room_number
        ";

        $stmt = $conn->prepare($availability_sql);
        if ($stmt) {
            $stmt->bind_param("iss", $guest_count, $check_out, $check_in);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $available_rooms[] = $row;
            }
            $stmt->close();

            $match_count = count($available_rooms);
            if ($match_count > 0) {
                $search_notice = $match_count . " room(s) are available for those dates. Select Book Now to request a reservation.";
            } else {
                $search_empty = "No rooms match those dates and guest count. Try different dates or fewer guests.";
            }
        } else {
            $search_errors[] = "We could not check availability right now.";
            $searched = false;
        }
    }
}

$rooms_sql = "
    SELECT rooms.room_id, rooms.room_number, rooms.status,
           room_types.type_name, room_types.price_per_night,
           room_types.capacity, room_types.description
    FROM rooms
    JOIN room_types ON rooms.type_id = room_types.type_id
    ORDER BY rooms.room_number
";
$rooms_result = $conn->query($rooms_sql);

$room_images = [
    "Standard" => "https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&w=1200&q=80",
    "Deluxe" => "https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=1200&q=80",
    "Suite" => "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=1200&q=80",
    "Single" => "https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=1200&q=80",
    "Family Suite" => "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=1200&q=80"
];
$default_room_image = "https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=1200&q=80";

function render_room_card($room, $room_images, $default_room_image, $check_in = "", $check_out = "", $guests = "") {
    $type_name = $room["type_name"];
    $image_src = $room_images[$type_name] ?? $default_room_image;
    $status_class = strtolower($room["status"]);
    $booking_url = "book.php?room_id=" . (int)$room["room_id"];

    // Carry search dates and guest count to the booking form when available.
    $query = [];
    if ($check_in !== "") $query["check_in"] = $check_in;
    if ($check_out !== "") $query["check_out"] = $check_out;
    if ($guests !== "") $query["guests"] = $guests;
    if ($query) $booking_url .= "&" . http_build_query($query);
    ?>
    <article class="room-card">
        <div class="room-photo">
            <img src="<?= e($image_src) ?>"
                 alt="<?= e($type_name) ?> room interior"
                 loading="lazy"
                 onerror="this.onerror=null;this.src='<?= e($default_room_image) ?>';">
            <span class="status-badge status-<?= e($status_class) ?>">
                <?= e($room["status"]) ?>
            </span>
        </div>
        <div class="room-body">
            <p class="room-number">Room <?= e($room["room_number"]) ?></p>
            <h3><?= e($room["type_name"]) ?></h3>
            <p class="room-copy"><?= e($room["description"]) ?></p>
            <dl class="room-meta">
                <div>
                    <dt>Price per night</dt>
                    <dd>₹<?= e(number_format((float)$room["price_per_night"], 2)) ?></dd>
                </div>
                <div>
                    <dt>Capacity</dt>
                    <dd><?= (int)$room["capacity"] ?> guests</dd>
                </div>
            </dl>
            <?php if ($room["status"] === "Available"): ?>
                <a class="btn-gold room-book-button" href="<?= e($booking_url) ?>">Book Now</a>
            <?php else: ?>
                <span class="room-unavailable">Not bookable right now</span>
            <?php endif; ?>
        </div>
    </article>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Grand Aurelia | Hotel Management System</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .site-nav .admin-nav-link { color: #cba32b; font-weight: 700; }
        .developer-credit { text-align: center; margin: 18px 0 6px; color: #f3cf5c; font-size: 14px; }
        .developer-credit strong { color: #ffffff; letter-spacing: .3px; }
        .room-book-button { display:inline-block; text-decoration:none; text-align:center; margin-top:18px; padding:12px 22px; cursor:pointer; }
        .room-unavailable { display:inline-block; margin-top:18px; opacity:.7; }
        .room-card .room-body { display:flex; flex-direction:column; }
        .room-meta { margin-bottom:0; }
    </style>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <header class="site-header">
        <div class="container header-inner">
            <a class="logo" href="index.php">
                <span class="logo-mark">GA</span>
                <span class="logo-text">
                    <strong>The Grand Aurelia</strong>
                    <small>Luxury Stay &amp; Service</small>
                </span>
            </a>
            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">
                <span class="sr-only">Open menu</span><span></span><span></span><span></span>
            </button>
            <nav id="site-nav" class="site-nav" aria-label="Main">
                <a href="index.php">Home</a>
                <a href="#rooms">Rooms</a>
                <a href="#amenities">Amenities</a>
                <a href="#contact">Contact</a>
                <a href="admin_login.php" class="admin-nav-link">Admin Login</a>
            </nav>
        </div>
    </header>

    <main id="main-content">
        <section class="hero">
            <img class="hero-image"
                 src="https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1920&q=80"
                 alt="Hotel with pool and outdoor seating">
            <div class="hero-overlay"></div>
            <div class="container hero-content">
                <p class="eyebrow">Welcome to The Grand Aurelia</p>
                <h1>A refined stay in the heart of the city</h1>
                <p class="hero-lead">Discover comfortable rooms, attentive service, and a calm luxury experience designed for business and leisure guests.</p>

                <form class="search-form" method="get" action="index.php">
                    <input type="hidden" name="search" value="1">
                    <div class="form-field">
                        <label for="check_in">Check-in</label>
                        <input type="date" id="check_in" name="check_in" min="<?= e(date("Y-m-d")) ?>" value="<?= e($check_in) ?>" required>
                    </div>
                    <div class="form-field">
                        <label for="check_out">Check-out</label>
                        <input type="date" id="check_out" name="check_out" min="<?= e(date("Y-m-d", strtotime("+1 day"))) ?>" value="<?= e($check_out) ?>" required>
                    </div>
                    <div class="form-field">
                        <label for="guests">Guests</label>
                        <input type="number" id="guests" name="guests" min="1" max="10" value="<?= e($guests) ?>" required>
                    </div>
                    <button class="btn-gold" type="submit">Search rooms</button>
                </form>

                <div class="form-messages" aria-live="polite">
                    <?php if ($search_errors): ?>
                        <ul class="alert alert-error">
                            <?php foreach ($search_errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($search_notice !== ""): ?>
                        <p class="alert alert-success"><?= e($search_notice) ?></p>
                    <?php endif; ?>
                    <?php if ($search_empty !== ""): ?>
                        <p class="alert alert-info"><?= e($search_empty) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($searched && empty($search_errors)): ?>
        <section id="availability" class="section rooms-section">
            <div class="container">
                <header class="section-header">
                    <p class="eyebrow">Live availability</p>
                    <h2>Rooms available for your dates</h2>
                    <p>Showing rooms for <?= e($guests) ?> guest(s), from <?= e($check_in) ?> to <?= e($check_out) ?>. Pending and confirmed bookings are treated as occupied.</p>
                </header>
                <div class="room-grid">
                    <?php if ($available_rooms): ?>
                        <?php foreach ($available_rooms as $room): ?>
                            <?php render_room_card($room, $room_images, $default_room_image, $check_in, $check_out, $guests); ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="empty-state">No rooms match those dates and guest count.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section id="rooms" class="section rooms-section">
            <div class="container">
                <header class="section-header">
                    <p class="eyebrow">Our collection</p>
                    <h2>Rooms from the live hotel database</h2>
                    <p>Choose a room and select Book Now to request a reservation.</p>
                </header>
                <div class="room-grid">
                    <?php if ($rooms_result && $rooms_result->num_rows > 0): ?>
                        <?php while ($room = $rooms_result->fetch_assoc()): ?>
                            <?php render_room_card($room, $room_images, $default_room_image); ?>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="empty-state">No rooms were found in the database yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section id="amenities" class="section amenities-section">
            <div class="container">
                <header class="section-header light">
                    <p class="eyebrow">Hotel comforts</p>
                    <h2>Amenities for a memorable stay</h2>
                </header>
                <div class="amenity-grid">
                    <article class="amenity-card"><h3>Fine dining</h3><p>In-house restaurant service with breakfast, lunch, and evening menus.</p></article>
                    <article class="amenity-card"><h3>Spa &amp; wellness</h3><p>Calm treatment rooms and a quiet space to recover after travel.</p></article>
                    <article class="amenity-card"><h3>Concierge desk</h3><p>Local recommendations, airport transfers, and guest support around the clock.</p></article>
                    <article class="amenity-card"><h3>High-speed Wi-Fi</h3><p>Reliable internet in rooms, the lobby, and meeting spaces.</p></article>
                </div>
            </div>
        </section>
    </main>

    <footer id="contact" class="site-footer">
        <div class="container footer-grid">
            <div>
                <p class="logo-text"><strong>The Grand Aurelia</strong><small>Hotel Management System</small></p>
                <p>A learning project connected to the hotel_management database.</p>
            </div>
            <div><h2>Visit</h2><p>Shirdi, Ahilyanagar<br><a href="tel:+919370847595">+91 9370847595</a></p></div>
            <div><h2>Hours</h2><p>Reception is open 24 hours<br>Check-in: 24/7</p></div>
        </div>
        <p class="developer-credit">Developed by <strong>Gaurav &amp; Karan</strong></p>
        <p class="copyright">© <?= date("Y") ?> The Grand Aurelia. All rights reserved.</p>
    </footer>

    <script src="assets/script.js"></script>
</body>
</html>
