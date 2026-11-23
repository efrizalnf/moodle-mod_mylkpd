<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_mylkpd\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Tests for the privacy provider, in particular people named inside group submissions.
 *
 * @package    mod_mylkpd
 * @category   test
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_mylkpd\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Group work where Ani submitted and wrote one answer, and Budi wrote another and also submitted.
     *
     * @return array [course, context, instance, group, ani, budi, submission]
     */
    protected function create_group_work(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $ani = $generator->create_and_enrol($course, 'student');
        $budi = $generator->create_and_enrol($course, 'student');
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $ani->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $budi->id]);
        $instance = $generator->create_module('mylkpd', ['course' => $course->id, 'teamsubmission' => 1, 'teamsubmitmode' => 1]);
        $context = \context_module::instance($instance->cmid);

        /** @var \mod_mylkpd_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_mylkpd');
        $submission = $plugingenerator->create_submission([
            'mylkpdid' => $instance->id,
            'groupid' => $group->id,
            'userid' => $ani->id,
            'answers' => [
                'items' => [
                    'a1' => ['type' => 'text', 'page' => 1, 'x' => 10, 'y' => 10, 'text' => 'By Ani', 'by' => (int) $ani->id],
                    'b1' => ['type' => 'text', 'page' => 1, 'x' => 20, 'y' => 20, 'text' => 'By Budi', 'by' => (int) $budi->id],
                ],
                'blocks' => ['3' => ['html' => 'Answer by Budi', 'by' => (int) $budi->id]],
            ],
            'submittedby' => [$ani->id => 1000, $budi->id => 1001],
        ]);
        return [$course, $context, $instance, $group, $ani, $budi, $submission];
    }

    /**
     * A member who left the group is still found through the answers and submissions naming them.
     */
    public function test_former_member_is_discoverable(): void {
        [, $context, , $group, , $budi] = $this->create_group_work();
        groups_remove_member($group->id, $budi->id);

        $this->assertContainsEquals($context->id, provider::get_contexts_for_userid($budi->id)->get_contextids());

        $userlist = new userlist($context, 'mod_mylkpd');
        provider::get_users_in_context($userlist);
        $this->assertContainsEquals($budi->id, $userlist->get_userids());
    }

    /**
     * The export of a former member marks their own answers and contains no other member's id.
     */
    public function test_export_for_former_member(): void {
        [, $context, , $group, $ani, $budi, $submission] = $this->create_group_work();
        groups_remove_member($group->id, $budi->id);

        $this->export_context_data_for_user($budi->id, $context, 'mod_mylkpd');
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('submissions', 'mod_mylkpd'), $submission->id]);

        $this->assertEquals(transform::yesno(true), $data->answers->items->b1->yours);
        $this->assertEquals(transform::yesno(false), $data->answers->items->a1->yours);
        $this->assertObjectNotHasProperty('by', $data->answers->items->a1);
        $this->assertStringNotContainsString('"by"', json_encode($data->answers));
        $this->assertNotEmpty($data->yousubmitted);
        $this->assertStringNotContainsString((string) $ani->id . '":', json_encode($data));
    }

    /**
     * Deleting one member's data anonymises them in the group submission and keeps the group's work.
     */
    public function test_delete_data_for_user_anonymises_group_submission(): void {
        global $DB;
        [, $context, , $group, $ani, $budi, $submission] = $this->create_group_work();
        groups_remove_member($group->id, $budi->id);

        provider::delete_data_for_user(new approved_contextlist($budi, 'mod_mylkpd', [$context->id]));

        $record = $DB->get_record('mylkpd_submissions', ['id' => $submission->id], '*', MUST_EXIST);
        $answers = json_decode($record->answers, true);
        $this->assertEquals(0, $answers['items']['b1']['by']);
        $this->assertEquals(0, $answers['blocks']['3']['by']);
        $this->assertEquals($ani->id, $answers['items']['a1']['by']);
        $this->assertEquals('By Budi', $answers['items']['b1']['text']);
        $this->assertEquals([$ani->id => 1000], json_decode($record->submittedby, true));
        $this->assertEquals($ani->id, $record->userid);
        $this->assertNotContainsEquals($context->id, provider::get_contexts_for_userid($budi->id)->get_contextids());
    }

    /**
     * Deleting several users from a context also clears the member who submitted.
     */
    public function test_delete_data_for_users_clears_submitter(): void {
        global $DB;
        [, $context, , , $ani, $budi, $submission] = $this->create_group_work();

        provider::delete_data_for_users(new approved_userlist($context, 'mod_mylkpd', [$ani->id, $budi->id]));

        $record = $DB->get_record('mylkpd_submissions', ['id' => $submission->id], '*', MUST_EXIST);
        $this->assertEquals(0, $record->userid);
        $this->assertNull($record->submittedby);
        $this->assertStringNotContainsString('"by":' . $ani->id, $record->answers);
        $this->assertStringNotContainsString('"by":' . $budi->id, $record->answers);
    }

    /**
     * Individual work is deleted entirely.
     */
    public function test_delete_individual_submission(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $instance = $generator->create_module('mylkpd', ['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);
        $generator->get_plugin_generator('mod_mylkpd')->create_submission(['mylkpdid' => $instance->id,
            'userid' => $student->id]);

        provider::delete_data_for_user(new approved_contextlist($student, 'mod_mylkpd', [$context->id]));

        $this->assertFalse($DB->record_exists('mylkpd_submissions', ['mylkpdid' => $instance->id]));
    }
}
