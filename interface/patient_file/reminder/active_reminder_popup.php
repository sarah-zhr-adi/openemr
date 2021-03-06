<?php
/**
 * active reminder popup gui
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @author  Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2011-2018 Brady Miller <brady.g.miller@gmail.com>
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


require_once("../../globals.php");
require_once("$srcdir/clinical_rules.php");

use OpenEMR\Core\Header;

?>
<html>
<head>
<?php Header::setupHeader('opener'); ?>
</head>

<body class="body_top">
<?php

// Set the session flag to show that notification was last done with this patient
$_SESSION['alert_notify_pid'] = $pid;

?>
<table class="border-0" cellspacing='0' cellpadding='0'>
<tr>

<?php
$all_allergy_alerts = array();
if ($GLOBALS['enable_allergy_check']) {
  // Will show allergy and medication/prescription conflicts here
    $all_allergy_alerts = allergy_conflict($pid, 'all', $_SESSION['authUser']);
}

$active_alerts = active_alert_summary($pid, "reminders-due", '', 'default', $_SESSION['authUser']);
?>

<td><span class="title">
<?php
if (!empty($active_alerts) && empty($all_allergy_alerts)) {
    echo xlt("Alerts/Reminders");
} else if (!empty($active_alerts) && !empty($all_allergy_alerts)) {
    echo xlt("WARNINGS and Alerts/Reminders");
} else { // empty($active_alerts) && !empty($all_allergy_alerts)
    echo xlt("WARNINGS");
}

?>
</span>&nbsp;&nbsp;&nbsp;</td>
<td>
    <a href="#" id="close" class="btn btn-secondary btn-lg" onclick="dlgclose(); return false;"><?php echo xlt('Close');?></a>
</td>
</tr>
</table>
<br />
<?php
foreach ($all_allergy_alerts as $allergy) {
    echo xlt("ALLERGY WARNING") . ":" . text($allergy) ."<br />";
}

if (!empty($all_allergy_alerts)) {
    echo "<br />";
}

echo $active_alerts;
?>
</body>
</html>
