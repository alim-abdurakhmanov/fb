<?php
require_once __DIR__ . '/config.php';
finbuild_destroy_session();
header('Location: index.php');
exit;