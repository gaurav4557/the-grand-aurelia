<?php
session_start();
require_once "db.php";
if (empty($_SESSION["admin_user_id"]) || ($_SESSION["admin_role"] ?? "") !== "admin") {
    header("Location: admin_login.php");
    exit;
}
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

$counts = ["all"=>0, "pending"=>0, "confirmed"=>0, "cancelled"=>0];
$r = $conn->query("SELECT COUNT(*) n FROM bookings");
if ($r) $counts["all"] = (int)$r->fetch_assoc()["n"];
$r = $conn->query("SELECT status, COUNT(*) n FROM bookings GROUP BY status");
if ($r) while ($x = $r->fetch_assoc()) {
    $k = strtolower($x["status"]);
    if (isset($counts[$k])) $counts[$k] = (int)$x["n"];
}

/* Revenue estimate: confirmed booking totals by booking creation date.
   This is booking revenue, not verified received payments or net profit. */
$today = date("Y-m-d");
$monthStart = date("Y-m-01");
$dailyIncome = 0.0;
$monthlyIncome = 0.0;
$s = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN DATE(created_at)=? THEN total_amount ELSE 0 END),0) AS daily_total,
                            COALESCE(SUM(CASE WHEN created_at>=? AND created_at<? THEN total_amount ELSE 0 END),0) AS monthly_total
                     FROM bookings WHERE status IN ('Confirmed','Completed')");
if ($s) {
    $nextMonth = date("Y-m-01", strtotime("+1 month"));
    $s->bind_param("sss", $today, $monthStart, $nextMonth);
    $s->execute();
    $incomeRow = $s->get_result()->fetch_assoc();
    $dailyIncome = (float)$incomeRow["daily_total"];
    $monthlyIncome = (float)$incomeRow["monthly_total"];
    $s->close();
}

$sql = "SELECT b.booking_id,b.check_in,b.check_out,b.guests,b.status,b.total_amount,b.created_at,
               u.full_name,u.email,u.phone,r.room_id,r.room_number,rt.type_name
        FROM bookings b
        JOIN users u ON b.user_id=u.user_id
        JOIN booking_rooms br ON b.booking_id=br.booking_id
        JOIN rooms r ON br.room_id=r.room_id
        JOIN room_types rt ON r.type_id=rt.type_id
        ORDER BY CASE b.status WHEN 'Pending' THEN 0 WHEN 'Confirmed' THEN 1 ELSE 2 END,b.created_at DESC";
$result = $conn->query($sql);

/* Rooms available for a stay starting today: exclude pending/confirmed overlapping bookings. */
$availableSql = "SELECT r.room_id,r.room_number,rt.type_name,rt.price_per_night,rt.capacity
                 FROM rooms r JOIN room_types rt ON r.type_id=rt.type_id
                 WHERE r.status='Available'
                   AND NOT EXISTS (
                     SELECT 1 FROM booking_rooms br JOIN bookings b ON b.booking_id=br.booking_id
                     WHERE br.room_id=r.room_id
                       AND b.status IN ('Pending','Confirmed')
                       AND b.check_in < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                       AND b.check_out > CURDATE()
                   )
                 ORDER BY r.room_number";
$availableResult = $conn->query($availableSql);

$notice = $_SESSION["admin_notice"] ?? "";
unset($_SESSION["admin_notice"]);
if (empty($_SESSION["admin_csrf"])) $_SESSION["admin_csrf"] = bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Dashboard</title>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#f4f1e8;color:#10233e;margin:0}
header{background:#0c1e36;color:white;padding:22px 4%;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
header h1{margin:0;font-size:24px}header a{color:#f3cf5c}main{max-width:1450px;margin:auto;padding:25px 4%}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:14px;margin:22px 0}
.stat{background:white;padding:18px;border-radius:10px;box-shadow:0 2px 8px #0000000b}
.stat strong{display:block;font-size:25px;margin-top:8px}.notice{background:#e0f5e5;padding:13px;margin:15px 0;border-radius:7px}
.wrap{overflow-x:auto;background:white;border-radius:10px;margin-bottom:24px}
table{width:100%;min-width:1050px;border-collapse:collapse}th,td{padding:11px;text-align:left;border-bottom:1px solid #ddd;vertical-align:top}
th{background:#142a47;color:white}.status{padding:5px 9px;border-radius:20px;font-size:12px;font-weight:bold}
.pending{background:#fff0c2;color:#795500}.confirmed{background:#d8f5df;color:#17652b}.cancelled{background:#ffe0e0;color:#9b1c1c}.completed{background:#dce8ff;color:#174a91}
form{display:inline-block;margin:2px}button{padding:8px 10px;border:0;border-radius:5px;cursor:pointer;font-weight:bold}
.approve{background:#176b39;color:white}.cancel,.delete{background:#a32626;color:white}.muted{color:#777}.small-note{color:#555;font-size:13px;line-height:1.5}
</style></head><body>
<header><h1>The Grand Aurelia — Admin Dashboard</h1><div>Signed in as <?=e($_SESSION["admin_name"]??"Admin")?> · <a href="admin_logout.php">Log out</a></div></header>
<main>
<p>Manage reservations, view available rooms, and check booking income.</p>
<?php if($notice):?><p class="notice"><?=e($notice)?></p><?php endif;?>
<section class="stats">
<div class="stat">All bookings<strong><?=$counts["all"]?></strong></div>
<div class="stat">Pending<strong><?=$counts["pending"]?></strong></div>
<div class="stat">Confirmed<strong><?=$counts["confirmed"]?></strong></div>
<div class="stat">Cancelled<strong><?=$counts["cancelled"]?></strong></div>
<div class="stat">Today's booking revenue<strong>₹<?=number_format($dailyIncome,2)?></strong></div>
<div class="stat">This month's booking revenue<strong>₹<?=number_format($monthlyIncome,2)?></strong></div>
</section>
<p class="small-note">Income figures are estimates from Confirmed/Completed booking totals by booking creation date. They are not verified payment receipts or net profit.</p>

<h2>Rooms available today</h2>
<div class="wrap"><table><thead><tr><th>Room</th><th>Type</th><th>Price per night</th><th>Capacity</th><th>Availability</th></tr></thead><tbody>
<?php if($availableResult && $availableResult->num_rows): while($room=$availableResult->fetch_assoc()):?>
<tr><td><?=e($room["room_number"])?></td><td><?=e($room["type_name"])?></td><td>₹<?=number_format((float)$room["price_per_night"],2)?></td><td><?=(int)$room["capacity"]?> guests</td><td><span class="status confirmed">Available</span></td></tr>
<?php endwhile; else: ?><tr><td colspan="5">No rooms are available today.</td></tr><?php endif;?>
</tbody></table></div>

<h2>All bookings</h2>
<div class="wrap"><table><thead><tr><th>ID / Created</th><th>Customer</th><th>Room</th><th>Stay</th><th>Guests</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if($result && $result->num_rows): while($b=$result->fetch_assoc()): $sc=strtolower($b["status"]);?>
<tr>
<td>#<?=(int)$b["booking_id"]?><br><small><?=e($b["created_at"])?></small></td>
<td><?=e($b["full_name"])?><br><?=e($b["email"])?><br><?=e($b["phone"])?></td>
<td>Room <?=e($b["room_number"])?><br><?=e($b["type_name"])?></td>
<td><?=e($b["check_in"])?><br>to <?=e($b["check_out"])?></td>
<td><?=(int)$b["guests"]?></td><td>₹<?=number_format((float)$b["total_amount"],2)?></td>
<td><span class="status <?=e($sc)?>"><?=e($b["status"])?></span></td><td>
<?php if($b["status"]==="Pending"):?>
<form method="post" action="admin_action.php"><input type="hidden" name="csrf" value="<?=e($_SESSION["admin_csrf"])?>"><input type="hidden" name="booking_id" value="<?=(int)$b["booking_id"]?>"><input type="hidden" name="action" value="confirm"><button class="approve">Accept</button></form>
<form method="post" action="admin_action.php" onsubmit="return confirm('Cancel this booking?')"><input type="hidden" name="csrf" value="<?=e($_SESSION["admin_csrf"])?>"><input type="hidden" name="booking_id" value="<?=(int)$b["booking_id"]?>"><input type="hidden" name="action" value="cancel"><button class="cancel">Cancel</button></form>
<?php endif;?>
<form method="post" action="admin_action.php" onsubmit="return confirm('Permanently delete booking #<?= (int)$b["booking_id"] ?>? Its linked room assignment and payment/service records will also be deleted. This cannot be undone.')"><input type="hidden" name="csrf" value="<?=e($_SESSION["admin_csrf"])?>"><input type="hidden" name="booking_id" value="<?=(int)$b["booking_id"]?>"><input type="hidden" name="action" value="delete"><button class="delete">Delete</button></form>
</td></tr>
<?php endwhile; else:?><tr><td colspan="8">No bookings found.</td></tr><?php endif;?>
</tbody></table></div>
<p><a href="index.php">View public hotel website</a></p>
</main></body></html>
