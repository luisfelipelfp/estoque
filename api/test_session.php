<?php
echo "session_loaded=" . (extension_loaded("session") ? "yes" : "no");
echo "\nsession_save_path=" . ini_get("session.save_path");
echo "\n";
