<?php
session_start();
header("Content-Type: application/json; charset=utf-8");

session_destroy();
echo json_encode(["sucesso" => true]);
