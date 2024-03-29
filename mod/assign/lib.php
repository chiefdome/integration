<?PHP
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
 * This file contains the moodle hooks for the assign module.
 * It delegates most functions to the assignment class.
 *
 * @package   mod_assign
 * @copyright 2012 NetSpot {@link http://www.netspot.com.au}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Adds an assignment instance
 *
 * This is done by calling the add_instance() method of the assignment type class
 * @param stdClass $data
 * @param mod_assign_mod_form $form
 * @return int The instance id of the new assignment
 */
function assign_add_instance(stdClass $data, mod_assign_mod_form $form = null) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/assign/locallib.php');

    $assignment = new assign(context_module::instance($data->coursemodule), null, null);
    return $assignment->add_instance($data, true);
}

/**
 * delete an assignment instance
 * @param int $id
 * @return bool
 */
function assign_delete_instance($id) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/assign/locallib.php');
    $cm = get_coursemodule_from_instance('assign', $id, 0, false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    $assignment = new assign($context, null, null);
    return $assignment->delete_instance();
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all assignment submissions and feedbacks in the database
 * and clean up any related data.
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function assign_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/assign/locallib.php');

    $status = array();
    $params = array('courseid'=>$data->courseid);
    $sql = "SELECT a.id FROM {assign} a WHERE a.course=:courseid";
    $course = $DB->get_record('course', array('id'=>$data->courseid), '*', MUST_EXIST);
    if ($assigns = $DB->get_records_sql($sql, $params)) {
        foreach ($assigns as $assign) {
            $cm = get_coursemodule_from_instance('assign',
                                                 $assign->id,
                                                 $data->courseid,
                                                 false,
                                                 MUST_EXIST);
            $context = context_module::instance($cm->id);
            $assignment = new assign($context, $cm, $course);
            $status = array_merge($status, $assignment->reset_userdata($data));
        }
    }
    return $status;
}

/**
 * Removes all grades from gradebook
 *
 * @param int $courseid The ID of the course to reset
 * @param string $type Optional type of assignment to limit the reset to a particular assignment type
 */
function assign_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $params = array('moduletype'=>'assign', 'courseid'=>$courseid);
    $sql = 'SELECT a.*, cm.idnumber as cmidnumber, a.course as courseid
            FROM {assign} a, {course_modules} cm, {modules} m
            WHERE m.name=:moduletype AND m.id=cm.module AND cm.instance=a.id AND a.course=:courseid';

    if ($assignments = $DB->get_records_sql($sql, $params)) {
        foreach ($assignments as $assignment) {
            assign_grade_item_update($assignment, 'reset');
        }
    }
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the assignment.
 * @param $mform form passed by reference
 */
function assign_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'assignheader', get_string('modulenameplural', 'assign'));
    $name = get_string('deleteallsubmissions', 'assign');
    $mform->addElement('advcheckbox', 'reset_assign_submissions', $name);
}

/**
 * Course reset form defaults.
 * @param  object $course
 * @return array
 */
function assign_reset_course_form_defaults($course) {
    return array('reset_assign_submissions'=>1);
}

/**
 * Update an assignment instance
 *
 * This is done by calling the update_instance() method of the assignment type class
 * @param stdClass $data
 * @param mod_assign_mod_form $form
 * @return object
 */
function assign_update_instance(stdClass $data, mod_assign_mod_form $form) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/assign/locallib.php');
    $context = context_module::instance($data->coursemodule);
    $assignment = new assign($context, null, null);
    return $assignment->update_instance($data);
}

/**
 * Return the list if Moodle features this module supports
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, null if doesn't know
 */
function assign_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        case FEATURE_ADVANCED_GRADING:        return true;
        case FEATURE_PLAGIARISM:              return true;

        default: return null;
    }
}

/**
 * Lists all gradable areas for the advanced grading methods gramework
 *
 * @return array('string'=>'string') An array with area names as keys and descriptions as values
 */
function assign_grading_areas_list() {
    return array('submissions'=>get_string('submissions', 'assign'));
}


/**
 * extend an assigment navigation settings
 *
 * @param settings_navigation $settings
 * @param navigation_node $navref
 * @return void
 */
function assign_extend_settings_navigation(settings_navigation $settings, navigation_node $navref) {
    global $PAGE, $DB;

    $cm = $PAGE->cm;
    if (!$cm) {
        return;
    }

    $context = $cm->context;
    $course = $PAGE->course;

    if (!$course) {
        return;
    }

    // Link to gradebook.
    if (has_capability('gradereport/grader:view', $cm->context) &&
            has_capability('moodle/grade:viewall', $cm->context)) {
        $link = new moodle_url('/grade/report/grader/index.php', array('id' => $course->id));
        $linkname = get_string('viewgradebook', 'assign');
        $node = $navref->add($linkname, $link, navigation_node::TYPE_SETTING);
    }

    // Link to download all submissions.
    if (has_capability('mod/assign:grade', $context)) {
        $link = new moodle_url('/mod/assign/view.php', array('id' => $cm->id, 'action'=>'grading'));
        $node = $navref->add(get_string('viewgrading', 'assign'), $link, navigation_node::TYPE_SETTING);

        $link = new moodle_url('/mod/assign/view.php', array('id' => $cm->id, 'action'=>'downloadall'));
        $node = $navref->add(get_string('downloadall', 'assign'), $link, navigation_node::TYPE_SETTING);
    }

    if (has_capability('mod/assign:revealidentities', $context)) {
        $dbparams = array('id'=>$cm->instance);
        $assignment = $DB->get_record('assign', $dbparams, 'blindmarking, revealidentities');

        if ($assignment && $assignment->blindmarking && !$assignment->revealidentities) {
            $urlparams = array('id' => $cm->id, 'action'=>'revealidentities');
            $url = new moodle_url('/mod/assign/view.php', $urlparams);
            $linkname = get_string('revealidentities', 'assign');
            $node = $navref->add($linkname, $url, navigation_node::TYPE_SETTING);
        }
    }
}

/**
 * Add a get_coursemodule_info function in case any assignment type wants to add 'extra' information
 * for the course (see resource).
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info An object on information that the courses
 *                        will know about (most noticeably, an icon).
 */
function assign_get_coursemodule_info($coursemodule) {
    global $CFG, $DB;

    $dbparams = array('id'=>$coursemodule->instance);
    $fields = 'id, name, alwaysshowdescription, allowsubmissionsfromdate, intro, introformat';
    if (! $assignment = $DB->get_record('assign', $dbparams, $fields)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $assignment->name;
    if ($coursemodule->showdescription) {
        if ($assignment->alwaysshowdescription || time() > $assignment->allowsubmissionsfromdate) {
            // Convert intro to html. Do not filter cached version, filters run at display time.
            $result->content = format_module_intro('assign', $assignment, $coursemodule->id, false);
        }
    }
    return $result;
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function assign_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $module_pagetype = array(
        'mod-assign-*' => get_string('page-mod-assign-x', 'assign'),
        'mod-assign-view' => get_string('page-mod-assign-view', 'assign'),
    );
    return $module_pagetype;
}

/**
 * Print an overview of all assignments
 * for the courses.
 *
 * @param mixed $courses The list of courses to print the overview for
 * @param array $htmlarray The array of html to return
 */
function assign_print_overview($courses, &$htmlarray) {
    global $USER, $CFG, $DB;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$assignments = get_all_instances_in_courses('assign', $courses)) {
        return;
    }

    $assignmentids = array();

    // Do assignment_base::isopen() here without loading the whole thing for speed.
    foreach ($assignments as $key => $assignment) {
        $time = time();
        $isopen = false;
        if ($assignment->duedate) {
            $duedate = false;
            if ($assignment->cutoffdate) {
                $duedate = $assignment->cutoffdate;
            }
            if ($duedate) {
                $isopen = ($assignment->allowsubmissionsfromdate <= $time && $time <= $duedate);
            } else {
                $isopen = ($assignment->allowsubmissionsfromdate <= $time);
            }
        }
        if ($isopen) {
            $assignmentids[] = $assignment->id;
        }
    }

    if (empty($assignmentids)) {
        // No assignments to look at - we're done.
        return true;
    }

    $strduedate = get_string('duedate', 'assign');
    $strcutoffdate = get_string('nosubmissionsacceptedafter', 'assign');
    $strnolatesubmissions = get_string('nolatesubmissions', 'assign');
    $strduedateno = get_string('duedateno', 'assign');
    $strduedateno = get_string('duedateno', 'assign');
    $strgraded = get_string('graded', 'assign');
    $strnotgradedyet = get_string('notgradedyet', 'assign');
    $strnotsubmittedyet = get_string('notsubmittedyet', 'assign');
    $strsubmitted = get_string('submitted', 'assign');
    $strassignment = get_string('modulename', 'assign');
    $strreviewed = get_string('reviewed', 'assign');

    // We do all possible database work here *outside* of the loop to ensure this scales.
    list($sqlassignmentids, $assignmentidparams) = $DB->get_in_or_equal($assignmentids);

    // Build up and array of unmarked submissions indexed by assignment id/ userid
    // for use where the user has grading rights on assignment.
    $rs = $DB->get_recordset_sql('SELECT
                                      s.assignment as assignment,
                                      s.userid as userid,
                                      s.id as id,
                                      s.status as status,
                                      g.timemodified as timegraded
                                  FROM {assign_submission} s
                                  LEFT JOIN {assign_grades} g ON
                                      s.userid = g.userid AND
                                      s.assignment = g.assignment
                                  WHERE
                                      g.timemodified = 0 OR
                                      s.timemodified > g.timemodified AND
                                      s.assignment ' . $sqlassignmentids, $assignmentidparams);

    $unmarkedsubmissions = array();
    foreach ($rs as $rd) {
        $unmarkedsubmissions[$rd->assignment][$rd->userid] = $rd->id;
    }
    $rs->close();

    // Get all user submissions, indexed by assignment id.
    $dbparams = array($USER->id, $USER->id);
    $mysubmissions = $DB->get_records_sql('SELECT
                                               a.id AS assignment,
                                               a.nosubmissions AS nosubmissions,
                                               g.timemodified AS timemarked,
                                               g.grader AS grader,
                                               g.grade AS grade,
                                               s.status AS status
                                           FROM {assign} a
                                           LEFT JOIN {assign_grades} g ON
                                               g.assignment = a.id AND
                                               g.userid = ?
                                           LEFT JOIN {assign_submission} s ON
                                               s.assignment = a.id AND
                                               s.userid = ?
                                           WHERE a.id ' . $sqlassignmentids, $dbparams);

    foreach ($assignments as $assignment) {
        // Do not show assignments that are not open.
        if (!in_array($assignment->id, $assignmentids)) {
            continue;
        }
        $dimmedclass = '';
        if (!$assignment->visible) {
            $dimmedclass = ' class="dimmed"';
        }
        $href = $CFG->wwwroot . '/mod/assign/view.php?id=' . $assignment->coursemodule;
        $str = '<div class="assign overview">' .
               '<div class="name">' .
               $strassignment . ': '.
               '<a ' . $dimmedclass .
                   'title="' . $strassignment . '" ' .
                   'href="' . $href . '">' .
               format_string($assignment->name) .
               '</a></div>';
        if ($assignment->duedate) {
            $userdate = userdate($assignment->duedate);
            $str .= '<div class="info">' . $strduedate . ': ' . $userdate . '</div>';
        } else {
            $str .= '<div class="info">' . $strduedateno . '</div>';
        }
        if ($assignment->cutoffdate) {
            if ($assignment->cutoffdate == $assignment->duedate) {
                $str .= '<div class="info">' . $strnolatesubmissions . '</div>';
            } else {
                $userdate = userdate($assignment->cutoffdate);
                $str .= '<div class="info">' . $strcutoffdate . ': ' . $userdate . '</div>';
            }
        }
        $context = context_module::instance($assignment->coursemodule);
        if (has_capability('mod/assign:grade', $context)) {
            // Count how many people can submit.
            $submissions = 0;
            if ($students = get_enrolled_users($context, 'mod/assign:view', 0, 'u.id')) {
                foreach ($students as $student) {
                    if (isset($unmarkedsubmissions[$assignment->id][$student->id])) {
                        $submissions++;
                    }
                }
            }

            if ($submissions) {
                $urlparams = array('id'=>$assignment->coursemodule, 'action'=>'grading');
                $url = new moodle_url('/mod/assign/view.php', $urlparams);
                $str .= '<div class="details">' .
                        '<a href="' . $url . '">' .
                        get_string('submissionsnotgraded', 'assign', $submissions) .
                        '</a></div>';
            }
        }
        if (has_capability('mod/assign:submit', $context)) {
            $str .= '<div class="details">';
            $str .= get_string('mysubmission', 'assign');
            $submission = $mysubmissions[$assignment->id];
            if ($submission->nosubmissions) {
                $str .= get_string('offline', 'assign');
            } else if (!$submission->status || $submission->status == 'draft') {
                $str .= $strnotsubmittedyet;
            } else {
                $str .= get_string('submissionstatus_' . $submission->status, 'assign');
            }
            if (!$submission->grade || $submission->grade < 0) {
                $str .= ', ' . get_string('notgraded', 'assign');
            } else {
                $str .= ', ' . get_string('graded', 'assign');
            }
            $str .= '</div>';
        }
        $str .= '</div>';
        if (empty($htmlarray[$assignment->course]['assign'])) {
            $htmlarray[$assignment->course]['assign'] = $str;
        } else {
            $htmlarray[$assignment->course]['assign'] .= $str;
        }
    }
}

/**
 * Print recent activity from all assignments in a given course
 *
 * This is used by the recent activity block
 * @param mixed $course the course to print activity for
 * @param bool $viewfullnames boolean to determine whether to show full names or not
 * @param int $timestart the time the rendering started
 */
function assign_print_recent_activity($course, $viewfullnames, $timestart) {
    global $CFG, $USER, $DB, $OUTPUT;

    // Do not use log table if possible, it may be huge.

    $dbparams = array($timestart, $course->id, 'assign');
    if (!$submissions = $DB->get_records_sql('SELECT asb.id, asb.timemodified, cm.id AS cmid, asb.userid,
                                                     u.firstname, u.lastname, u.email, u.picture
                                                FROM {assign_submission} asb
                                                     JOIN {assign} a      ON a.id = asb.assignment
                                                     JOIN {course_modules} cm ON cm.instance = a.id
                                                     JOIN {modules} md        ON md.id = cm.module
                                                     JOIN {user} u            ON u.id = asb.userid
                                               WHERE asb.timemodified > ? AND
                                                     a.course = ? AND
                                                     md.name = ?
                                            ORDER BY asb.timemodified ASC', $dbparams)) {
         return false;
    }

    $modinfo = get_fast_modinfo($course);
    $show    = array();
    $grader  = array();

    $showrecentsubmissions = get_config('mod_assign', 'showrecentsubmissions');

    foreach ($submissions as $submission) {
        if (!array_key_exists($submission->cmid, $modinfo->get_cms())) {
            continue;
        }
        $cm = $modinfo->get_cm($submission->cmid);
        if (!$cm->uservisible) {
            continue;
        }
        if ($submission->userid == $USER->id) {
            $show[] = $submission;
            continue;
        }

        $context = context_module::instance($submission->cmid);
        // The act of submitting of assignment may be considered private -
        // only graders will see it if specified.
        if (empty($showrecentsubmissions)) {
            if (!array_key_exists($cm->id, $grader)) {
                $grader[$cm->id] = has_capability('moodle/grade:viewall', $context);
            }
            if (!$grader[$cm->id]) {
                continue;
            }
        }

        $groupmode = groups_get_activity_groupmode($cm, $course);

        if ($groupmode == SEPARATEGROUPS &&
                !has_capability('moodle/site:accessallgroups',  $context)) {
            if (isguestuser()) {
                // Shortcut - guest user does not belong into any group.
                continue;
            }

            if (is_null($modinfo->get_groups())) {
                // Load all my groups and cache it in modinfo.
                $modinfo->groups = groups_get_user_groups($course->id);
            }

            // This will be slow - show only users that share group with me in this cm.
            if (empty($modinfo->groups[$cm->id])) {
                continue;
            }
            $usersgroups =  groups_get_all_groups($course->id, $submission->userid, $cm->groupingid);
            if (is_array($usersgroups)) {
                $usersgroups = array_keys($usersgroups);
                $intersect = array_intersect($usersgroups, $modinfo->groups[$cm->id]);
                if (empty($intersect)) {
                    continue;
                }
            }
        }
        $show[] = $submission;
    }

    if (empty($show)) {
        return false;
    }

    echo $OUTPUT->heading(get_string('newsubmissions', 'assign').':', 3);

    foreach ($show as $submission) {
        $cm = $modinfo->get_cm($submission->cmid);
        $link = $CFG->wwwroot.'/mod/assign/view.php?id='.$cm->id;
        print_recent_activity_note($submission->timemodified,
                                   $submission,
                                   $cm->name,
                                   $link,
                                   false,
                                   $viewfullnames);
    }

    return true;
}

/**
 * Returns all assignments since a given time.
 *
 * @param array $activities The activity information is returned in this array
 * @param int $index The current index in the activities array
 * @param int $timestart The earliest activity to show
 * @param int $courseid Limit the search to this course
 * @param int $cmid The course module id
 * @param int $userid Optional user id
 * @param int $groupid Optional group id
 * @return void
 */
function assign_get_recent_mod_activity(&$activities,
                                        &$index,
                                        $timestart,
                                        $courseid,
                                        $cmid,
                                        $userid=0,
                                        $groupid=0) {
    global $CFG, $COURSE, $USER, $DB;

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id'=>$courseid));
    }

    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->get_cm($cmid);
    $params = array();
    if ($userid) {
        $userselect = 'AND u.id = :userid';
        $params['userid'] = $userid;
    } else {
        $userselect = '';
    }

    if ($groupid) {
        $groupselect = 'AND gm.groupid = :groupid';
        $groupjoin   = 'JOIN {groups_members} gm ON  gm.userid=u.id';
        $params['groupid'] = $groupid;
    } else {
        $groupselect = '';
        $groupjoin   = '';
    }

    $params['cminstance'] = $cm->instance;
    $params['timestart'] = $timestart;

    $userfields = user_picture::fields('u', null, 'userid');

    if (!$submissions = $DB->get_records_sql('SELECT asb.id, asb.timemodified, ' .
                                                     $userfields .
                                             '  FROM {assign_submission} asb
                                                JOIN {assign} a ON a.id = asb.assignment
                                                JOIN {user} u ON u.id = asb.userid ' .
                                          $groupjoin .
                                            '  WHERE asb.timemodified > :timestart AND
                                                     a.id = :cminstance
                                                     ' . $userselect . ' ' . $groupselect .
                                            ' ORDER BY asb.timemodified ASC', $params)) {
         return;
    }

    $groupmode       = groups_get_activity_groupmode($cm, $course);
    $cm_context      = context_module::instance($cm->id);
    $grader          = has_capability('moodle/grade:viewall', $cm_context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cm_context);
    $viewfullnames   = has_capability('moodle/site:viewfullnames', $cm_context);

    if (is_null($modinfo->get_groups())) {
        // Load all my groups and cache it in modinfo.
        $modinfo->groups = groups_get_user_groups($course->id);
    }

    $showrecentsubmissions = get_config('mod_assign', 'showrecentsubmissions');
    $show = array();
    $usersgroups = groups_get_all_groups($course->id, $USER->id, $cm->groupingid);
    if (is_array($usersgroups)) {
        $usersgroups = array_keys($usersgroups);
    }
    foreach ($submissions as $submission) {
        if ($submission->userid == $USER->id) {
            $show[] = $submission;
            continue;
        }
        // The act of submitting of assignment may be considered private -
        // only graders will see it if specified.
        if (empty($showrecentsubmissions)) {
            if (!$grader) {
                continue;
            }
        }

        if ($groupmode == SEPARATEGROUPS and !$accessallgroups) {
            if (isguestuser()) {
                // Shortcut - guest user does not belong into any group.
                continue;
            }

            // This will be slow - show only users that share group with me in this cm.
            if (empty($modinfo->groups[$cm->id])) {
                continue;
            }
            if (is_array($usersgroups)) {
                $intersect = array_intersect($usersgroups, $modinfo->groups[$cm->id]);
                if (empty($intersect)) {
                    continue;
                }
            }
        }
        $show[] = $submission;
    }

    if (empty($show)) {
        return;
    }

    if ($grader) {
        require_once($CFG->libdir.'/gradelib.php');
        $userids = array();
        foreach ($show as $id => $submission) {
            $userids[] = $submission->userid;
        }
        $grades = grade_get_grades($courseid, 'mod', 'assign', $cm->instance, $userids);
    }

    $aname = format_string($cm->name, true);
    foreach ($show as $submission) {
        $activity = new stdClass();

        $activity->type         = 'assign';
        $activity->cmid         = $cm->id;
        $activity->name         = $aname;
        $activity->sectionnum   = $cm->sectionnum;
        $activity->timestamp    = $submission->timemodified;
        $activity->user         = new stdClass();
        if ($grader) {
            $activity->grade = $grades->items[0]->grades[$submission->userid]->str_long_grade;
        }

        $userfields = explode(',', user_picture::fields());
        foreach ($userfields as $userfield) {
            if ($userfield == 'id') {
                // Aliased in SQL above.
                $activity->user->{$userfield} = $submission->userid;
            } else {
                $activity->user->{$userfield} = $submission->{$userfield};
            }
        }
        $activity->user->fullname = fullname($submission, $viewfullnames);

        $activities[$index++] = $activity;
    }

    return;
}

/**
 * Print recent activity from all assignments in a given course
 *
 * This is used by course/recent.php
 * @param stdClass $activity
 * @param int $courseid
 * @param bool $detail
 * @param array $modnames
 */
function assign_print_recent_mod_activity($activity, $courseid, $detail, $modnames) {
    global $CFG, $OUTPUT;

    echo '<table border="0" cellpadding="3" cellspacing="0" class="assignment-recent">';

    echo '<tr><td class="userpicture" valign="top">';
    echo $OUTPUT->user_picture($activity->user);
    echo '</td><td>';

    if ($detail) {
        $modname = $modnames[$activity->type];
        echo '<div class="title">';
        echo '<img src="' . $OUTPUT->pix_url('icon', 'assign') . '" '.
             'class="icon" alt="' . $modname . '">';
        echo '<a href="' . $CFG->wwwroot . '/mod/assign/view.php?id=' . $activity->cmid . '">';
        echo $activity->name;
        echo '</a>';
        echo '</div>';
    }

    if (isset($activity->grade)) {
        echo '<div class="grade">';
        echo get_string('grade').': ';
        echo $activity->grade;
        echo '</div>';
    }

    echo '<div class="user">';
    echo "<a href=\"$CFG->wwwroot/user/view.php?id={$activity->user->id}&amp;course=$courseid\">";
    echo "{$activity->user->fullname}</a>  - " . userdate($activity->timestamp);
    echo '</div>';

    echo '</td></tr></table>';
}

/**
 * Checks if a scale is being used by an assignment.
 *
 * This is used by the backup code to decide whether to back up a scale
 * @param int $assignmentid
 * @param int $scaleid
 * @return boolean True if the scale is used by the assignment
 */
function assign_scale_used($assignmentid, $scaleid) {
    global $DB;

    $return = false;
    $rec = $DB->get_record('assign', array('id'=>$assignmentid, 'grade'=>-$scaleid));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of assignment
 *
 * This is used to find out if scale used anywhere
 * @param int $scaleid
 * @return boolean True if the scale is used by any assignment
 */
function assign_scale_used_anywhere($scaleid) {
    global $DB;

    if ($scaleid and $DB->record_exists('assign', array('grade'=>-$scaleid))) {
        return true;
    } else {
        return false;
    }
}

/**
 * List the actions that correspond to a view of this module.
 * This is used by the participation report.
 * @return array
 */
function assign_get_view_actions() {
    return array('view submission', 'view feedback');
}

/**
 * List the actions that correspond to a post of this module.
 * This is used by the participation report.
 * @return array
 */
function assign_get_post_actions() {
    return array('upload', 'submit', 'submit for grading');
}

/**
 * Call cron on the assign module.
 */
function assign_cron() {
    global $CFG;

    require_once($CFG->dirroot . '/mod/assign/locallib.php');
    assign::cron();

    $plugins = get_plugin_list('assignsubmission');

    foreach ($plugins as $name => $plugin) {
        $disabled = get_config('assignsubmission_' . $name, 'disabled');
        if (!$disabled) {
            $class = 'assign_submission_' . $name;
            require_once($CFG->dirroot . '/mod/assign/submission/' . $name . '/locallib.php');
            $class::cron();
        }
    }
    $plugins = get_plugin_list('assignfeedback');

    foreach ($plugins as $name => $plugin) {
        $disabled = get_config('assignfeedback_' . $name, 'disabled');
        if (!$disabled) {
            $class = 'assign_feedback_' . $name;
            require_once($CFG->dirroot . '/mod/assign/feedback/' . $name . '/locallib.php');
            $class::cron();
        }
    }
}

/**
 * Returns all other capabilities used by this module.
 * @return array Array of capability strings
 */
function assign_get_extra_capabilities() {
    return array('gradereport/grader:view',
                 'moodle/grade:viewall',
                 'moodle/site:viewfullnames',
                 'moodle/site:config');
}

/**
 * Create grade item for given assignment.
 *
 * @param stdClass $assign record with extra cmidnumber
 * @param array $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function assign_grade_item_update($assign, $grades=null) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    if (!isset($assign->courseid)) {
        $assign->courseid = $assign->course;
    }

    $params = array('itemname'=>$assign->name, 'idnumber'=>$assign->cmidnumber);

    if ($assign->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $assign->grade;
        $params['grademin']  = 0;

    } else if ($assign->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$assign->grade;

    } else {
        // Allow text comments only.
        $params['gradetype'] = GRADE_TYPE_TEXT;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/assign',
                        $assign->courseid,
                        'mod',
                        'assign',
                        $assign->id,
                        0,
                        $grades,
                        $params);
}

/**
 * Return grade for given user or all users.
 *
 * @param stdClass $assign record of assign with an additional cmidnumber
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function assign_get_user_grades($assign, $userid=0) {
    global $CFG;

    require_once($CFG->dirroot . '/mod/assign/locallib.php');

    $cm = get_coursemodule_from_instance('assign', $assign->id, 0, false, MUST_EXIST);
    $context = context_module::instance($cm->id);
    $assignment = new assign($context, null, null);
    $assignment->set_instance($assign);
    return $assignment->get_user_grades_for_gradebook($userid);
}

/**
 * Update activity grades.
 *
 * @param stdClass $assign database record
 * @param int $userid specific user only, 0 means all
 * @param bool $nullifnone - not used
 */
function assign_update_grades($assign, $userid=0, $nullifnone=true) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    if ($assign->grade == 0) {
        assign_grade_item_update($assign);

    } else if ($grades = assign_get_user_grades($assign, $userid)) {
        foreach ($grades as $k => $v) {
            if ($v->rawgrade == -1) {
                $grades[$k]->rawgrade = null;
            }
        }
        assign_grade_item_update($assign, $grades);

    } else {
        assign_grade_item_update($assign);
    }
}

/**
 * List the file areas that can be browsed.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array
 */
function assign_get_file_areas($course, $cm, $context) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/assign/locallib.php');
    $areas = array();

    $assignment = new assign($context, $cm, $course);
    foreach ($assignment->get_submission_plugins() as $plugin) {
        if ($plugin->is_visible()) {
            $pluginareas = $plugin->get_file_areas();

            if ($pluginareas) {
                $areas = array_merge($areas, $pluginareas);
            }
        }
    }
    foreach ($assignment->get_feedback_plugins() as $plugin) {
        if ($plugin->is_visible()) {
            $pluginareas = $plugin->get_file_areas();

            if ($pluginareas) {
                $areas = array_merge($areas, $pluginareas);
            }
        }
    }

    return $areas;
}

/**
 * File browsing support for assign module.
 *
 * @param file_browser $browser
 * @param object $areas
 * @param object $course
 * @param object $cm
 * @param object $context
 * @param string $filearea
 * @param int $itemid
 * @param string $filepath
 * @param string $filename
 * @return object file_info instance or null if not found
 */
function assign_get_file_info($browser,
                              $areas,
                              $course,
                              $cm,
                              $context,
                              $filearea,
                              $itemid,
                              $filepath,
                              $filename) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/assign/locallib.php');

    if ($context->contextlevel != CONTEXT_MODULE) {
        return null;
    }

    $fs = get_file_storage();
    $filepath = is_null($filepath) ? '/' : $filepath;
    $filename = is_null($filename) ? '.' : $filename;

    // Need to find the plugin this belongs to.
    $assignment = new assign($context, $cm, $course);
    $pluginowner = null;
    foreach ($assignment->get_submission_plugins() as $plugin) {
        if ($plugin->is_visible()) {
            $pluginareas = $plugin->get_file_areas();

            if (array_key_exists($filearea, $pluginareas)) {
                $pluginowner = $plugin;
                break;
            }
        }
    }
    if (!$pluginowner) {
        foreach ($assignment->get_feedback_plugins() as $plugin) {
            if ($plugin->is_visible()) {
                $pluginareas = $plugin->get_file_areas();

                if (array_key_exists($filearea, $pluginareas)) {
                    $pluginowner = $plugin;
                    break;
                }
            }
        }
    }

    if (!$pluginowner) {
        return null;
    }

    $result = $pluginowner->get_file_info($browser, $filearea, $itemid, $filepath, $filename);
    return $result;
}

/**
 * Prints the complete info about a user's interaction with an assignment.
 *
 * @param stdClass $course
 * @param stdClass $user
 * @param stdClass $coursemodule
 * @param stdClass $assign the database assign record
 *
 * This prints the submission summary and feedback summary for this student.
 */
function assign_user_complete($course, $user, $coursemodule, $assign) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/assign/locallib.php');

    $context = context_module::instance($coursemodule->id);

    $assignment = new assign($context, $coursemodule, $course);

    echo $assignment->view_student_summary($user, false);
}

/**
 * Print the grade information for the assignment for this user.
 *
 * @param stdClass $course
 * @param stdClass $user
 * @param stdClass $coursemodule
 * @param stdClass $assignment
 */
function assign_user_outline($course, $user, $coursemodule, $assignment) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');
    require_once($CFG->dirroot.'/grade/grading/lib.php');

    $gradinginfo = grade_get_grades($course->id,
                                        'mod',
                                        'assign',
                                        $assignment->id,
                                        $user->id);

    $gradingitem = $gradinginfo->items[0];
    $gradebookgrade = $gradingitem->grades[$user->id];

    if (!$gradebookgrade) {
        return null;
    }
    $result = new stdClass();
    $result->info = get_string('outlinegrade', 'assign', $gradebookgrade->grade);
    $result->time = $gradebookgrade->dategraded;

    return $result;
}

/**
 * Obtains the automatic completion state for this module based on any conditions
 * in assign settings.
 *
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not, $type if conditions not set.
 */
function assign_get_completion_state($course, $cm, $userid, $type) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/assign/locallib.php');

    $assign = new assign(null, $cm, $course);

    // If completion option is enabled, evaluate it and return true/false.
    if ($assign->get_instance()->completionsubmit) {
        $dbparams = array('assignment'=>$assign->get_instance()->id, 'userid'=>$userid);
        $submission = $DB->get_record('assign_submission', $dbparams, '*', IGNORE_MISSING);
        return $submission && $submission->status == ASSIGN_SUBMISSION_STATUS_SUBMITTED;
    } else {
        // Completion option is not enabled so just return $type.
        return $type;
    }
}
