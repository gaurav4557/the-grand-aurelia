<?php
session_start(); require_once "db.php";
if (!empty($_SESSION["admin_user_id"]) && ($_SESSION["admin_role"]??"") === "admin") { header("Location: admin.php"); exit; }
$error="";
if($_SERVER["REQUEST_METHOD"]==="POST"){
$email=trim($_POST["email"]??"");$password=$_POST["password"]??"";
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password==="")$error="Enter your admin email and password.";
else{$s=$conn->prepare("SELECT user_id,full_name,password_hash,role FROM users WHERE email=? AND role='admin' LIMIT 1");$s->bind_param("s",$email);$s->execute();$a=$s->get_result()->fetch_assoc();$s->close();
if($a&&password_verify($password,$a["password_hash"])){session_regenerate_id(true);$_SESSION["admin_user_id"]=(int)$a["user_id"];$_SESSION["admin_name"]=$a["full_name"];$_SESSION["admin_role"]="admin";header("Location: admin.php");exit;}else $error="Incorrect admin email or password.";}
}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,"UTF-8");}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Login</title><style>
*{box-sizing:border-box}body{font-family:Arial;background:#0c1e36;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}main{background:white;color:#10233e;padding:32px;border-radius:14px;width:min(100%,440px)}label{display:block;margin:16px 0 6px}input{width:100%;padding:12px;border:1px solid #ccc;border-radius:6px}button{width:100%;margin-top:22px;padding:13px;background:#cba32b;border:0;border-radius:6px;font-weight:bold}.error{background:#ffe6e6;color:#921b1b;padding:12px}</style></head><body><main>
<h1>Administrator Login</h1><p>The Grand Aurelia</p><?php if($error):?><p class="error"><?=e($error)?></p><?php endif;?>
<form method="post"><label>Email</label><input type="email" name="email" autocomplete="username" required><label>Password</label><input type="password" name="password" autocomplete="current-password" required><button>Sign In</button></form><p><a href="index.php">Back to hotel website</a></p></main></body></html>
