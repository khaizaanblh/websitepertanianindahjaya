<?php

session_start();

session_unset();
session_destroy();

header("Location: /kasir_pertanian/login.php");
exit;
