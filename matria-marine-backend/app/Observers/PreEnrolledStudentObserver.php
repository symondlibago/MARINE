<?php

namespace App\Observers;

use App\Models\PreEnrolledStudent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class PreEnrolledStudentObserver
{
    /**
     * Handle the PreEnrolledStudent "updated" event.
     */
    public function updated(PreEnrolledStudent $student): void
    {
        // Check if the 'enrollment_status' was changed to 'enrolled' AND if a user account doesn't already exist.
        if ($student->wasChanged('enrollment_status') && $student->enrollment_status === 'enrolled' && is_null($student->user_id)) {

            try {
                // 1. Prepare user data from the student model
                $fullName = $student->first_name . ' ' . ($student->middle_name ? $student->middle_name[0] . '. ' : '') . $student->last_name;

                // Sanitize last name for the password (lowercase, no spaces)
                $passwordPart = strtolower(str_replace(' ', '', $student->last_name));
                $rawPassword = $passwordPart . '@' . $student->student_id_number;

                // 2. Create the new user
                $newUser = User::create([
                    'name' => $fullName,
                    'email' => $student->email_address,
                    'password' => Hash::make($rawPassword),
                    'role' => 'Student', // Assign the 'Student' role
                ]);

                // 3. Link the new user back to the student record
                // We use 'saveQuietly' to prevent triggering the 'updated' event again and causing an infinite loop.
                $student->user_id = $newUser->id;
                $student->saveQuietly();

            } catch (\Exception $e) {
                // Log any errors to help with debugging
                Log::error('Failed to create user for student ID: ' . $student->id . '. Error: ' . $e->getMessage());
            }
        }
    }
}