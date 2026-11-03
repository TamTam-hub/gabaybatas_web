<?php
// The public case lookup was turned off: anyone could read a case's names,
// hearing and history by trying case numbers. Residents track their own cases
// in the GabayBatas app after signing in.
header('Location: login.php', true, 301);
exit();
