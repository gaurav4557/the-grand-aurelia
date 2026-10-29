<?php
require_once "db.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$roomId = filter_input(INPUT_GET, "room_id", FILTER_VALIDATE_INT);
$error = "";
$success = "";
$room = null;

if ($roomId) {
    $stmt = $conn->prepare(
        "SELECT r.room_id, r.room_number, r.status,
                rt.type_name, rt.price_per_night, rt.capacity
         FROM rooms r
         JOIN room_types rt ON r.type_id = rt.type_id
         WHERE r.room_id = ?"
    );
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $roomId = filter_input(INPUT_POST, "room_id", FILTER_VALIDATE_INT);
    $name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $checkIn = $_POST["check_in"] ?? "";
    $checkOut = $_POST["check_out"] ?? "";
    $guests = filter_input(INPUT_POST, "guests", FILTER_VALIDATE_INT);

    $today = date("Y-m-d");

    if (!$roomId || !$name || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !$phone || !$checkIn || !$checkOut || !$guests || $guests < 1) {
        $error = "Please enter valid details in every field.";
    } elseif ($checkIn < $today) {
        $error = "Check-in cannot be in the past.";
    } elseif ($checkOut <= $checkIn) {
        $error = "Check-out must be after check-in.";
    } else {
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare(
                "SELECT r.room_id, r.status, rt.price_per_night, rt.capacity
                 FROM rooms r
                 JOIN room_types rt ON r.type_id = rt.type_id
                 WHERE r.room_id = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $roomId);
            $stmt->execute();
            $selectedRoom = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$selectedRoom || $selectedRoom["status"] !== "Available") {
                throw new Exception("This room is not available for booking.");
            }

            if ($guests > (int)$selectedRoom["capacity"]) {
                throw new Exception("The selected room cannot accommodate that many guests.");
            }

            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS conflicts
                 FROM bookings b
                 JOIN booking_rooms br ON b.booking_id = br.booking_id
                 WHERE br.room_id = ?
                   AND b.status IN ('Pending', 'Confirmed')
                   AND b.check_in < ?
                   AND b.check_out > ?"
            );
            $stmt->bind_param("iss", $roomId, $checkOut, $checkIn);
            $stmt->execute();
            $conflicts = $stmt->get_result()->fetch_assoc()["conflicts"];
            $stmt->close();

            if ((int)$conflicts > 0) {
                throw new Exception("This room is already booked for those dates. Please choose other dates.");
            }

            $stmt = $conn->prepare(
                "SELECT user_id FROM users WHERE email = ? LIMIT 1"
            );
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $existingUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existingUser) {
                $userId = (int)$existingUser["user_id"];
            } else {
                $placeholderHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $role = "customer";

                $stmt = $conn->prepare(
                    "INSERT INTO users (full_name, email, phone, password_hash, role)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("sssss", $name, $email, $phone, $placeholderHash, $role);
                $stmt->execute();
                $userId = $conn->insert_id;
                $stmt->close();
            }

            $start = new DateTime($checkIn);
            $end = new DateTime($checkOut);
            $nights = (int)$start->diff($end)->days;
            $total = $nights * (float)$selectedRoom["price_per_night"];

            $status = "Pending";

            $stmt = $conn->prepare(
                "INSERT INTO bookings
                 (user_id, check_in, check_out, guests, status, total_amount)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "issisd",
                $userId, $checkIn, $checkOut, $guests, $status, $total
            );
            $stmt->execute();
            $bookingId = $conn->insert_id;
            $stmt->close();

            $price = (float)$selectedRoom["price_per_night"];

            $stmt = $conn->prepare(
                "INSERT INTO booking_rooms (booking_id, room_id, price_per_night)
                 VALUES (?, ?, ?)"
            );
            $stmt->bind_param("iid", $bookingId, $roomId, $price);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $success = "Booking request saved successfully! Your booking ID is "
                     . $bookingId . ". Status: Pending. No payment has been taken.";

            $room = null;
        } catch (Throwable $ex) {
            $conn->rollback();
            $error = $ex instanceof mysqli_sql_exception
                ? "The booking could not be saved. Please check the database and try again."
                : $ex->getMessage();
        }
    }

    if (!$room && $roomId) {
        $stmt = $conn->prepare(
            "SELECT r.room_id, r.room_number, r.status,
                    rt.type_name, rt.price_per_night, rt.capacity
             FROM rooms r JOIN room_types rt ON r.type_id = rt.type_id
             WHERE r.room_id = ?"
        );
        $stmt->bind_param("i", $roomId);
        $stmt->execute();
        $room = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book a Room | The Grand Aurelia</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main style="max-width:720px;margin:50px auto;padding:24px;">
        <h1>Book Your Stay</h1>
        <p>Complete this form to request a room reservation.</p>

        <?php if ($error): ?>
            <p role="alert" style="color:#b00020;">
                <?= e($error) ?>
            </p>
        <?php endif; ?>

        <?php if ($success): ?>
            <p role="status" style="color:green;">
                <?= e($success) ?>
            </p>
        <?php endif; ?>

        <?php if ($room && !$success): ?>
            <h2>Room <?= e($room["room_number"]) ?> — <?= e($room["type_name"]) ?></h2>
            <p>Price per night: ₹<?= number_format((float)$room["price_per_night"], 2) ?></p>
            <p>Maximum capacity: <?= (int)$room["capacity"] ?> guests</p>

            <?php if ($room["status"] !== "Available"): ?>
                <p>This room is currently not available for booking.</p>
            <?php else: ?>
                <form method="post" action="book.php" style="display:grid;gap:14px;">
                    <input type="hidden" name="room_id" value="<?= (int)$room["room_id"] ?>">

                    <label>Full name
                        <input required name="full_name" maxlength="100"
                               value="<?= e($_POST["full_name"] ?? "") ?>">
                    </label>

                    <label>Email
                        <input required type="email" name="email" maxlength="120"
                               value="<?= e($_POST["email"] ?? "") ?>">
                    </label>

                    <label>Phone
                        <input required type="tel" name="phone" maxlength="15"
                               value="<?= e($_POST["phone"] ?? "") ?>">
                    </label>

                    <label>Check-in
                        <input required type="date" name="check_in"
                               min="<?= e(date("Y-m-d")) ?>"
                               value="<?= e($_POST["check_in"] ?? "") ?>">
                    </label>

                    <label>Check-out
                        <input required type="date" name="check_out"
                               value="<?= e($_POST["check_out"] ?? "") ?>">
                    </label>

                    <label>Guests
                        <input required type="number" name="guests" min="1"
                               max="<?= (int)$room["capacity"] ?>"
                               value="<?= e($_POST["guests"] ?? "1") ?>">
                    </label>

                    <button type="submit">Request Booking</button>
                </form>
            <?php endif; ?>
        <?php elseif (!$success && !$room): ?>
            <p>Room not found. Please return to the <a href="index.php">hotel homepage</a>.</p>
        <?php endif; ?>

        <p><a href="index.php">← Back to rooms</a></p>
    </main>
</body>
</html>