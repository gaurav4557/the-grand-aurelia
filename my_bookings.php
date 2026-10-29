<?php
require_once "db.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$bookings = [];
$error = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $sql = "
            SELECT
                b.booking_id,
                b.check_in,
                b.check_out,
                b.guests,
                b.status,
                b.total_amount,
                r.room_number,
                rt.type_name
            FROM bookings b
            JOIN users u ON b.user_id = u.user_id
            JOIN booking_rooms br ON b.booking_id = br.booking_id
            JOIN rooms r ON br.room_id = r.room_id
            JOIN room_types rt ON r.type_id = rt.type_id
            WHERE u.email = ?
            ORDER BY b.created_at DESC
        ";

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $bookings[] = $row;
            }

            $stmt->close();
        } else {
            $error = "Unable to retrieve bookings right now.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Bookings | The Grand Aurelia</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .booking-page {
            max-width: 1000px;
            margin: 50px auto;
            padding: 24px;
        }
        .booking-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin: 24px 0;
        }
        .booking-form input {
            flex: 1;
            min-width: 220px;
            padding: 13px;
        }
        .booking-form button {
            padding: 13px 22px;
            cursor: pointer;
        }
        .booking-table-wrap { overflow-x: auto; }
        .booking-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        .booking-table th, .booking-table td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        .booking-table th {
            background: #0c1e36;
            color: white;
        }
        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: bold;
        }
        .status-pending { background: #fff0c2; color: #805900; }
        .status-confirmed { background: #d8f5df; color: #17652b; }
        .status-cancelled { background: #ffe0e0; color: #9b1c1c; }
        .status-completed { background: #dce8ff; color: #174a91; }
    </style>
</head>
<body>
<main class="booking-page">
    <h1>My Bookings</h1>
    <p>Enter the email address used when requesting your reservation.</p>

    <?php if ($error): ?>
        <p role="alert" style="color:#b00020;"><?= e($error) ?></p>
    <?php endif; ?>

    <form class="booking-form" method="post">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email"
               value="<?= e($email) ?>" required>
        <button class="btn-gold" type="submit">View Bookings</button>
    </form>

    <?php if ($_SERVER["REQUEST_METHOD"] === "POST" && !$error): ?>
        <?php if (count($bookings) === 0): ?>
            <p>No bookings were found for that email address.</p>
        <?php else: ?>
            <div class="booking-table-wrap">
                <table class="booking-table">
                    <thead>
                    <tr>
                        <th>Booking ID</th>
                        <th>Room</th>
                        <th>Type</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Guests</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bookings as $b): ?>
                        <tr>
                            <td><?= (int)$b["booking_id"] ?></td>
                            <td><?= e($b["room_number"]) ?></td>
                            <td><?= e($b["type_name"]) ?></td>
                            <td><?= e($b["check_in"]) ?></td>
                            <td><?= e($b["check_out"]) ?></td>
                            <td><?= (int)$b["guests"] ?></td>
                            <td>₹<?= number_format((float)$b["total_amount"], 2) ?></td>
                            <td>
                                <span class="status status-<?= e(strtolower($b["status"])) ?>">
                                    <?= e($b["status"]) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <p><a href="index.php">← Back to hotel homepage</a></p>
</main>
</body>
</html>