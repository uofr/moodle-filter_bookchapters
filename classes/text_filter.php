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
 * Automatically links section names in a Moodle course and its activities
 *
 * This filter provides automatic linking to sections when its name (title)
 * is found inside every Moodle text
 *
 * @package    filter_bookchapters
 * @copyright  2017 Matt Davidson
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_bookchapters;

defined('MOODLE_INTERNAL') || die();

/**
 * Book chapter filtering.
 *
 * @package    filter_bookchapters
 * @copyright  2017 Matt Davidson
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    // Trivial-cache - keyed on $cachedcourseid and $cacheduserid.
    /** @var array section list. */
    public static $chapterlist = null;

    /** @var int course id. */
    public static $cachedcourseid;

    /** @var int userid. */
    public static $cacheduserid;

    /**
     * Given an object containing all the necessary data,
     * (defined by the form in mod_form.php) this function
     * will update an existing instance with new data.
     *
     * @param string $text The text that will be filtered.
     * @param array $options The standard filter options passed.
     * @return string Filtered text.
     */
    public function filter($text, array $options = array()) {
        global $USER, $DB; // Since 2.7 we can finally start using globals in filters.

        $coursectx = $this->context->get_course_context(false);
        if (!$coursectx) {
            return $text;
        }
        $courseid = $coursectx->instanceid;

        // Initialise/invalidate our trivial cache if dealing with a different course.
        if (!isset(self::$cachedcourseid) || self::$cachedcourseid !== (int)$courseid) {
            self::$chapterlist = null;
        }
        self::$cachedcourseid = (int)$courseid;
        // And the same for user id.
        if (!isset(self::$cacheduserid) || self::$cacheduserid !== (int)$USER->id) {
            self::$chapterlist = null;
        }
        self::$cacheduserid = (int)$USER->id;

        // It may be cached.

        if (is_null(self::$chapterlist)) {
            self::$chapterlist = array();

            $modinfo = get_fast_modinfo($courseid);
            self::$chapterlist = array(); // We will store all the created filters here.

            // Create array of chapters sorted by the name length (we are only interested in properties name and url).
            $sortedchapters = array();

            foreach ($modinfo->cms as $cm) {
                // Use normal access control and visibility, but exclude labels and hidden activities.
                if ($cm->modname == "book" && ($cm->visible and $cm->has_view() and $cm->uservisible)) {
                    if ($chapters = $DB->get_records('book_chapters', array('bookid' => $cm->instance))) {
                        foreach ($chapters as $chapter) {
                            if (!$chapter->hidden) { // Do not link if chapter is hidden.
                                $sortedchapters[] = (object)array(
                                    'name' => $chapter->title,
                                    'url' => $cm->url . '&chapterid=' . $chapter->id,
                                    'id' => $chapter->id,
                                    'namelen' => -strlen($chapter->title), // Negative value for reverse sorting.
                                );
                            }
                        }
                    }
                }
            }

            // Sort activities by the length of the section name in reverse order.
            \core_collator::asort_objects_by_property($sortedchapters, 'namelen', \core_collator::SORT_NUMERIC);

            foreach ($sortedchapters as $chapter) {
                $title = s(trim(strip_tags($chapter->name)));
                $currentname = trim($chapter->name);
                $entname  = s($currentname);
                // Avoid empty or unlinkable activity names.
                if (!empty($title)) {
                    $hrefopen = \html_writer::start_tag('a',
                            array('class' => 'autolink', 'title' => $title,
                                'href' => $chapter->url));
                    self::$chapterlist[$chapter->id] = new \filterobject($currentname, $hrefopen, '</a>', false, true);
                    if ($currentname != $entname) {
                        // If name has some entity (&amp; &quot; &lt; &gt;) add that filter too. MDL-17545.
                        self::$chapterlist[$chapter->id.'-e'] = new \filterobject($entname, $hrefopen, '</a>', false, true);
                    }
                }
            }
        }

        $filterslist = array();
        if (self::$chapterlist) {
            $chapterid = $this->context->instanceid;
            if ($this->context->contextlevel == CONTEXT_MODULE && isset(self::$chapterlist[$chapterid])) {
                // Remove filterobjects for the current module.
                $filterslist = array_values(array_diff_key(self::$chapterlist, array($chapterid => 1, $chapterid.'-e' => 1)));
            } else {
                $filterslist = array_values(self::$chapterlist);
            }
        }

        if ($filterslist) {
            return $text = filter_phrases($text, $filterslist);
        } else {
            return $text;
        }
    }
}
