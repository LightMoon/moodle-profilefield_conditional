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

namespace profilefield_conditional;

use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Tests for the server-side validation of the conditional profile field.
 *
 * @package    profilefield_conditional
 * @copyright  2014 Shamim Rezaie {@link http://foodle.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \profile_field_conditional
 */
final class field_test extends \advanced_testcase {
    /** @var string Name of the form input of the conditional (controller) field. */
    private const CONTROLLER = 'profile_field_profession_adc_test';
    /** @var string Name of the form input of the first dependent field, a menu. */
    private const STAGE = 'profile_field_adc_exam_stage';
    /** @var string Name of the form input of the second dependent field, a text field. */
    private const VALIDITY = 'profile_field_written_validity_status';

    /**
     * Creates the profession field and its two dependent fields, plus an unrelated unique field.
     *
     * @param array $dependentsettings Additional settings for both dependent fields.
     */
    private function create_fields(array $dependentsettings = []): void {
        $generator = $this->getDataGenerator();
        $generator->create_custom_profile_field([
            'datatype' => 'menu',
            'shortname' => 'adc_exam_stage',
            'name' => 'ADC exam stage',
            'param1' => "0\n1\n2",
            'signup' => 1,
        ] + $dependentsettings);
        $generator->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'written_validity_status',
            'name' => 'Written validity status',
            'signup' => 1,
        ] + $dependentsettings);
        $generator->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'plain',
            'name' => 'Plain field',
            'signup' => 1,
            'forceunique' => 1,
        ]);

        $dependents = ['adc_exam_stage', 'written_validity_status'];
        $generator->create_custom_profile_field([
            'datatype' => 'conditional',
            'shortname' => 'profession_adc_test',
            'name' => 'Profession',
            'param1' => "Dentist\nGeneral Practitioner\nOthers",
            'param4' => 0,
            'param5' => json_encode([
                ['option' => 'Dentist', 'requiredfields' => $dependents],
                ['option' => 'General Practitioner', 'hiddenfields' => $dependents],
                ['option' => 'Others', 'hiddenfields' => $dependents],
            ]),
            'signup' => 1,
        ]);
    }

    /**
     * Builds the user object that is passed to the validation, like the signup and edit forms do.
     *
     * @param int $userid 0 for the signup form.
     * @param array $profiledata Submitted profile field values. A missing key means the field was not submitted.
     * @return stdClass
     */
    private function get_usernew(int $userid, array $profiledata): stdClass {
        return (object) ([
            'id' => $userid,
            'username' => 'testuser',
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'testuser@example.com',
        ] + $profiledata);
    }

    /**
     * Runs the validation of all the profile fields, as the signup and the profile forms do.
     *
     * @param stdClass $usernew
     * @return array
     */
    private function validate(stdClass $usernew): array {
        return profile_validation($usernew, []);
    }

    /**
     * Sets the current user and returns the id the validated object has in that context.
     *
     * @param bool $loggedin False for the signup form, true for the profile edit form.
     * @return int
     */
    private function prepare_context(bool $loggedin): int {
        $this->resetAfterTest();
        if ($loggedin) {
            $user = $this->getDataGenerator()->create_user();
            $this->setUser($user);
            return (int) $user->id;
        }
        $this->setUser(0);
        return 0;
    }

    /**
     * The contexts in which the validation must work.
     *
     * @return array
     */
    public static function context_provider(): array {
        return [
            'signup' => [false],
            'edit profile' => [true],
        ];
    }

    /**
     * @dataProvider context_provider
     * @param bool $loggedin
     */
    public function test_required_fields_valid(bool $loggedin): void {
        $this->create_fields();
        $userid = $this->prepare_context($loggedin);

        $usernew = $this->get_usernew($userid, [self::CONTROLLER => 'Dentist', self::STAGE => '1', self::VALIDITY => 'Valid']);
        $this->assertSame([], $this->validate($usernew));
    }

    /**
     * @dataProvider context_provider
     * @param bool $loggedin
     */
    public function test_blank_fields_are_rejected(bool $loggedin): void {
        $this->create_fields();
        $userid = $this->prepare_context($loggedin);

        $usernew = $this->get_usernew($userid, [self::CONTROLLER => 'Dentist', self::STAGE => '1', self::VALIDITY => '']);
        $this->assertSame([self::VALIDITY], array_keys($this->validate($usernew)));

        $usernew = $this->get_usernew($userid, [self::CONTROLLER => 'Dentist', self::STAGE => '', self::VALIDITY => 'Valid']);
        $this->assertSame([self::STAGE], array_keys($this->validate($usernew)));
    }

    /**
     * @dataProvider context_provider
     * @param bool $loggedin
     */
    public function test_omitted_fields_are_rejected(bool $loggedin): void {
        $this->create_fields();
        $userid = $this->prepare_context($loggedin);

        // Both keys missing.
        $errors = $this->validate($this->get_usernew($userid, [self::CONTROLLER => 'Dentist']));
        $this->assertEqualsCanonicalizing([self::STAGE, self::VALIDITY], array_keys($errors));

        // One key missing.
        $usernew = $this->get_usernew($userid, [self::CONTROLLER => 'Dentist', self::STAGE => '2']);
        $this->assertSame([self::VALIDITY], array_keys($this->validate($usernew)));
        $usernew = $this->get_usernew($userid, [self::CONTROLLER => 'Dentist', self::VALIDITY => 'Valid']);
        $this->assertSame([self::STAGE], array_keys($this->validate($usernew)));

        // Null counts as missing.
        $usernew = $this->get_usernew($userid, [self::CONTROLLER => 'Dentist', self::STAGE => null, self::VALIDITY => null]);
        $this->assertEqualsCanonicalizing([self::STAGE, self::VALIDITY], array_keys($this->validate($usernew)));
    }

    /**
     * @dataProvider context_provider
     * @param bool $loggedin
     */
    public function test_whitespace_only_is_rejected(bool $loggedin): void {
        $this->create_fields();
        $userid = $this->prepare_context($loggedin);

        $usernew = $this->get_usernew($userid, [self::CONTROLLER => 'Dentist', self::STAGE => ' ', self::VALIDITY => " \t\n"]);
        $this->assertEqualsCanonicalizing([self::STAGE, self::VALIDITY], array_keys($this->validate($usernew)));
    }

    /**
     * Editor-like values are submitted as an array with a "text" key.
     */
    public function test_array_value_with_text_key(): void {
        $this->create_fields();
        $this->prepare_context(false);

        $usernew = $this->get_usernew(0, [
            self::CONTROLLER => 'Dentist',
            self::STAGE => '1',
            self::VALIDITY => ['text' => '  ', 'format' => FORMAT_HTML],
        ]);
        $this->assertSame([self::VALIDITY], array_keys($this->validate($usernew)));

        $usernew->{self::VALIDITY} = ['text' => 'Valid', 'format' => FORMAT_HTML];
        $this->assertSame([], $this->validate($usernew));
    }

    /**
     * @dataProvider context_provider
     * @param bool $loggedin
     */
    public function test_zero_is_a_valid_value(bool $loggedin): void {
        $this->create_fields();
        $userid = $this->prepare_context($loggedin);

        // "0" is both a valid menu option and a valid text value.
        $usernew = $this->get_usernew($userid, [self::CONTROLLER => 'Dentist', self::STAGE => '0', self::VALIDITY => '0']);
        $this->assertSame([], $this->validate($usernew));
    }

    /**
     * @dataProvider context_provider
     * @param bool $loggedin
     */
    public function test_value_must_be_a_menu_option(bool $loggedin): void {
        $this->create_fields();
        $userid = $this->prepare_context($loggedin);

        $usernew = $this->get_usernew($userid, [self::CONTROLLER => 'Dentist', self::STAGE => '99', self::VALIDITY => 'Valid']);
        $this->assertSame([self::STAGE], array_keys($this->validate($usernew)));
    }

    /**
     * @dataProvider context_provider
     * @param bool $loggedin
     */
    public function test_hidden_fields_remain_optional(bool $loggedin): void {
        $this->create_fields();
        $userid = $this->prepare_context($loggedin);

        foreach (['General Practitioner', 'Others'] as $profession) {
            // Omitted or blank: neither is a conditional validation error.
            $this->assertSame([], $this->validate($this->get_usernew($userid, [self::CONTROLLER => $profession])));
            $usernew = $this->get_usernew($userid, [self::CONTROLLER => $profession, self::STAGE => '', self::VALIDITY => '']);
            $this->assertSame([], $this->validate($usernew));
        }
    }

    /**
     * The conditional field not having a (valid) value does not require anything.
     */
    public function test_no_condition_selected(): void {
        $this->create_fields();
        $this->prepare_context(false);

        $this->assertSame([], $this->validate($this->get_usernew(0, [])));
        $this->assertSame([], $this->validate($this->get_usernew(0, [self::CONTROLLER => ''])));
        $this->assertSame([], $this->validate($this->get_usernew(0, [self::CONTROLLER => 'Unknown'])));
        $this->assertSame([], $this->validate($this->get_usernew(0, [self::CONTROLLER => ['Dentist']])));
    }

    /**
     * A field that is also hidden for the selected option is never required.
     */
    public function test_field_hidden_and_required_is_optional(): void {
        global $DB;

        $this->create_fields();
        $this->prepare_context(false);

        $record = $DB->get_record('user_info_field', ['shortname' => 'profession_adc_test'], '*', MUST_EXIST);
        $conditions = json_decode($record->param5);
        $conditions[0]->hiddenfields = ['adc_exam_stage'];
        $DB->set_field('user_info_field', 'param5', json_encode($conditions), ['id' => $record->id]);

        $errors = $this->validate($this->get_usernew(0, [self::CONTROLLER => 'Dentist']));
        $this->assertSame([self::VALIDITY], array_keys($errors));
    }

    /**
     * Fields that are not on the signup form cannot be filled in, so the error is reported on the conditional field.
     */
    public function test_field_not_on_signup_form_is_reported_on_the_controller(): void {
        global $DB;

        $this->create_fields();
        $DB->set_field('user_info_field', 'signup', 0, ['shortname' => 'written_validity_status']);
        $this->prepare_context(false);

        $errors = $this->validate($this->get_usernew(0, [self::CONTROLLER => 'Dentist']));
        $this->assertEqualsCanonicalizing([self::STAGE, self::CONTROLLER], array_keys($errors));
        $this->assertStringContainsString('Written validity status', $errors[self::CONTROLLER]);

        $usernew = $this->get_usernew(0, [self::CONTROLLER => 'Dentist', self::STAGE => '1', self::VALIDITY => 'Valid']);
        $this->assertSame([], $this->validate($usernew));
    }

    /**
     * A dependent field that no longer exists cannot be validated.
     */
    public function test_missing_dependent_field_is_ignored(): void {
        global $DB;

        $this->create_fields();
        $DB->delete_records('user_info_field', ['shortname' => 'written_validity_status']);
        $this->prepare_context(false);

        $errors = $this->validate($this->get_usernew(0, [self::CONTROLLER => 'Dentist']));
        $this->assertSame([self::STAGE], array_keys($errors));
    }

    /**
     * Locked fields are frozen for users, so they keep their stored value and only those who can change them need to fill them.
     */
    public function test_locked_field_on_edit_form(): void {
        $this->resetAfterTest();
        $this->create_fields(['locked' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $omitted = $this->get_usernew((int) $user->id, [self::CONTROLLER => 'Dentist']);

        // Without a stored value, the error is reported on the conditional field as the user cannot edit the others.
        $this->setUser($user);
        $this->assertSame([self::CONTROLLER], array_keys($this->validate($omitted)));

        // The stored values are enough when the frozen elements are not submitted.
        profile_save_data($this->get_usernew((int) $user->id, [self::STAGE => '2', self::VALIDITY => 'Valid']));
        $this->assertSame([], $this->validate($omitted));

        // The administrators can edit the fields, so a missing value is reported there.
        $this->setAdminUser();
        $other = $this->getDataGenerator()->create_user();
        $omitted = $this->get_usernew((int) $other->id, [self::CONTROLLER => 'Dentist']);
        $this->assertEqualsCanonicalizing([self::STAGE, self::VALIDITY], array_keys($this->validate($omitted)));
    }

    /**
     * An object that cannot be a complete form submission is not validated.
     */
    public function test_incomplete_user_object(): void {
        $this->create_fields();
        $this->prepare_context(false);

        $usernew = (object) ['id' => 0, self::CONTROLLER => 'Dentist'];
        $this->assertSame([], $this->validate($usernew));
    }

    /**
     * Errors of the other fields are reported next to the conditional ones.
     */
    public function test_profile_validation_integration(): void {
        $this->create_fields();
        $userid = $this->prepare_context(true);
        $other = $this->getDataGenerator()->create_user();
        profile_save_data((object) ['id' => $other->id, 'profile_field_plain' => 'taken']);

        $usernew = $this->get_usernew($userid, [self::CONTROLLER => 'Dentist', 'profile_field_plain' => 'taken']);
        $errors = $this->validate($usernew);
        $this->assertEqualsCanonicalizing(['profile_field_plain', self::STAGE, self::VALIDITY], array_keys($errors));
        $this->assertStringContainsString('Profession', $errors[self::STAGE]);
        $this->assertStringContainsString('Dentist', $errors[self::STAGE]);
    }

    /**
     * Fields without any condition keep their behaviour: omitting them is fine, duplicates are still caught.
     */
    public function test_regular_fields_are_unaffected(): void {
        $this->create_fields();
        $userid = $this->prepare_context(true);
        $other = $this->getDataGenerator()->create_user();
        profile_save_data((object) ['id' => $other->id, 'profile_field_plain' => 'taken']);

        $this->assertSame([], $this->validate($this->get_usernew($userid, [self::CONTROLLER => 'Others'])));
        $this->assertSame([], $this->validate($this->get_usernew($userid, [
            self::CONTROLLER => 'Others',
            'profile_field_plain' => 'unique',
        ])));
        $errors = $this->validate($this->get_usernew($userid, [
            self::CONTROLLER => 'Others',
            'profile_field_plain' => 'taken',
        ]));
        $this->assertSame(['profile_field_plain'], array_keys($errors));
    }
}
