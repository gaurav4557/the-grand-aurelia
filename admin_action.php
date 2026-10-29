<?php
session_start();require_once "db.php";
if(empty($_SESSION["admin_user_id"])||($_SESSION["admin_role"]??"")!=="admin"){header("Location: admin_login.php");exit;}
if($_SERVER["REQUEST_METHOD"]!=="POST"){header("Location: admin.php");exit;}
if(empty($_SESSION["admin_csrf"])||!hash_equals($_SESSION["admin_csrf"],$_POST["csrf"]??"")){$_SESSION["admin_notice"]="Security check failed. Try again.";header("Location: admin.php");exit;}
$id=filter_input(INPUT_POST,"booking_id",FILTER_VALIDATE_INT);$action=$_POST["action"]??"";
if(!$id||!in_array($action,["confirm","cancel","delete"],true)){$_SESSION["admin_notice"]="Invalid booking action.";header("Location: admin.php");exit;}
$conn->begin_transaction();
try{
$s=$conn->prepare("SELECT booking_id,status,check_in,check_out FROM bookings WHERE booking_id=? FOR UPDATE");$s->bind_param("i",$id);$s->execute();$b=$s->get_result()->fetch_assoc();$s->close();
if (!$b) throw new Exception("Booking not found.");
if ($action === "delete") {
    // College-project behavior: allow deleting bookings of any status.
    // Remove dependent rows first so the booking can be deleted cleanly.
    // This also removes the linked payment/service-order records for this booking,
    // so dashboard revenue calculated from bookings decreases automatically.
    $s = $conn->prepare("DELETE FROM service_orders WHERE booking_id=?");
    if ($s) {
        $s->bind_param("i", $id);
        $s->execute();
        $s->close();
    }

    $s = $conn->prepare("DELETE FROM payments WHERE booking_id=?");
    if ($s) {
        $s->bind_param("i", $id);
        $s->execute();
        $s->close();
    }

    $s = $conn->prepare("DELETE FROM booking_rooms WHERE booking_id=?");
    if (!$s) throw new Exception("Could not prepare booking-room cleanup.");
    $s->bind_param("i", $id);
    $s->execute();
    $s->close();

    $s = $conn->prepare("DELETE FROM bookings WHERE booking_id=?");
    if (!$s) throw new Exception("Could not prepare booking deletion.");
    $s->bind_param("i", $id);
    $s->execute();
    $s->close();

    $conn->commit();
    $_SESSION["admin_notice"] = "Booking #" . $id . " and its linked records were deleted. The room is available again if no other booking overlaps.";
    header("Location: admin.php");
    exit;
}
if ($b["status"] !== "Pending") throw new Exception("Only pending bookings can be changed.");
if($action==="confirm"){
$s=$conn->prepare("SELECT COUNT(*) n FROM booking_rooms br JOIN booking_rooms other_br ON other_br.room_id=br.room_id AND other_br.booking_id<>br.booking_id JOIN bookings other_b ON other_b.booking_id=other_br.booking_id WHERE br.booking_id=? AND other_b.status='Confirmed' AND other_b.check_in<? AND other_b.check_out>?");$s->bind_param("iss",$id,$b["check_out"],$b["check_in"]);$s->execute();$n=(int)$s->get_result()->fetch_assoc()["n"];$s->close();
if($n>0)throw new Exception("Cannot accept: this room overlaps another confirmed booking.");$status="Confirmed";
}else{$status="Cancelled";}
$s=$conn->prepare("UPDATE bookings SET status=? WHERE booking_id=?");$s->bind_param("si",$status,$id);$s->execute();$s->close();$conn->commit();$_SESSION["admin_notice"]="Booking #".$id." updated to ".$status.".";
}catch(Throwable $e){$conn->rollback();$_SESSION["admin_notice"]=$e instanceof mysqli_sql_exception?"Database error while updating booking.":$e->getMessage();}
header("Location: admin.php");exit;
