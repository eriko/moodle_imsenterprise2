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
 * Strings for component 'enrol_imsenterprise', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package    enrol
 * @subpackage imsenterprise
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['allowunenrol'] = 'Allow the IMS data to <strong>unenroll</strong> students/teachers';
$string['allowunenrol_desc'] = 'If enabled, course enrollments will be removed when specified in the Enterprise data.';

$string['createnewcourses_desc'] = 'If enabled, the IMS Enterprise enrollment plugin can create new courses for any it finds in the IMS data but not in Moodle\'s database. Any newly-created courses are initially hidden.';
$string['createnewusers_desc'] = 'IMS Enterprise enrollment data typically describes a set of users. If enabled, accounts can be created for any users not found in Moodle\'s database.

Users are searched for first by their "idnumber", and second by their Moodle username. Passwords are not imported by the IMS Enterprise plugin. The use of an authentication plugin is recommended for authenticating users.';

$string['deleteusers_desc'] = 'If enabled, IMS Enterprise enrollment data can specify the deletion of user accounts (if the "recstatus" flag is set to 3, which represents deletion of an account). As is standard in Moodle, the user record isn\'t actually deleted from Moodle\'s database, but a flag is set to mark the account as deleted.';

$string['filelockedmail'] = 'The text file you are using for IMS-file-based enrollments ({$a}) can not be deleted by the cron process.  This usually means the permissions are wrong on it.  Please fix the permissions so that Moodle can delete the file, otherwise it might be processed repeatedly.';
$string['filelockedmailsubject'] = 'Important error: Enrollment file';


$string['imsenterprise2:config'] = 'Configure imsenterprise instances';
$string['imsenterprise2:enrol'] = 'Enroll with IMS';
$string['imsenterprise2:manage'] = 'Manage an IMS enrollment';
$string['imsenterprise2:unenrol'] = 'Unenroll an IMS instance';
$string['imsenterprise2:unenrolself'] = 'Unenroll self in IMS instance';

$string['unenrol'] = 'Unenroll user';
$string['unenrolselectedusers'] = 'Unenroll selected users';
$string['unenrolselfconfirm'] = 'Do you really want to unenroll yourself from course "{$a}"? <strong>This will not unenroll you at registration.  Please go to Registration and Records to do that.</strong>';

$string['unenroluser'] = 'Do you really want to unenroll "{$a->user}" from course "{$a->course}"?';
$string['unenrolusers'] = 'Unenroll users';

$string['editenrolment'] = 'Edit enrollment';
$string['editselectedusers'] = 'Edit selected user enrollments';
$string['enrolledincourserole'] = 'Enrolled in "{$a->course}" as "{$a->role}"';

$string['erroreditenrolment'] = 'Error in edit enrollment';