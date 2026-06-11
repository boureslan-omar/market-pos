<?php
session_start();
session_destroy();
header('Location: /dahdouh/login.php'); exit;
