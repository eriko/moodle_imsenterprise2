<?php
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_login(0, false);
require_capability('moodle/site:config', get_context_instance(CONTEXT_SYSTEM));

$site = get_site();

/// get language strings
$PAGE->set_context(get_context_instance(CONTEXT_SYSTEM));

$PAGE->set_url('/enrol/imsenterprise2/importnow.php');
$PAGE->set_title(get_string('importimsfile', 'enrol_imsenterprise2'));
$PAGE->set_heading(get_string('importimsfile', 'enrol_imsenterprise2'));
$PAGE->navbar->add(get_string('administrationsite'));
$PAGE->navbar->add(get_string('plugins', 'admin'));
$PAGE->navbar->add(get_string('enrolments', 'enrol'));
$PAGE->navbar->add(get_string('pluginname', 'enrol_imsenterprise2'), new moodle_url('/admin/settings.php', array('section'=>'enrolsettingsimsenterprise2')));
$PAGE->navbar->add(get_string('importimsfile', 'enrol_imsenterprise2'));
$PAGE->navigation->clear_cache();

echo $OUTPUT->header();

require_once('lib.php');

//echo "Creating the IMS Enterprise enroller object\n";
$enrol = new enrol_imsenterprise2_plugin();

?>
<p>Launching the IMS Enterprise "cron" function. The import log will appear below (giving details of any
problems that might require attention).</p>
<pre style="margin:10px; padding: 2px; border: 1px solid black; background-color: white; color: black;"><?php
//error_reporting(E_ALL);
$enrol->cron();
?></pre><?php
echo $OUTPUT->footer();

exit;
?>
