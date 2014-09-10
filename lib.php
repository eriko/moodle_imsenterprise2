<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * IMS Enterprise file enrolment plugin.
 *
 * This plugin lets the user specify an IMS Enterprise file to be processed.
 * The IMS Enterprise file is mainly parsed on a regular cron,
 * but can also be imported via the UI (Admin Settings).
 * @package    enrol
 * @subpackage imsenterprise2
 * @copyright  2010 Eugene Venter
 * @author     Eugene Venter - based on code by Dan Stowell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/*

Note for programmers:

This class uses regular expressions to mine the data file. The main reason is
that XML handling changes from PHP 4 to PHP 5, so this should work on both.

One drawback is that the pattern-matching doesn't (currently) handle XML
namespaces - it only copes with a <group> tag if it says <group>, and not
(for example) <ims:group>.

This should also be able to handle VERY LARGE FILES - so the entire IMS file is
NOT loaded into memory at once. It's handled line-by-line, 'forgetting' tags as
soon as they are processed.

N.B. The "sourcedid" ID code is translated to Moodle's "idnumber" field, both
for users and for courses.

*/

require_once($CFG->dirroot . '/group/lib.php');


class enrol_imsenterprise2_plugin extends enrol_plugin
{

    var $log;

    public function roles_protected()
    {
        // users may tweak the roles later
        return true;
    }

    public function allow_enrol(stdClass $instance)
    {
        // users with enrol cap may unenrol other users manually manually
        return true;
    }

    public function allow_unenrol(stdClass $instance)
    {
        // users with unenrol cap may unenrol other users manually manually
        return true;
    }

    public function allow_manage(stdClass $instance)
    {
        // users with manage cap may tweak period and status
        return true;
    }

    /**
     * Read in an IMS Enterprise file.
     * Originally designed to handle v1.1 files but should be able to handle
     * earlier types as well, I believe.
     *
     */
    function cron()
    {
        global $CFG;

        // Get configs
        $imsfilelocation = $this->get_config('imsfilelocation');
        $logtolocation = $this->get_config('logtolocation');
        $mailadmins = $this->get_config('mailadmins');
        $prev_time = $this->get_config('prev_time');
        $prev_md5 = $this->get_config('prev_md5');
        $prev_path = $this->get_config('prev_path');
        $fromweb = $this->get_config('fromweb');


        $this->logfp = false; // File pointer for writing log data to
        if (!empty($logtolocation)) {
            $this->logfp = fopen($logtolocation, 'a');
        }


        $md5 = 0; // NB We'll write this value back to the database at the end of the cron
        $filemtime = 0;
        $file_exists = false;

        $this->log_line('the file is located at:  ' . $imsfilelocation);
        $this->log_line('is the file from the web:  ' . $fromweb);
        $this->log_line('the available enrolments are:' . $CFG->enrol_plugins_enabled);

        if (empty($imsfilelocation)) {
            // $filename = "$CFG->dirroot/enrol/imsenterprise/example.xml";  // Default location
            $filename = "$CFG->dataroot/1/imsenterprise-enrol.xml"; // Default location
        } else {
            $filename = $imsfilelocation;
        }

        $xml = file_get_contents($filename);

        if ($fromweb) {

            $md5 = md5($xml);
            $filemtime = time();

            $this->log_line('the url mtime is set as follows:  ' . $filemtime);
            $this->log_line('the url md5 is set as follows:  ' . $md5);
            if (strlen($xml) > 0) {
                $file_exists = true;
            }
        } else {

            if (file_exists($filename)) {
                $this->log_line('the file exists:  ');
                $md5 = md5_file($filename);
                $filemtime = filemtime($filename);

                $this->log_line('the file mtime is set as follows:  ' . $filemtime);
                $this->log_line('the file md5 is set as follows:  ' . $md5);
                $file_exists = true;
            }
        }


        if ($file_exists) {
            @set_time_limit(0);
            $starttime = time();

            $this->log_line('----------------------------------------------------------------------');
            $this->log_line("IMS Enterprise enrol cron process launched at " . userdate(time()));
            $this->log_line('Found file ' . $imsfilelocation);
            $this->xmlcache = '';

            // Make sure we understand how to map the IMS-E roles to Moodle roles
            $this->load_role_mappings();


            // Decide if we want to process the file (based on filepath, modification time, and MD5 hash)
            // This is so we avoid wasting the server's efforts processing a file unnecessarily
            if (empty($prev_path) || ($filename != $prev_path)) {
                $fileisnew = true;
            } elseif (isset($prev_time) && ($filemtime <= $prev_time)) {
                $fileisnew = false;
                $this->log_line('File modification time is not more recent than last update - skipping processing.');
            }
            //elseif (isset($prev_md5) && ($md5 == $prev_md5)) {
            //    $fileisnew = false;
            //    $this->log_line('File MD5 hash is same as on last update - skipping processing.');
            //}
            else {
                $fileisnew = true; // Let's process it!
            }


            if ($fileisnew) {
                $this->log_line('file is new');
                // FIRST PASS: Run through the file and process the group/person entries
                ////if (($fh = fopen($filename, "r")) != false) {
                //$fh = explode("\n", $xml);
                //$this->log_line('the file is split and has ' . count($fh) . " lines");
                //$line = 0;
                $ims = new SimpleXMLElement($xml);
                $this->log_line('about to process groups');
                foreach ($ims->xpath('/enterprise/group') as $xml_group) {
                    //$this->log_line('the coursecode for the group should be:  ' . $xml_group->sourcedid->id);
                    $group = $this->process_group_tag($xml_group);
                    //$this->log_line('processed group: ' . (string)$group->shortname);
                }

                $this->log_line('about to process persons');
                foreach ($ims->xpath('/enterprise/person') as $xml_person) {
                    $person = $this->process_person_tag($xml_person);
                    //$this->log_line('processed person: ' . $person->username);
                }

                $this->log_line('about to process course and group memberships');
                foreach ($ims->xpath('/enterprise/membership') as $xml_membership) {
                    $this->process_membership_tag($xml_membership);
                }

                fix_course_sortorder();
                $timeelapsed = time() - $starttime;
                $this->log_line('Process has completed. Time taken: ' . $timeelapsed . ' seconds.');


            } // END of "if file is new"


            // These variables are stored so we can compare them against the IMS file, next time round.
            $this->set_config('prev_time', $filemtime);
            $this->set_config('prev_md5', $md5);
            $this->set_config('prev_path', $filename);


        } else { // end of if(file_exists)
            $this->log_line('File not found: ' . $filename);
        }

        if (!empty($mailadmins)) {
            $msg = "An IMS enrolment has been carried out within Moodle.\nTime taken: $timeelapsed seconds.\n\n";
            if (!empty($logtolocation)) {
                if ($this->logfp) {
                    $msg .= "Log data has been written to:\n";
                    $msg .= "$logtolocation\n";
                    $msg .= "(Log file size: " . ceil(filesize($logtolocation) / 1024) . "Kb)\n\n";
                } else {
                    $msg .= "The log file appears not to have been successfully written.\nCheck that the file is writeable by the server:\n";
                    $msg .= "$logtolocation\n\n";
                }
            } else {
                $msg .= "Logging is currently not active.";
            }

            $eventdata = new stdClass();
            $eventdata->modulename = 'moodle';
            $eventdata->component = 'imsenterprise2';
            $eventdata->name = 'imsenterprise2_enrolment';
            $eventdata->userfrom = get_admin();
            $eventdata->userto = get_admin();
            $eventdata->subject = "Moodle IMS Enterprise enrolment notification";
            $eventdata->fullmessage = $msg;
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml = '';
            $eventdata->smallmessage = '';
            message_send($eventdata);

            $this->log_line('Notification email sent to administrator.');

        }

        if ($this->logfp) {
            fclose($this->logfp);
        }


    } // end of cron() function

    /**
     * Process the group tag. This defines a Moodle course.
     * @param string $tagconents The raw contents of the XML element
     */
    function process_group_tag($xml_group)
    {
        global $DB;
        // Get configs
        $truncatecoursecodes = $this->get_config('truncatecoursecodes');
        $createnewcourses = $this->get_config('createnewcourses');
        $createnewcategories = $this->get_config('createnewcategories');

        // Process tag contents
        $group = new stdClass();
        $group->coursecode = (string)$xml_group->sourcedid->id;
        $group->fullname = (string)$xml_group->description->long;
        $group->shortname = (string)$xml_group->description->short;
        $group->category = (string)$xml_group->org->orgunit;
        if (trim($group->fullname) == '') {
            // This is the point where we can fall back to using the "shorname" if "fullname" is not supplied
            // NB We don't use an "elseif" because the tag may be supplied-but-empty
            $group->fullname = $group->shortname;
        }


        $recstatus = (empty($xml_person['recstatus'])) ? (integer)$xml_group['recstatus'] : 0;
        //echo "<p>get_recstatus for this group returned $recstatus</p>";

        if (!(strlen($group->coursecode) > 0)) {
            $this->log_line('Error at line ' . $xml_group . ': Unable to find course code in \'group\' element.');
        } else {
            // First, truncate the course code if desired
            if (intval($truncatecoursecodes) > 0) {
                $group->coursecode = ($truncatecoursecodes > 0)
                    ? substr($group->coursecode, 0, intval($truncatecoursecodes))
                    : $group->coursecode;
            }

            /* -----------Course aliasing is DEACTIVATED until a more general method is in place---------------

                      // Second, look in the course alias table to see if the code should be translated to something else
                       if($aliases = $DB->get_field('enrol_coursealias', 'toids', array('fromid'=>$group->coursecode))){
                           $this->log_line("Found alias of course code: Translated $group->coursecode to $aliases");
                           // Alias is allowed to be a comma-separated list, so let's split it
                           $group->coursecode = explode(',', $aliases);
                       }
                      */

            // For compatibility with the (currently inactive) course aliasing, we need this to be an array
            $group->coursecode = array($group->coursecode);
            //Does the course code exist.  If not do not create course.
            if ((strlen($group->category) > 0) && $catid = $DB->get_field('course_categories', 'id', array('name' => $group->category))) {
                // Third, check if the course(s) exist
                foreach ($group->coursecode as $coursecode) {
                    $coursecode = trim($coursecode);
                    if (!$DB->get_field('course', 'id', array('idnumber' => $coursecode))) {
                        if (!$createnewcourses) {
                            $this->log_line("Course $coursecode not found in Moodle's course idnumbers.");
                        } else {
                            // Create the (hidden) course(s) if not found
                            $course = new stdClass();
                            $course->fullname = $group->fullname;
                            $course->shortname = $group->shortname;
                            $course->idnumber = $coursecode;
                            $course->format = 'topics';
                            $course->visible = 0;
                            // Insert default names for teachers/students, from the current language
                            $site = get_site();

                            // Handle course categorisation (taken from the group.org.orgunit field if present)
                            if (strlen($group->category) > 0) {
                                // If the category is defined and exists in Moodle, we want to store it in that one
                                if ($catid = $DB->get_field('course_categories', 'id', array('name' => $group->category))) {
                                    $course->category = $catid;
                                } elseif ($createnewcategories) {
                                    // Else if we're allowed to create new categories, let's create this one
                                    $newcat = new stdClass();
                                    $newcat->name = $group->category;
                                    $newcat->visible = 1;
                                    $catid = $DB->insert_record('course_categories', $newcat);
                                    $course->category = $catid;
                                    $this->log_line("Created new (hidden) category, #$catid: $newcat->name");
                                } else {
                                    // If not found and not allowed to create, stick with default
                                    $this->log_line('Category ' . $group->category . ' not found in Moodle database, so using default category instead.');
                                    $course->category = 1;
                                }
                            } else {
                                $course->category = 1;
                            }
                            $course->timecreated = time();
                            $course->startdate = time();
                            $course->numsections = 11;
                            // Choose a sort order that puts us at the start of the list!
                            $course->sortorder = 0;

                            $courseid = $DB->insert_record('course', $course);

                            //DO this externally for now
                            // Setup default enrolment plugins
                            //$course->id = $courseid;
                            //enrol_course_updated(true, $course, null);

                            // Setup the blocks
                            $course = $DB->get_record('course', array('id' => $courseid));
                            blocks_add_default_course_blocks($course);
                            //$this->order_default_course_blocks($course);

                            $section = new stdClass();
                            $section->course = $course->id; // Create a default section.
                            $section->section = 0;
                            $section->summaryformat = FORMAT_HTML;
                            $section->id = $DB->insert_record("course_sections", $section);

                            add_to_log(SITEID, "course", "new", "view.php?id=$course->id", "$course->fullname (ID $course->id)");

                            $this->log_line("Created course $coursecode in Moodle (Moodle ID is $course->id)");
                        }
                    } else {
                        $courseid = $DB->get_field('course', 'id', array('idnumber' => $coursecode));
                        // If course does exist update the course title incase it changed in the upstream system.
                        $DB->set_field('course', 'fullname', $group->fullname, array('id' => $courseid));
                    }
                    if ($recstatus == 3 && ($courseid = $DB->get_field('course', 'id', array('idnumber' => $coursecode)))) {
                        // If course does exist, but recstatus==3 (delete), then set the course as hidden
                        $DB->set_field('course', 'visible', '0', array('id' => $courseid));
                    }
                } // End of foreach(coursecode)
            }
        }
        return $group;
    } // End process_group_tag()


    /**
     * Process the person tag. This defines a Moodle user.
     * @param string $tagconents The raw contents of the XML element
     */
    function process_person_tag($xml_person)
    {
        global $CFG, $DB;

        // Get plugin configs
        $imssourcedidfallback = $this->get_config('imssourcedidfallback');
        $fixcaseusernames = $this->get_config('fixcaseusernames');
        $fixcasepersonalnames = $this->get_config('fixcasepersonalnames');
        $imsdeleteusers = $this->get_config('imsdeleteusers');
        $createnewusers = $this->get_config('createnewusers');

        $person = new stdClass();
        $person->idnumber = trim((string)$xml_person->sourcedid->id);
        $person->firstname = (string)$xml_person->name->n->given;
        $person->lastname = (string)$xml_person->name->n->family;
        $person->username = trim((string)$xml_person->userid);
        if ($imssourcedidfallback && trim($person->username) == '') {
            // This is the point where we can fall back to useing the "sourcedid" if "userid" is not supplied
            // NB We don't use an "elseif" because the tag may be supplied-but-empty
            $person->username = $person->idnumber;
        }

        //$person->email = (string)$xml_person->email;
        ////LOCAL set email address based on username in an attempt to stop crap from FS accounts
        $person->email = $person->username . '@evergreen.edu';

        $person->url = (string)$xml_person->url;
        $person->city = (string)$xml_person->locality;
        $person->country = (string)$xml_person->country;

        // Fix case of some of the fields if required
        if ($fixcaseusernames && isset($person->username)) {
            $person->username = strtolower($person->username);
        }
        if ($fixcasepersonalnames) {
            if (isset($person->firstname)) {
                $person->firstname = ucwords(strtolower($person->firstname));
            }
            if (isset($person->lastname)) {
                $person->lastname = ucwords(strtolower($person->lastname));
            }
        }

        $recstatus = (empty($xml_person['recstatus'])) ? (integer)$xml_person['recstatus'] : 0;


        // Now if the recstatus is 3, we should delete the user if-and-only-if the setting for delete users is turned on
        // In the "users" table we can do this by setting deleted=1
        if ($recstatus == 3) {

            if ($imsdeleteusers) { // If we're allowed to delete user records
                // Make sure their "deleted" field is set to one
                $DB->set_field('user', 'deleted', 1, array('username' => $person->username));
                $this->log_line("Marked user record for user '$person->username' (ID number $person->idnumber) as deleted.");
            } else {
                $this->log_line("Ignoring deletion request for user '$person->username' (ID number $person->idnumber).");
            }

        } else { // Add or update record
            if ($DB->record_exists_select('user', " suspended = 1 AND idnumber = '$person->idnumber'")) {
                $this->log_line("The user for ID # $person->idnumber existed but is suspended.  They will be unsuspended.");
                $description = $DB->get_field('user', 'description', array('idnumber' => $person->idnumber));
                $DB->set_field('user', 'suspended', 0, array('idnumber' => $person->idnumber));
                $DB->set_field('user', 'username', $person->username, array('idnumber' => $person->idnumber));
                $DB->set_field('user', 'email', $person->email, array('idnumber' => $person->idnumber));
                $DB->set_field('user', 'auth', "cas", array('idnumber' => $person->idnumber));
                $DB->set_field('user', 'description', $description . "---UNSUSPENDED via IMS on" . date('Y-m-d:H'), array('idnumber' => $person->idnumber));

            }
            $tst = $DB->get_field('user', 'id', array('idnumber' => $person->idnumber));
            // If the user exists (matching sourcedid) then we don't need to do anything.
            if (!$DB->get_field('user', 'id', array('idnumber' => $person->idnumber)) && $createnewusers) {
                // If they don't exist and haven't a defined username, we log this as a potential problem.
                if ((!isset($person->username)) || (strlen($person->username) == 0)) {
                    $this->log_line("Cannot create new user for ID # $person->idnumber - no username listed in IMS data for this person.");
                } else if ($DB->record_exists_select('user', "username = '$person->username' AND idnumber is null")) {
                    $this->log_line("*****A User record for '$person->username' and ID number $person->idnumber is null.");
                    // If their idnumber is not registered but their user ID is and does not have an idnumber,
                    //then add their idnumber to their record
                    //not fixing anything
                    //$DB->set_field('user', 'idnumber', $person->idnumber, array('username' => $person->username));
                } else if ($DB->record_exists_select('user', "username = '$person->username' AND idnumber != '$person->idnumber'")) {
                    $other_person = $DB->get_record_select('user', "username = '$person->username' AND idnumber != '$person->idnumber'");
                    $this->log_line("******The username is in use and we need to disable the previous account record for '$other_person->username' and ID number $other_person->idnumber as $person->idnumber wants it.");
                    $this->log_line("******Changing the username for '$other_person->username'  to $other_person->username." . time()."of the user with an ID number $other_person->idnumber");
                    $DB->set_field('user', 'suspended', 1, array('username' => $other_person->username));
                    $DB->set_field('user', 'description', "This persons account was deactivated on ".date('Y-m-d:H')." as the idnumber no longer has an active account", array('username' => $other_person->username));
                    $DB->set_field('user', 'username', $other_person->username.time(), array('username' => $other_person->username));

                } else {

                    // If they don't exist and they have a defined username, and $createnewusers == true, we create them.
                    $person->lang = 'manual'; //TODO: this needs more work due tu multiauth changes
                    $person->auth = $CFG->auth;
                    $person->description = 'A person';
                    $person->confirmed = 1;
                    $person->timemodified = time();
                    $person->mnethostid = $CFG->mnet_localhost_id;
                    //$person = $this->local_user_settings($person);
                    $id = $DB->insert_record('user', $person);

                    $this->log_line("Created user record for user '$person->username' (ID number $person->idnumber).");
                }

            } elseif ($createnewusers) {
                //$this->log_line("User record already exists for user '$person->username' (ID number $person->idnumber).");

                // Make sure their "deleted" field is set to zero.
                $DB->set_field('user', 'deleted', 0, array('idnumber' => $person->idnumber));
            } elseif ($DB->get_field('user', 'idnumber', array('username' => $person->username))
                &&
                $DB->get_field('user', 'idnumber', array('username' => $person->username) != $person->idnumber)
            ) {
                $this->log_line("User record already exists for user '$person->username' (ID number $person->idnumber).");

                // Make sure their "deleted" field is set to zero.
                $DB->set_field('user', 'deleted', 0, array('idnumber' => $person->idnumber));
            } else {
                $this->log_line("No user record found for '$person->username' (ID number $person->idnumber).");
            }

        } // End of are-we-deleting-or-adding
        return $person;

    } // End process_person_tag()

    /**
     * Process the membership tag. This defines whether the specified Moodle users
     * should be added/removed as teachers/students.
     * @param string $tagconents The raw contents of the XML element
     */
    function process_membership_tag($xml_membership)
    {
        global $DB;

        // Get plugin configs
        $truncatecoursecodes = $this->get_config('truncatecoursecodes');
        $imscapitafix = $this->get_config('imscapitafix');

        $memberstally = 0;
        $membersuntally = 0;

        // In order to reduce the number of db queries required, group name/id associations are cached in this array:
        $groupids = array();

        $ship = new stdClass();
        $ship->coursecode = ($truncatecoursecodes > 0)
            ? substr(trim((string)$xml_membership->sourcedid->id), 0, intval($truncatecoursecodes))
            : trim((string)$xml_membership->sourcedid->id);
        $ship->courseid = $DB->get_field('course', 'id', array('idnumber' => $ship->coursecode));


        if ($ship->courseid) {
            $courseobj = new stdClass();
            $courseobj->id = $ship->courseid;

            foreach ($xml_membership->member as $xml_member) {
                $memberstoreobj = new stdClass();
                $member = new stdClass();
                $member->idnumber = trim((string)$xml_member->sourcedid->id);
                if ($imscapitafix) {
                    //The XML that comes out of Capita Student Records seems to contain a misinterpretation of the IMS specification!
                    $member->roletype = (string)$xml_member->role->roletype; // 01 means Student, 02 means Instructor, 3 means ContentDeveloper, and there are more besides
                } else {
                    $member->roletype = (string)$xml_member->role['roletype']; // 01 means Student, 02 means Instructor, 3 means ContentDeveloper, and there are more besides
                }

                $member->status = (integer)$xml_member->role->status; // 1 means active, 0 means inactive - treat this as enrol vs unenrol

                $recstatus = $xml_member->role['rectatus'];
                if ($recstatus == 3) {
                    $member->status = 0; // See above - recstatus of 3 (==delete) is treated the same as status of 0
                    //echo "<p>process_membership_tag: unenrolling member due to recstatus of 3</p>";
                }

                $timeframe = new stdClass();
                $timeframe->begin = 0;
                $timeframe->end = 0;
                $timeframe->begin = $this->decode_timeframe((string)$xml_member->role->status->timeframe->begin);
                $timeframe->end = $this->decode_timeframe((string)$xml_member->role->status->timeframe->begin);

                if (!empty($xml_person['recstatus'])) {
                    $member->groupname = (string)$xml_member->extension->cohort;
                    // The actual processing (ensuring a group record exists, etc) occurs below, in the enrol-a-student clause
                }

                $rolecontext = context_course::instance($ship->courseid);
                $rolecontext = $rolecontext->id; // All we really want is the ID


                // Add or remove this student or teacher to the course...
                $memberstoreobj->userid = $DB->get_field('user', 'id', array('idnumber' => $member->idnumber));
                $memberstoreobj->enrol = 'imsenterprise2';
                $memberstoreobj->course = $ship->courseid;
                $memberstoreobj->time = time();
                $memberstoreobj->timemodified = time();

                if ($memberstoreobj->userid) {

                    // Decide the "real" role (i.e. the Moodle role) that this user should be assigned to.
                    // Zero means this roletype is supposed to be skipped.
                    $moodleroleid = $this->rolemappings[$member->roletype];
                    if (!$moodleroleid) {
                        $this->log_line("SKIPPING role $member->roletype for $memberstoreobj->userid ($member->idnumber) in course $memberstoreobj->course");
                        continue;
                    }

                    if (intval($member->status) == 1) {
                        // Enrol the member

                        $einstance = $DB->get_record('enrol',
                            array('courseid' => $courseobj->id, 'enrol' => $memberstoreobj->enrol));
                        if (empty($einstance)) {
                            // Only add an enrol instance to the course if non-existent
                            $enrolid = $this->add_instance($courseobj);
                            $einstance = $DB->get_record('enrol', array('id' => $enrolid));
                        }
                        //$this->log_line("about to enrol_user einstance " . $einstance->id . " , memberstoreobj-userid " . $memberstoreobj->userid . ", moodleroleid " . $moodleroleid . ", begin " . $timeframe->begin . ", end " . $timeframe->end . " to course " . $memberstoreobj->course);
                        $this->enrol_user($einstance, $memberstoreobj->userid, $moodleroleid, $timeframe->begin, $timeframe->end);

                        $this->log_line("Enrolled user #$memberstoreobj->userid ($member->idnumber) to role $member->roletype in course $memberstoreobj->course");
                        $memberstally++;

                        // At this point we can also ensure the group membership is recorded if present
                        if (isset($member->groupname)) {
                            // Create the group if it doesn't exist - either way, make sure we know the group ID
                            if (isset($groupids[$member->groupname])) {
                                $member->groupid = $groupids[$member->groupname]; // Recall the group ID from cache if available
                            } else {
                                if ($groupid = $DB->get_field('groups', 'id', 'name', $member->groupname, array('courseid' => $ship->courseid))) {
                                    $member->groupid = $groupid;
                                    $groupids[$member->groupname] = $groupid; // Store ID in cache
                                } else {
                                    // Attempt to create the group
                                    $group = new stdClass();
                                    $group->name = $member->groupname;
                                    $group->courseid = $ship->courseid;
                                    $group->timecreated = time();
                                    $group->timemodified = time();
                                    $groupid = $DB->insert_record('groups', $group);
                                    $this->log_line('Added a new group for this course: ' . $group->name);
                                    $groupids[$member->groupname] = $groupid; // Store ID in cache
                                    $member->groupid = $groupid;
                                }
                            }
                            // Add the user-to-group association if it doesn't already exist
                            if ($member->groupid) {
                                groups_add_member($member->groupid, $memberstoreobj->userid);
                            }
                        } // End of group-enrolment (from member.role.extension.cohort tag)

                    } elseif ($this->get_config('imsunenrol')) {
                        // Unenrol member

                        $einstances = $DB->get_records('enrol',
                            array('enrol' => $memberstoreobj->enrol, 'courseid' => $courseobj->id));
                        foreach ($einstances as $einstance) {
                            // Unenrol the user from all imsenterprise2 enrolment instances
                            $this->unenrol_user($einstance, $memberstoreobj->userid);
                        }

                        $membersuntally++;
                        $this->log_line("Unenrolled $member->idnumber from role $moodleroleid in course");
                    }

                }
            }
            $this->log_line("Added $memberstally users to course $ship->coursecode");
            if ($membersuntally > 0) {
                $this->log_line("Removed $membersuntally users from course $ship->coursecode");
            }
        }
    } // End process_membership_tag()

    /**
     * Store logging information. This does two things: uses the {@link mtrace()}
     * function to print info to screen/STDOUT, and also writes log to a text file
     * if a path has been specified.
     * @param string $string Text to write (newline will be added automatically)
     */
    function log_line($string)
    {
        mtrace($string);
        if ($this->logfp) {
            fwrite($this->logfp, $string . "\n");
        }
    }

    /**
     * Process the INNER contents of a <timeframe> tag, to return beginning/ending dates.
     */
    function decode_timeframe($string)
    { // Pass me the INNER CONTENTS of a <timeframe> tag - beginning and/or ending is returned, in unix time, zero indicating not specified
        $ret = 0;
        // Explanatory note: The matching will ONLY match if the attribute restrict="1"
        // because otherwise the time markers should be ignored (participation should be
        // allowed outside the period)
        if (preg_match('{(\d\d\d\d)-(\d\d)-(\d\d)}is', $string, $matches)) {
            $ret = mktime(0, 0, 0, $matches[2], $matches[3], $matches[1]);
        }
        return $ret;
    } // End decode_timeframe

    /**
     * Load the role mappings (from the config), so we can easily refer to
     * how an IMS-E role corresponds to a Moodle role
     */
    function load_role_mappings()
    {
        require_once('locallib.php');
        global $DB;

        $imsroles = new imsenterprise2_roles();
        $imsroles = $imsroles->get_imsroles();

        $this->rolemappings = array();
        foreach ($imsroles as $imsrolenum => $imsrolename) {
            $this->rolemappings[$imsrolenum] = $this->rolemappings[$imsrolename] = $this->get_config('imsrolemap' . $imsrolenum);
        }
    }


    /**
     * Gets an array of the user enrolment actions
     *
     * @param course_enrolment_manager $manager
     * @param stdClass $ue A user enrolment object
     * @return array An array of user_enrolment_actions
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue)
    {
        $actions = array();
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol($instance) && has_capability("enrol/imsenterprise2:unenrol", $context)) {
            $url = new moodle_url('/enrol/imsenterprise2/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/delete', ''), get_string('unenrol', 'enrol'), $url, array('class' => 'unenrollink', 'rel' => $ue->id));
        }
        if ($this->allow_manage($instance) && has_capability("enrol/imsenterprise2:manage", $context)) {
            $url = new moodle_url('/enrol/imsenterprise2/editenrolment.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/edit', ''), get_string('edit'), $url, array('class' => 'editenrollink', 'rel' => $ue->id));
        }
        return $actions;
    }


    /**
     * Evergreen specific code
     */

    /**
     * setup the account to use local settings
     * @param $person
     */

    private function local_user_settings($person)
    {
        if (isset($person->username)) {
            $person->email = $person->username . "@evergreen.edu";
        }
        $person->lang = 'en_us';
        return $person;
    }

    /**
     * sets the order of the blocks in the course
     *
     * @param  $course
     * @return void
     */
    private function order_default_course_blocks($course)
    {
        $contextid = get_context_course::instance($course->id)->id;
        global $DB;
        $instances = $DB->get_recordset('block_instances', array('parentcontextid' => $contextid));
        foreach ($instances as $instance) {
            switch ($instance->blockname) {
                case "fac_help";
                    $instance->defaultweight = -6;
                    break;
                case "help";
                    $instance->defaultweight = -6;
                    break;
                case "stu_help";
                    $instance->defaultweight = -5;
                    break;
                case "participants";
                    $instance->defaultweight = -4;
                    break;
                case "messages";
                    $instance->defaultweight = 5;
                    break;
                case "staff";
                    $instance->defaultweight = 6;
                    break;
            }
            $DB->update_record('block_instances', $instance);
        }
        $instances->close();
    }


} // end of class


