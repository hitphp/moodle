<?php
/**
 * Extra library for groups and groupings.
 *
 * @copyright &copy; 2006 The Open University
 * @author J.White AT open.ac.uk, Petr Skoda (skodak)
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package groups
 */

/*
 * INTERNAL FUNCTIONS - to be used by moodle core only
 * require_once $CFG->dirroot.'/group/lib.php' must be used
 */

/**
 * Adds a specified user to a group
 * @param mixed $groupid  The group id or group object
 * @param mixed $userid   The user id or user object
 * @return boolean True if user added successfully or the user is already a
 * member of the group, false otherwise.
 */
function groups_add_member($grouporid, $userorid) {
    global $DB;

    if (is_object($userorid)) {
        $userid = $userorid->id;
        $user   = $userorid;
    } else {
        $userid = $userorid;
        $user = $DB->get_record('user', array('id'=>$userid), '*', MUST_EXIST);
    }

    if (is_object($grouporid)) {
        $groupid = $grouporid->id;
        $group   = $grouporid;
    } else {
        $groupid = $grouporid;
        $group = $DB->get_record('groups', array('id'=>$groupid), '*', MUST_EXIST);
    }

    //check if the user a participant of the group course
    if (!is_course_participant ($userid, $group->courseid)) {
        return false;
    }

    if (groups_is_member($groupid, $userid)) {
        return true;
    }

    $member = new object();
    $member->groupid   = $groupid;
    $member->userid    = $userid;
    $member->timeadded = time();

    $DB->insert_record('groups_members', $member);

    //update group info
    $DB->set_field('groups', 'timemodified', $member->timeadded, array('id'=>$groupid));

    //trigger groups events
    $eventdata = new object();
    $eventdata->groupid = $groupid;
    $eventdata->userid  = $userid;
    events_trigger('groups_member_added', $eventdata);

    return true;
}

/**
 * Deletes the link between the specified user and group.
 * @param mixed $groupid  The group id or group object
 * @param mixed $userid   The user id or user object
 * @return boolean True if deletion was successful, false otherwise
 */
function groups_remove_member($grouporid, $userorid) {
    global $DB;

    if (is_object($userorid)) {
        $userid = $userorid->id;
        $user   = $userorid;
    } else {
        $userid = $userorid;
        $user = $DB->get_record('user', array('id'=>$userid), '*', MUST_EXIST);
    }

    if (is_object($grouporid)) {
        $groupid = $grouporid->id;
        $group   = $grouporid;
    } else {
        $groupid = $grouporid;
        $group = $DB->get_record('groups', array('id'=>$groupid), '*', MUST_EXIST);
    }

    if (!groups_is_member($groupid, $userid)) {
        return true;
    }

    $DB->delete_records('groups_members', array('groupid'=>$groupid, 'userid'=>$userid));

    //update group info
    $DB->set_field('groups', 'timemodified', time(), array('id'=>$groupid));

    //trigger groups events
    $eventdata = new object();
    $eventdata->groupid = $groupid;
    $eventdata->userid  = $userid;
    events_trigger('groups_member_removed', $eventdata);

    return true;
}

/**
 * Add a new group
 * @param object $data group properties (with magic quotes)
 * @param object $um upload manager with group picture
 * @return id of group or false if error
 */
function groups_create_group($data, $editform=false, $editoroptions=null) {
    global $CFG, $DB;
    require_once("$CFG->libdir/gdlib.php");

    //check that courseid exists
    $course = $DB->get_record('course', array('id' => $data->courseid), '*', MUST_EXIST);

    $data->timecreated  = time();
    $data->timemodified = $data->timecreated;
    $data->name         = trim($data->name);

    if ($editform) {
        $data->description = $data->description_editor['text'];
        $data->descriptionformat = $data->description_editor['format'];
    }

    $id = $DB->insert_record('groups', $data);

    $data->id = $id;
    if ($editform) {
        //update image
        if (save_profile_image($id, $editform, 'groups')) {
            $DB->set_field('groups', 'picture', 1, array('id'=>$id));
        }
        $data->picture = 1;

        if (method_exists($editform, 'get_editor_options')) {
            // Update description from editor with fixed files
            $editoroptions = $editform->get_editor_options();
            $description = new stdClass;
            $description->id = $data->id;
            $description->description_editor = $data->description_editor;
            $description = file_postupdate_standard_editor($description, 'description', $editoroptions, $editoroptions['context'], 'course_group_description', $description->id);
            $DB->update_record('groups', $description);
        }
    }

    //trigger groups events
    events_trigger('groups_group_created', $data);

    return $id;
}

/**
 * Add a new grouping
 * @param object $data grouping properties (with magic quotes)
 * @return id of grouping or false if error
 */
function groups_create_grouping($data, $editoroptions=null) {
    global $DB;

    $data->timecreated  = time();
    $data->timemodified = $data->timecreated;
    $data->name         = trim($data->name);

    if ($editoroptions !== null) {
        $data->description = $data->description_editor['text'];
        $data->descriptionformat = $data->description_editor['format'];
    }

    $id = $DB->insert_record('groupings', $data);

    //trigger groups events
    $data->id = $id;

    if ($editoroptions !== null) {
        $description = new stdClass;
        $description->id = $data->id;
        $description->description_editor = $data->description_editor;
        $description = file_postupdate_standard_editor($description, 'description', $editoroptions, $editoroptions['context'], 'course_grouping_description', $description->id);
        $DB->update_record('groupings', $description);
    }

    events_trigger('groups_grouping_created', $data);

    return $id;
}

/**
 * Update group
 * @param object $data group properties (with magic quotes)
 * @param object $um upload manager with group picture
 * @return boolean true or exception
 */
function groups_update_group($data, $editform=false) {
    global $CFG, $DB;
    require_once("$CFG->libdir/gdlib.php");

    $data->timemodified = time();
    $data->name         = trim($data->name);

    if ($editform && method_exists($editform, 'get_editor_options')) {
        $editoroptions = $editform->get_editor_options();
        $data = file_postupdate_standard_editor($data, 'description', $editoroptions, $editoroptions['context'], 'course_group_description', $data->id);
    }

    $DB->update_record('groups', $data);

    if ($editform) {
        //update image
        if (save_profile_image($data->id, $editform, 'groups')) {
        $DB->set_field('groups', 'picture', 1, array('id'=>$data->id));
            $data->picture = 1;
        }
    }

    //trigger groups events
    events_trigger('groups_group_updated', $data);

    return true;
}

/**
 * Update grouping
 * @param object $data grouping properties (with magic quotes)
 * @return boolean true or exception
 */
function groups_update_grouping($data, $editoroptions=null) {
    global $DB;
    $data->timemodified = time();
    $data->name         = trim($data->name);
    if ($editoroptions !== null) {
        $data = file_postupdate_standard_editor($data, 'description', $editoroptions, $editoroptions['context'], 'course_grouping_description', $data->id);
    }
    $DB->update_record('groupings', $data);
    //trigger groups events
    events_trigger('groups_grouping_updated', $data);

    return true;
}

/**
 * Delete a group best effort, first removing members and links with courses and groupings.
 * Removes group avatar too.
 * @param mixed $grouporid The id of group to delete or full group object
 * @return boolean True if deletion was successful, false otherwise
 */
function groups_delete_group($grouporid) {
    global $CFG, $DB;
    require_once("$CFG->libdir/gdlib.php");

    if (is_object($grouporid)) {
        $groupid = $grouporid->id;
        $group   = $grouporid;
    } else {
        $groupid = $grouporid;
        if (!$group = $DB->get_record('groups', array('id'=>$groupid))) {
            //silently ignore attempts to delete missing already deleted groups ;-)
            return true;
        }
    }

    // delete group calendar events
    $DB->delete_records('event', array('groupid'=>$groupid));
    //first delete usage in groupings_groups
    $DB->delete_records('groupings_groups', array('groupid'=>$groupid));
    //delete members
    $DB->delete_records('groups_members', array('groupid'=>$groupid));
    //then imge
    delete_profile_image($groupid, 'groups');
    //group itself last
    $DB->delete_records('groups', array('id'=>$groupid));

    // Delete all files associated with this group
    $context = get_context_instance(CONTEXT_COURSE, $group->courseid);
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'course_group_description', $groupid);
    foreach ($files as $file) {
        $file->delete();
    }

    //trigger groups events
    events_trigger('groups_group_deleted', $group);

    return true;
}

/**
 * Delete grouping
 * @param int $groupingid
 * @return bool success
 */
function groups_delete_grouping($groupingorid) {
    global $DB;

    if (is_object($groupingorid)) {
        $groupingid = $groupingorid->id;
        $grouping   = $groupingorid;
    } else {
        $groupingid = $groupingorid;
        if (!$grouping = $DB->get_record('groupings', array('id'=>$groupingorid))) {
            //silently ignore attempts to delete missing already deleted groupings ;-)
            return true;
        }
    }

    //first delete usage in groupings_groups
    $DB->delete_records('groupings_groups', array('groupingid'=>$groupingid));
    // remove the default groupingid from course
    $DB->set_field('course', 'defaultgroupingid', 0, array('defaultgroupingid'=>$groupingid));
    // remove the groupingid from all course modules
    $DB->set_field('course_modules', 'groupingid', 0, array('groupingid'=>$groupingid));
    //group itself last
    $DB->delete_records('groupings', array('id'=>$groupingid));

    $context = get_context_instance(CONTEXT_COURSE, $grouping->courseid);
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'course_grouping_description', $groupingid);
    foreach ($files as $file) {
        $file->delete();
    }

    //trigger groups events
    events_trigger('groups_grouping_deleted', $grouping);

    return true;
}

/**
 * Remove all users (or one user) from all groups in course
 * @param int $courseid
 * @param int $userid 0 means all users
 * @param bool $showfeedback
 * @return bool success
 */
function groups_delete_group_members($courseid, $userid=0, $showfeedback=false) {
    global $DB, $OUTPUT;

    if (is_bool($userid)) {
        debugging('Incorrect userid function parameter');
        return false;
    }

    $params = array('courseid'=>$courseid);

    if ($userid) {
        $usersql = "AND userid = :userid";
        $params['userid'] = $userid;
    } else {
        $usersql = "";
    }

    $groupssql = "SELECT id FROM {groups} g WHERE g.courseid = :courseid";
    $DB->delete_records_select('groups_members', "groupid IN ($groupssql) $usersql", $params);

    //trigger groups events
    $eventdata = new object();
    $eventdata->courseid = $courseid;
    $eventdata->userid   = $userid;
    events_trigger('groups_members_removed', $eventdata);

    if ($showfeedback) {
        echo $OUTPUT->notification(get_string('deleted').' groups_members');
    }

    return true;
}

/**
 * Remove all groups from all groupings in course
 * @param int $courseid
 * @param bool $showfeedback
 * @return bool success
 */
function groups_delete_groupings_groups($courseid, $showfeedback=false) {
    global $DB, $OUTPUT;

    $groupssql = "SELECT id FROM {groups} g WHERE g.courseid = ?";
    $DB->delete_records_select('groupings_groups', "groupid IN ($groupssql)", array($courseid));

    // Delete all files associated with groupings for this course
    $context = get_context_instance(CONTEXT_COURSE, $courseid);
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'course_group_description');

    //trigger groups events
    events_trigger('groups_groupings_groups_removed', $courseid);

    if ($showfeedback) {
        echo $OUTPUT->notification(get_string('deleted').' groupings_groups');
    }

    return true;
}

/**
 * Delete all groups from course
 * @param int $courseid
 * @param bool $showfeedback
 * @return bool success
 */
function groups_delete_groups($courseid, $showfeedback=false) {
    global $CFG, $DB, $OUTPUT;
    require_once($CFG->libdir.'/gdlib.php');

    // delete any uses of groups
    // Any associated files are deleted as part of groups_delete_groupings_groups
    groups_delete_groupings_groups($courseid, $showfeedback);
    groups_delete_group_members($courseid, 0, $showfeedback);

    // delete group pictures
    if ($groups = $DB->get_records('groups', array('courseid'=>$courseid))) {
        foreach($groups as $group) {
            delete_profile_image($group->id, 'groups');
        }
    }

    // delete group calendar events
    $groupssql = "SELECT id FROM {groups} g WHERE g.courseid = ?";
    $DB->delete_records_select('event', "groupid IN ($groupssql)", array($courseid));

    $DB->delete_records('groups', array('courseid'=>$courseid));

    //trigger groups events
    events_trigger('groups_groups_deleted', $courseid);

    if ($showfeedback) {
        echo $OUTPUT->notification(get_string('deleted').' groups');
    }

    return true;
}

/**
 * Delete all groupings from course
 * @param int $courseid
 * @param bool $showfeedback
 * @return bool success
 */
function groups_delete_groupings($courseid, $showfeedback=false) {
    global $DB, $OUTPUT;

    // delete any uses of groupings
    $sql = "DELETE FROM {groupings_groups}
             WHERE groupingid in (SELECT id FROM {groupings} g WHERE g.courseid = ?)";
    $DB->execute($sql, array($courseid));

    // remove the default groupingid from course
    $DB->set_field('course', 'defaultgroupingid', 0, array('id'=>$courseid));
    // remove the groupingid from all course modules
    $DB->set_field('course_modules', 'groupingid', 0, array('course'=>$courseid));

    $DB->delete_records('groupings', array('courseid'=>$courseid));

    // Delete all files associated with groupings for this course
    $context = get_context_instance(CONTEXT_COURSE, $courseid);
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'course_grouping_description');

    //trigger groups events
    events_trigger('groups_groupings_deleted', $courseid);

    if ($showfeedback) {
        echo $OUTPUT->notification(get_string('deleted').' groupings');
    }

    return true;
}

/* =================================== */
/* various functions used by groups UI */
/* =================================== */

/**
 * Obtains a list of the possible roles that group members might come from,
 * on a course. Generally this includes all the roles who would have
 * course:view on that course, except the doanything roles.
 * @param object $context Context of course
 * @return Array of role ID integers, or false if error/none.
 */
function groups_get_possible_roles($context) {
    $capability = 'moodle/course:view';
    $doanything = false;

    // find all possible "student" roles
    if ($possibleroles = get_roles_with_capability($capability, CAP_ALLOW, $context)) {
        if (!$doanything) {
            if (!$sitecontext = get_context_instance(CONTEXT_SYSTEM)) {
                return false;    // Something is seriously wrong
            }
            $doanythingroles = get_roles_with_capability('moodle/site:doanything', CAP_ALLOW, $sitecontext);
        }

        $validroleids = array();
        foreach ($possibleroles as $possiblerole) {
            if (!$doanything) {
                if (isset($doanythingroles[$possiblerole->id])) {  // We don't want these included
                    continue;
                }
            }
            if ($caps = role_context_capabilities($possiblerole->id, $context, $capability)) { // resolved list
                if (isset($caps[$capability]) && $caps[$capability] > 0) { // resolved capability > 0
                    $validroleids[] = $possiblerole->id;
                }
            }
        }
        if (empty($validroleids)) {
            return false;
        }
        return $validroleids;
    } else {
        return false;  // No need to continue, since no roles have this capability set
    }
}


/**
 * Gets potential group members for grouping
 * @param int $courseid The id of the course
 * @param int $roleid The role to select users from
 * @param string $orderby The colum to sort users by
 * @return array An array of the users
 */
function groups_get_potential_members($courseid, $roleid = null, $orderby = 'lastname,firstname') {
    global $DB;

    $context = get_context_instance(CONTEXT_COURSE, $courseid);
    $sitecontext = get_context_instance(CONTEXT_SYSTEM);
    $rolenames = array();
    $avoidroles = array();

    if ($roles = get_roles_used_in_context($context, true)) {

        $canviewroles    = get_roles_with_capability('moodle/course:view', CAP_ALLOW, $context);
        $doanythingroles = get_roles_with_capability('moodle/site:doanything', CAP_ALLOW, $sitecontext);

        foreach ($roles as $role) {
            if (!isset($canviewroles[$role->id])) {   // Avoid this role (eg course creator)
                $avoidroles[] = $role->id;
                unset($roles[$role->id]);
                continue;
            }
            if (isset($doanythingroles[$role->id])) {   // Avoid this role (ie admin)
                $avoidroles[] = $role->id;
                unset($roles[$role->id]);
                continue;
            }
            $rolenames[$role->id] = strip_tags(role_get_name($role, $context));   // Used in menus etc later on
        }
    }

    if ($avoidroles) {
        list($adminroles, $params) = $DB->get_in_or_equal($avoidroles, SQL_PARAMS_NAMED, 'ar0', false);
        $adminroles = "AND r.roleid $adminroles";
    } else {
        $adminroles = "";
        $params = array();
    }

    // we are looking for all users with this role assigned in this context or higher
    if ($usercontexts = get_parent_contexts($context)) {
        $listofcontexts = 'IN ('.implode(',', $usercontexts).')';
    } else {
        $listofcontexts = '='.$sitecontext->id.')'; // must be site
    }

    if ($roleid) {
        $selectrole = "AND r.roleid = :roleid";
        $params['roleid'] = $roleid;
    } else {
        $selectrole = "";
    }

    $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.idnumber
              FROM {user} u
              JOIN {role_assignments} r on u.id=r.userid
             WHERE (r.contextid = :contextid OR r.contextid $listofcontexts)
                   AND u.deleted = 0 AND u.username != 'guest'
                   $selectrole $adminroles
          ORDER BY $orderby";
    $params['contextid'] = $context->id;

    return $DB->get_records_sql($sql, $params);

}

/**
 * Parse a group name for characters to replace
 * @param string $format The format a group name will follow
 * @param int $groupnumber The number of the group to be used in the parsed format string
 * @return string the parsed format string
 */
function groups_parse_name($format, $groupnumber) {
    if (strstr($format, '@') !== false) { // Convert $groupnumber to a character series
        $letter = 'A';
        for($i=0; $i<$groupnumber; $i++) {
            $letter++;
        }
        $str = str_replace('@', $letter, $format);
    } else {
        $str = str_replace('#', $groupnumber+1, $format);
    }
    return($str);
}

/**
 * Assigns group into grouping
 * @param int groupingid
 * @param int groupid
 * @return bool true or exception
 */
function groups_assign_grouping($groupingid, $groupid) {
    global $DB;

    if ($DB->record_exists('groupings_groups', array('groupingid'=>$groupingid, 'groupid'=>$groupid))) {
        return true;
    }
    $assign = new object();
    $assign->groupingid = $groupingid;
    $assign->groupid    = $groupid;
    $assign->timeadded  = time();
    $DB->insert_record('groupings_groups', $assign);

    return true;
}

/**
 * Unassigns group grom grouping
 * @param int groupingid
 * @param int groupid
 * @return bool success
 */
function groups_unassign_grouping($groupingid, $groupid) {
    global $DB;
    $DB->delete_records('groupings_groups', array('groupingid'=>$groupingid, 'groupid'=>$groupid));

    return true;
}

/**
 * Lists users in a group based on their role on the course.
 * Returns false if there's an error or there are no users in the group.
 * Otherwise returns an array of role ID => role data, where role data includes:
 * (role) $id, $shortname, $name
 * $users: array of objects for each user which include the specified fields
 * Users who do not have a role are stored in the returned array with key '-'
 * and pseudo-role details (including a name, 'No role'). Users with multiple
 * roles, same deal with key '*' and name 'Multiple roles'. You can find out
 * which roles each has by looking in the $roles array of the user object.
 * @param int $groupid
 * @param int $courseid Course ID (should match the group's course)
 * @param string $fields List of fields from user table prefixed with u, default 'u.*'
 * @param string $sort SQL ORDER BY clause, default 'u.lastname ASC'
 * @param string $extrawheretest extra SQL conditions ANDed with the existing where clause.
 * @param array $whereparams any parameters required by $extrawheretest.
 * @return array Complex array as described above
 */
function groups_get_members_by_role($groupid, $courseid, $fields='u.*',
        $sort='u.lastname ASC', $extrawheretest='', $whereparams=array()) {
    global $CFG, $DB;

    // Retrieve information about all users and their roles on the course or
    // parent ('related') contexts
    $context = get_context_instance(CONTEXT_COURSE, $courseid);

    if ($extrawheretest) {
        $extrawheretest = ' AND ' . $extrawheretest;
    }

    $sql = "SELECT r.id AS roleid, r.shortname AS roleshortname, r.name AS rolename,
                   u.id AS userid, $fields
              FROM {groups_members} gm
              JOIN {user} u ON u.id = gm.userid
              JOIN {role_assignments} ra ON ra.userid = u.id
              JOIN {role} r ON r.id = ra.roleid
             WHERE gm.groupid=?
                   AND ra.contextid ".get_related_contexts_string($context).
                   $extrawheretest."
          ORDER BY r.sortorder, $sort";
    array_unshift($whereparams, $groupid);
    $rs = $DB->get_recordset_sql($sql, $whereparams);

    return groups_calculate_role_people($rs, $context);
}

/**
 * Internal function used by groups_get_members_by_role to handle the
 * results of a database query that includes a list of users and possible
 * roles on a course.
 *
 * @param object $rs The record set (may be false)
 * @param int $contextid ID of course context
 * @return array As described in groups_get_members_by_role
 */
function groups_calculate_role_people($rs, $context) {
    global $CFG, $DB;

    if (!$rs) {
        return array();
    }

    $roles = $DB->get_records_menu('role', null, 'name', 'id, name');
    $aliasnames = role_fix_names($roles, $context);

    // Array of all involved roles
    $roles = array();
    // Array of all retrieved users
    $users = array();
    // Fill arrays
    foreach ($rs as $rec) {
        // Create information about user if this is a new one
        if (!array_key_exists($rec->userid, $users)) {
            // User data includes all the optional fields, but not any of the
            // stuff we added to get the role details
            $userdata=clone($rec);
            unset($userdata->roleid);
            unset($userdata->roleshortname);
            unset($userdata->rolename);
            unset($userdata->userid);
            $userdata->id = $rec->userid;

            // Make an array to hold the list of roles for this user
            $userdata->roles = array();
            $users[$rec->userid] = $userdata;
        }
        // If user has a role...
        if (!is_null($rec->roleid)) {
            // Create information about role if this is a new one
            if (!array_key_exists($rec->roleid,$roles)) {
                $roledata = new object();
                $roledata->id        = $rec->roleid;
                $roledata->shortname = $rec->roleshortname;
                if (array_key_exists($rec->roleid, $aliasnames)) {
                    $roledata->name = $aliasnames[$rec->roleid];
                } else {
                    $roledata->name = $rec->rolename;
                }
                $roledata->users = array();
                $roles[$roledata->id] = $roledata;
            }
            // Record that user has role
            $users[$rec->userid]->roles[] = $roles[$rec->roleid];
        }
    }
    $rs->close();

    // Return false if there weren't any users
    if (count($users)==0) {
        return false;
    }

    // Add pseudo-role for multiple roles
    $roledata = new object();
    $roledata->name = get_string('multipleroles','role');
    $roledata->users = array();
    $roles['*'] = $roledata;

    // Now we rearrange the data to store users by role
    foreach ($users as $userid=>$userdata) {
        $rolecount = count($userdata->roles);
        if ($rolecount==0) {
            debugging("Unexpected: user $userid is missing roles");
        } else if($rolecount>1) {
            $roleid = '*';
        } else {
            $roleid = $userdata->roles[0]->id;
        }
        $roles[$roleid]->users[$userid] = $userdata;
    }

    // Delete roles not used
    foreach ($roles as $key=>$roledata) {
        if (count($roledata->users)===0) {
            unset($roles[$key]);
        }
    }

    // Return list of roles containing their users
    return $roles;
}