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
 * Add/remove group from grouping.
 *
 * @copyright 1999 Martin Dougiamas  http://dougiamas.com
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @package groups
 */

require_once('../config.php');
require_once('lib.php');

$groupingid = required_param('id', PARAM_INT);

$PAGE->set_url('/group/assign.php', array('id'=>$groupingid));

if (!$grouping = $DB->get_record('groupings', array('id'=>$groupingid))) {
    print_error('invalidgroupid');
}

if (!$course = $DB->get_record('course', array('id'=>$grouping->courseid))) {
    print_error('invalidcourse');
}
$courseid = $course->id;

require_login($course);
$context = get_context_instance(CONTEXT_COURSE, $courseid);
require_capability('moodle/course:managegroups', $context);

$returnurl = $CFG->wwwroot.'/group/groupings.php?id='.$courseid;


if ($frm = data_submitted() and confirm_sesskey()) {

    if (isset($frm->cancel)) {
        redirect($returnurl);

    } else if (isset($frm->add) and !empty($frm->addselect)) {
        foreach ($frm->addselect as $groupid) {
            groups_assign_grouping($grouping->id, (int)$groupid);
        }

    } else if (isset($frm->remove) and !empty($frm->removeselect)) {
        foreach ($frm->removeselect as $groupid) {
            groups_unassign_grouping($grouping->id, (int)$groupid);
        }
    }
}


$currentmembers = array();
$potentialmembers  = array();

if ($groups = $DB->get_records('groups', array('courseid'=>$courseid), 'name')) {
    if ($assignment = $DB->get_records('groupings_groups', array('groupingid'=>$grouping->id))) {
        foreach ($assignment as $ass) {
            $currentmembers[$ass->groupid] = $groups[$ass->groupid];
            unset($groups[$ass->groupid]);
        }
    }
    $potentialmembers = $groups;
}

$currentmembersoptions = '';
$currentmemberscount = 0;
if ($currentmembers) {
    foreach($currentmembers as $group) {
        $currentmembersoptions .= '<option value="'.$group->id.'.">'.format_string($group->name).'</option>';
        $currentmemberscount ++;
    }

    // Get course managers so they can be hilited in the list
    if ($managerroles = get_config('', 'coursemanager')) {
        $coursemanagerroles = split(',', $managerroles);
        foreach ($coursemanagerroles as $roleid) {
            $role = $DB->get_record('role', array('id'=>$roleid));
            $canseehidden = has_capability('moodle/role:viewhiddenassigns', $context);
            $managers = get_role_users($roleid, $context, true, 'u.id', 'u.id ASC', $canseehidden);
        }
    }
} else {
    $currentmembersoptions .= '<option>&nbsp;</option>';
}

$potentialmembersoptions = '';
$potentialmemberscount = 0;
if ($potentialmembers) {
    foreach($potentialmembers as $group) {
        $potentialmembersoptions .= '<option value="'.$group->id.'.">'.format_string($group->name).'</option>';
        $potentialmemberscount ++;
    }
} else {
    $potentialmembersoptions .= '<option>&nbsp;</option>';
}

// Print the page and form
$strgroups = get_string('groups');
$strparticipants = get_string('participants');
$straddgroupstogroupings = get_string('addgroupstogroupings', 'group');

$groupingname = format_string($grouping->name);

$PAGE->navbar->add($strparticipants, new moodle_url('/user/index.php', array('id'=>$courseid)));
$PAGE->navbar->add($strgroups, new moodle_url('/group/index.php', array('id'=>$courseid)));
$PAGE->navbar->add($straddgroupstogroupings);

/// Print header
$PAGE->set_title("$course->shortname: $strgroups");
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

?>
<div id="addmembersform">
    <h3 class="main"><?php print_string('addgroupstogroupings', 'group'); echo ": $groupingname"; ?></h3>

    <form id="assignform" method="post" action="">
    <div>
    <input type="hidden" name="sesskey" value="<?php p(sesskey()); ?>" />

    <table summary="" cellpadding="5" cellspacing="0">
    <tr>
      <td valign="top">
          <label for="removeselect"><?php print_string('existingmembers', 'group', $currentmemberscount); ?></label>
          <br />
          <select name="removeselect[]" size="20" id="removeselect" multiple="multiple"
                  onfocus="document.getElementById('assignform').add.disabled=true;
                           document.getElementById('assignform').remove.disabled=false;
                           document.getElementById('assignform').addselect.selectedIndex=-1;">
          <?php echo $currentmembersoptions ?>
          </select></td>
      <td valign="top">

        <p class="arrow_button">
            <input name="add" id="add" type="submit" value="<?php echo '&nbsp;'.$OUTPUT->larrow().' &nbsp; &nbsp; '.get_string('add'); ?>" title="<?php print_string('add'); ?>" />
            <br />
            <input name="remove" id="remove" type="submit" value="<?php echo '&nbsp; '.$OUTPUT->rarrow().' &nbsp; &nbsp; '.get_string('remove'); ?>" title="<?php print_string('remove'); ?>" />
        </p>
      </td>
      <td valign="top">
          <label for="addselect"><?php print_string('potentialmembers', 'group', $potentialmemberscount); ?></label>
          <br />
          <select name="addselect[]" size="20" id="addselect" multiple="multiple"
                  onfocus="document.getElementById('assignform').add.disabled=false;
                           document.getElementById('assignform').remove.disabled=true;
                           document.getElementById('assignform').removeselect.selectedIndex=-1;">
         <?php echo $potentialmembersoptions ?>
         </select>
         <br />
       </td>
    </tr>
    <tr><td>
        <input type="submit" name="cancel" value="<?php print_string('backtogroupings', 'group'); ?>" />
    </td></tr>
    </table>
    </div>
    </form>
</div>

<?php
    echo $OUTPUT->footer();